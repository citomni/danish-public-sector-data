<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\DanishPublicSectorData\Support;

use CitOmni\DanishPublicSectorData\Enum\DatafordelerAuthentication;
use CitOmni\DanishPublicSectorData\Exception\AuthenticationException;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
use CitOmni\Infrastructure\Exception\CurlException;
use CitOmni\Kernel\App;

/**
 * DatafordelerClient: Support class that sends authenticated GraphQL requests to Datafordeler.
 *
 * Gives the package's services one way to reach Datafordeler: It authenticates every request,
 * sends it through the curl service, and turns missing credentials, failed transfers, error
 * answers and answers it cannot use into package exceptions. Which credentials a service needs,
 * and what it asks for, belong to the service that creates the client.
 *
 * Rules:
 * - Read the credentials from the secrets service, and only when a request needs them.
 * - Mark the API key as a sensitive query key, so the curl service redacts it from every URL it
 *   exposes.
 * - Send the client secret only in the body of the token request, and an access token only in the
 *   Authorization header.
 */
class DatafordelerClient {

	/** Secret that holds the API key of the IT-system. */
	private const string API_KEY_SECRET = 'datafordeler.api_key';

	/** Secret that holds the client id of the IT-system's Shared Secret. */
	private const string CLIENT_ID_SECRET = 'datafordeler.oauth_client_id';

	/** Secret that holds the client secret of the IT-system's Shared Secret. */
	private const string CLIENT_SECRET_SECRET = 'datafordeler.oauth_client_secret';

	/** Lifetime of an access token in seconds when the token answer gives none. */
	private const int DEFAULT_TOKEN_LIFETIME = 3600; // Datafordeler documents 60 minutes.

	/** Seconds before its expiry from which an access token is no longer used. */
	private const int TOKEN_RENEWAL_MARGIN = 60;

	/** @var array<string,array{token:string,expiresAt:int}> Access tokens of the process, keyed by token endpoint and client id; expiresAt is a Unix time. */
	private static array $accessTokens = [];

	/** App whose cfg, curl service and secrets service the client uses. */
	private App $app;

	/** Base URL of the GraphQL services, without a trailing slash. */
	private string $baseUrl;

	/** Request timeout in seconds. */
	private int|float $timeout;

	/** Connect timeout in seconds. */
	private int|float $connectTimeout;

	/** How the client authenticates. */
	private DatafordelerAuthentication $authentication;

	/** OAuth token endpoint; empty for an API-key client. */
	private string $tokenUrl = '';

	/** Key in $accessTokens of the access token that the client last used; null before its first one. */
	private ?string $accessTokenKey = null;

	/**
	 * Read and check the Datafordeler cfg.
	 *
	 * @param  App  $app
	 * @param  DatafordelerAuthentication  $authentication  Credentials that every request of the client uses.
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.datafordeler has a base_url, or for the Shared Secret a token_url, that is not an HTTPS URL, or a timeout that is not a finite number of at least 0.
	 */
	public function __construct(App $app, DatafordelerAuthentication $authentication = DatafordelerAuthentication::ApiKey) {
		$this->app = $app;
		$this->authentication = $authentication;

		$cfg = $this->app->cfg->danish_public_sector_data->datafordeler;

		$baseUrl = (string)$cfg->base_url;
		$baseUrl = \rtrim($baseUrl, '/');

		if ($baseUrl === '' || !\str_starts_with($baseUrl, 'https://')) {
			throw new \UnexpectedValueException('Datafordeler base_url must be a non-empty HTTPS URL.');
		}

		if ($authentication === DatafordelerAuthentication::SharedSecret) {
			$tokenUrl = (string)$cfg->token_url;

			if ($tokenUrl === '' || !\str_starts_with($tokenUrl, 'https://')) {
				throw new \UnexpectedValueException('Datafordeler token_url must be a non-empty HTTPS URL.');
			}

			$this->tokenUrl = $tokenUrl;
		}

		$this->baseUrl        = $baseUrl;
		$this->timeout        = $this->normalizeTimeout($cfg->timeout, 'timeout');
		$this->connectTimeout = $this->normalizeTimeout($cfg->connect_timeout, 'connect_timeout');
	}

