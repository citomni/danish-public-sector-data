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

use CitOmni\DanishPublicSectorData\Exception\AuthenticationException;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
use CitOmni\Infrastructure\Exception\CurlException;
use CitOmni\Kernel\App;

/**
 * DatafordelerClient: Execute authenticated GraphQL queries against Datafordeleren.
 *
 * Behavior:
 * - Reads the API key lazily from the app-local secret store for each query.
 * - Sends GraphQL requests through the shared CitOmni Curl service.
 * - Maps HTTP, GraphQL, transport, and malformed-response failures to package exceptions.
 * - Marks the API-key query parameter as sensitive so exposed Curl URLs are redacted.
 *
 * Notes:
 * - API-key authentication is intentionally limited to non-access-restricted data in this first version.
 * - Datafordeleren places API keys in the request URL; Curl redacts the configured sensitive query key
 *   from logs, exceptions, and returned transport metadata while preserving the real outbound URL.
 * - OAuth can be added later without changing domain services such as Cvr.
 *
 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException When the API key is missing or rejected.
 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException When Datafordeleren rate-limits the request.
 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException When transport, HTTP, or GraphQL execution fails.
 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the response is not valid GraphQL JSON.
 */
final class DatafordelerClient {

	private const API_KEY_SECRET = 'datafordeler.api_key';

	private App $app;
	private string $baseUrl;
	private int|float $timeout;
	private int|float $connectTimeout;

	/**
	 * Create the internal Datafordeler client and validate package configuration.
	 *
	 * @param App $app Application container.
	 * @throws \UnexpectedValueException When package configuration is invalid.
	 */
	public function __construct(App $app) {
		$this->app = $app;

		$cfg = $this->app->cfg->danish_public_sector_data->datafordeler;

		$baseUrl = (string)($cfg->base_url ?? '');
		$baseUrl = \rtrim($baseUrl, '/');

		if ($baseUrl === '' || !\str_starts_with($baseUrl, 'https://')) {
			throw new \UnexpectedValueException('Datafordeler base_url must be a non-empty HTTPS URL.');
		}

		$this->baseUrl        = $baseUrl;
		$this->timeout        = $this->normalizeTimeout($cfg->timeout ?? 15, 'timeout');
		$this->connectTimeout = $this->normalizeTimeout($cfg->connect_timeout ?? 5, 'connect_timeout');
	}

	/**
	 * Execute one GraphQL query against a Datafordeler service endpoint.
	 *
	 * Behavior:
	 * - Validates endpoint path components before building the URL.
	 * - Uses the standard JSON GraphQL request envelope.
	 * - Treats any GraphQL error array as a failed request, even when partial data is also returned.
	 *
	 * @param string $service Datafordeler GraphQL service path, for example "flexibleCurrent" or "CVR/custom".
	 * @param string $version Service version in the form "vN".
	 * @param string $query GraphQL query document.
	 * @param array<string,mixed> $variables Optional GraphQL variables.
	 * @return array<string,mixed> GraphQL data object.
	 * @throws \InvalidArgumentException When endpoint or query input is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException When the API key is missing or rejected.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException When Datafordeleren rate-limits the request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException When transport, HTTP, or GraphQL execution fails.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the response is malformed.
	 */
	public function query(string $service, string $version, string $query, array $variables = []): array {
		$this->validateEndpoint($service, $version);

		if ($query === '' || \trim($query) === '') {
			throw new \InvalidArgumentException('GraphQL query must be a non-empty string.');
		}

		$apiKey = $this->apiKey();

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
			$response = $this->app->curl->execute([
				'url' => $url,
				'method' => 'POST',
				'query' => [
					'apiKey' => $apiKey,
				],
				'sensitive_query_keys' => [
					'apiKey',
				],
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
	 * Return the configured Datafordeler API key.
	 *
	 * @return string Non-empty API key.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\AuthenticationException When the API key is missing or empty.
	 */
	private function apiKey(): string {
		try {
			$apiKey = $this->app->secrets->get(self::API_KEY_SECRET);
		} catch (\OutOfBoundsException) {
			throw new AuthenticationException('Datafordeler API key is not configured.');
		}

		if ($apiKey === '') {
			throw new AuthenticationException('Datafordeler API key is empty.');
		}

		return $apiKey;
	}

	/**
	 * Validate a GraphQL service path and version before URL construction.
	 *
	 * @param string $service Service path.
	 * @param string $version Version string.
	 * @return void
	 * @throws \InvalidArgumentException When a path component is invalid.
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
	 * Normalize a timeout without silently accepting invalid configuration.
	 *
	 * @param mixed $value Raw configured timeout.
	 * @param string $key Configuration key for the exception message.
	 * @return int|float Timeout in seconds.
	 * @throws \UnexpectedValueException When the value is not a finite non-negative number.
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