	/**
	 * Send one GraphQL query and return its data.
	 *
	 * Behavior:
	 * - Any GraphQL error fails the request, also when the answer holds data too.
	 * - A request that Datafordeler rejects with 401 or 403 discards the client's access token, so
	 *   the next request gets a new one.
	 *
	 * Typical usage:
	 *   $data = (new DatafordelerClient($this->app))->query('flexibleCurrent', 'v3', $query);
	 *
	 * @param  string  $service  GraphQL service path, such as flexibleCurrent or CVR/custom.
	 * @param  string  $version  Service version, such as v3.
	 * @param  string  $query  GraphQL query document.
	 * @param  array<string,mixed>  $variables  GraphQL variables; none when empty.
	 * @return array<string,mixed>  The data object of the answer.
	 * @throws \InvalidArgumentException  When the service path, the version or the query is invalid, or the query and its variables cannot be encoded as JSON.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When a credential is missing, empty or rejected.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When Datafordeler rate-limits the request or its token request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the request fails, Datafordeler answers with another unsuccessful status, or the answer holds GraphQL errors.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the answer is not a GraphQL response with a data object, or a token answer holds no usable access token.
	 */
	public function query(string $service, string $version, string $query, array $variables = []): array {
		$this->validateEndpoint($service, $version);

		if ($query === '' || \trim($query) === '') {
			throw new \InvalidArgumentException('GraphQL query must be a non-empty string.');
		}

		$credentials = $this->credentials();

		$payload = ['query' => $query];
		if ($variables !== []) {
			$payload['variables'] = $variables;
		}

		try {
			$body = \json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('GraphQL request payload cannot be JSON encoded.');
		}

		$url = $this->baseUrl . '/' . $service . '/' . $version;

		try {
			$response = $this->app->curl->execute($credentials + [
				'url' => $url,
				'method' => 'POST',
				'headers' => [
					'Accept: application/graphql-response+json',
					'Content-Type: application/json',
				],
				'body' => $body,
				'timeout' => $this->timeout,
				'connect_timeout' => $this->connectTimeout,
				'follow_redirects' => false,
				'return_headers' => false,
				'capture_info' => false,
			]);
		} catch (CurlException) {
			throw new RemoteServiceException('Datafordeler transport failed.');
		}

		$statusCode = (int)$response['status_code'];
		if ($statusCode === 401 || $statusCode === 403) {
			$this->forgetAccessToken();
			throw new AuthenticationException('Datafordeler rejected the configured credentials.', $statusCode);
		}
		if ($statusCode === 429) {
			throw new RateLimitException('Datafordeler rate limit exceeded.', $statusCode);
		}
		if (!$response['is_http_success']) {
			throw new RemoteServiceException('Datafordeler returned an unsuccessful HTTP response.', $statusCode);
		}

		try {
			$decoded = \json_decode($response['body'], true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidResponseException('Datafordeler returned invalid JSON.');
		}

		if (!\is_array($decoded)) {
			throw new InvalidResponseException('Datafordeler returned an invalid GraphQL response object.');
		}

		if (isset($decoded['errors'])) {
			if (!\is_array($decoded['errors'])) {
				throw new InvalidResponseException('Datafordeler returned an invalid GraphQL errors value.');
			}
			if ($decoded['errors'] !== []) {
				throw new RemoteServiceException('Datafordeler returned one or more GraphQL errors.', $statusCode);
			}
		}

		if (!\array_key_exists('data', $decoded) || !\is_array($decoded['data'])) {
			throw new InvalidResponseException('Datafordeler response does not contain a GraphQL data object.');
		}

		return $decoded['data'];
	}

	/**
	 * Download the GraphQL schema document of one service from its /schema endpoint.
	 *
	 * Behavior:
	 * - Authenticates, and reports failures, like query(), including the discarded access token
	 *   after a 401 or 403.
	 * - Returns the document as Datafordeler sends it.
	 *
	 * Typical usage:
	 *   $sdl = (new DatafordelerClient($this->app))->schema('BBR', 'v3');
	 *
	 * @param  string  $service  GraphQL service path, such as flexibleCurrent or BBR.
	 * @param  string  $version  Service version, such as v3.
	 * @return string  GraphQL SDL; never empty.
	 * @throws \InvalidArgumentException  When the service path or the version is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When a credential is missing, empty or rejected.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When Datafordeler rate-limits the request or its token request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the request fails or Datafordeler answers with another unsuccessful status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the document is empty, or a token answer holds no usable access token.
	 * @see \CitOmni\DanishPublicSectorData\Support\DatafordelerClient::query()  The failures of a request.
	 */
	public function schema(string $service, string $version): string {
		$this->validateEndpoint($service, $version);

		$credentials = $this->credentials();
		$url = $this->baseUrl . '/' . $service . '/' . $version . '/schema';

		try {
			$response = $this->app->curl->execute($credentials + [
				'url' => $url,
				'method' => 'GET',
				'headers' => [
					'Content-Type: application/json',
				],
				'timeout' => $this->timeout,
				'connect_timeout' => $this->connectTimeout,
				'follow_redirects' => false,
				'return_headers' => false,
				'capture_info' => false,
			]);
		} catch (CurlException) {
			throw new RemoteServiceException('Datafordeler schema transport failed.');
		}

		$statusCode = (int)$response['status_code'];
		if ($statusCode === 401 || $statusCode === 403) {
			$this->forgetAccessToken();
			throw new AuthenticationException('Datafordeler rejected the configured credentials.', $statusCode);
		}
		if ($statusCode === 429) {
			throw new RateLimitException('Datafordeler rate limit exceeded.', $statusCode);
		}
		if (!$response['is_http_success']) {
			throw new RemoteServiceException('Datafordeler schema endpoint returned an unsuccessful HTTP response.', $statusCode);
		}

		$body = $response['body'];
		if (!\is_string($body) || \trim($body) === '') {
			throw new InvalidResponseException('Datafordeler returned an empty GraphQL schema document.');
		}

		return $body;
	}


	/**
	 * Return the curl request keys that authenticate one request.
	 *
	 * @return array{query:array{apiKey:string},sensitive_query_keys:list<string>}|array{bearer_token:string}  The API key as a sensitive query parameter, or an access token for the Shared Secret.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When a credential is missing, empty or rejected.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When the token endpoint rate-limits the token request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the token request fails.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the token answer holds no usable access token.
	 */
	private function credentials(): array {
		if ($this->authentication === DatafordelerAuthentication::SharedSecret) {
			return ['bearer_token' => $this->accessToken()];
		}

		return [
			'query' => [
				'apiKey' => $this->secret(self::API_KEY_SECRET, 'API key'),
			],
			'sensitive_query_keys' => [
				'apiKey',
			],
		];
	}

	/**
	 * Return one credential from the secrets service.
	 *
	 * @param  string  $key  Secret key.
	 * @param  string  $label  Name of the credential in exception messages.
	 * @return string  Never empty.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When the secret is missing or empty.
	 */
	private function secret(string $key, string $label): string {
		try {
			$value = $this->app->secrets->get($key);
		} catch (\OutOfBoundsException) {
			throw new AuthenticationException('Datafordeler ' . $label . ' is not configured.');
		}

		if ($value === '') {
			throw new AuthenticationException('Datafordeler ' . $label . ' is empty.');
		}

		return $value;
	}

	/**
	 * Return an access token for the Shared Secret.
	 *
	 * Behavior:
	 * - Reuses the token that the process got for the same token endpoint and client id until
	 *   TOKEN_RENEWAL_MARGIN seconds before it expires, and asks for a new one after that.
	 *
	 * @return string  Access token without line breaks.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When a credential is missing, empty or rejected.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When the token endpoint rate-limits the token request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the token request fails.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the token answer holds no usable access token.
	 */
	private function accessToken(): string {
		$clientId = $this->secret(self::CLIENT_ID_SECRET, 'OAuth client id');
		$this->accessTokenKey = $this->tokenUrl . "\n" . $clientId;

		$cached = self::$accessTokens[$this->accessTokenKey] ?? null;
		if ($cached !== null && $cached['expiresAt'] - self::TOKEN_RENEWAL_MARGIN > \time()) {
			return $cached['token'];
		}

		$issued = $this->requestAccessToken($clientId, $this->secret(self::CLIENT_SECRET_SECRET, 'OAuth client secret'));
		self::$accessTokens[$this->accessTokenKey] = $issued;

		return $issued['token'];
	}

	/**
	 * Ask the token endpoint for an access token with the client credentials grant.
	 *
	 * Behavior:
	 * - The token expires expires_in seconds after the request was sent, or DEFAULT_TOKEN_LIFETIME
	 *   seconds when the answer gives no positive expires_in.
	 *
	 * @param  string  $clientId  Client id of the Shared Secret.
	 * @param  string  $clientSecret  Client secret of the Shared Secret.
	 * @return array{token:string,expiresAt:int}  expiresAt is a Unix time.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException  When the token endpoint rejects the client credentials with 400, 401 or 403.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When the token endpoint rate-limits the request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the request fails or the token endpoint answers with another unsuccessful status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the answer is not JSON or holds no usable access token.
	 */
	private function requestAccessToken(string $clientId, #[\SensitiveParameter] string $clientSecret): array {
		// Counted from before the request, so a slow answer cannot stretch the token's lifetime.
		$requestedAt = \time();

		try {
			$response = $this->app->curl->execute([
				'url' => $this->tokenUrl,
				'method' => 'POST',
				'headers' => [
					'Accept: application/json',
					'Content-Type: application/x-www-form-urlencoded',
				],
				'body' => \http_build_query([
					'grant_type' => 'client_credentials',
					'client_id' => $clientId,
					'client_secret' => $clientSecret,
				], '', '&', \PHP_QUERY_RFC3986),
				'timeout' => $this->timeout,
				'connect_timeout' => $this->connectTimeout,
				'follow_redirects' => false,
				'return_headers' => false,
				'capture_info' => false,
			]);
		} catch (CurlException) {
			throw new RemoteServiceException('Datafordeler token transport failed.');
		}

		$statusCode = (int)$response['status_code'];
		if ($statusCode === 400 || $statusCode === 401 || $statusCode === 403) {
			throw new AuthenticationException('Datafordeler rejected the configured OAuth client credentials.', $statusCode);
		}
		if ($statusCode === 429) {
			throw new RateLimitException('Datafordeler token rate limit exceeded.', $statusCode);
		}
		if (!$response['is_http_success']) {
			throw new RemoteServiceException('Datafordeler token endpoint returned an unsuccessful HTTP response.', $statusCode);
		}

		try {
			$decoded = \json_decode($response['body'], true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidResponseException('Datafordeler token endpoint returned invalid JSON.');
		}

		$token = \is_array($decoded) ? ($decoded['access_token'] ?? null) : null;
		if (!\is_string($token) || $token === '' || \strpbrk($token, "\r\n\0") !== false) {
			throw new InvalidResponseException('Datafordeler token answer does not contain a usable access token.');
		}

		// OAuth makes expires_in optional; without it the token lives as long as Datafordeler documents.
		$lifetime = $decoded['expires_in'] ?? null;
		if (!\is_int($lifetime) || $lifetime < 1) {
			$lifetime = self::DEFAULT_TOKEN_LIFETIME;
		}

		return ['token' => $token, 'expiresAt' => $requestedAt + $lifetime];
	}

	/**
	 * Discard the access token that the client last used, so that the next request gets a new one.
	 *
	 * @return void
	 */
	private function forgetAccessToken(): void {
		if ($this->accessTokenKey !== null) {
			unset(self::$accessTokens[$this->accessTokenKey]);
		}
	}

	/**
	 * Check a GraphQL service path and version before they become part of a URL.
	 *
	 * @param  string  $service  Service path: Segments of letters and digits, each starting with a letter, separated by /.
	 * @param  string  $version  Version: v followed by a number of at least 1.
	 * @return void
	 * @throws \InvalidArgumentException  When either does not have that form.
	 */
	private function validateEndpoint(string $service, string $version): void {
		if (!\preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\/[A-Za-z][A-Za-z0-9]*)*$/D', $service)) {
			throw new \InvalidArgumentException('Datafordeler service path is invalid.');
		}

		if (!\preg_match('/^v[1-9][0-9]*$/D', $version)) {
			throw new \InvalidArgumentException('Datafordeler service version is invalid.');
		}
	}

	/**
	 * Check one timeout from cfg.
	 *
	 * @param  mixed  $value  Timeout from cfg.
	 * @param  string  $key  Cfg key, for the exception message.
	 * @return int|float  Timeout in seconds.
	 * @throws \UnexpectedValueException  When the value is not a finite number of at least 0.
	 */
	private function normalizeTimeout(mixed $value, string $key): int|float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new \UnexpectedValueException('Datafordeler ' . $key . ' must be an int or float.');
		}

		if ($value < 0 || (\is_float($value) && !\is_finite($value))) {
			throw new \UnexpectedValueException('Datafordeler ' . $key . ' must be finite and >= 0.');
		}

		return $value;
	}
}
