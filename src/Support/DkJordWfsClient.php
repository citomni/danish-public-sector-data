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

use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
use CitOmni\Infrastructure\Exception\CurlException;
use CitOmni\Kernel\App;

/**
 * DkJordWfsClient: Query public DKjord WFS feature layers.
 *
 * Behavior:
 * - Uses the anonymous DKjord WFS endpoint exposed by Danmarks Miljøportal.
 * - Restricts each request to one official ejerlav code with a server-side CQL filter.
 * - Requests only the properties the caller names, so the response carries no geometry unless the caller names it.
 * - Validates the GeoJSON feature collection before returning source features.
 *
 * Notes:
 * - This client is internal; public package services own parcel matching and domain semantics.
 * - Layer and property names are supplied by the caller so transport stays independent of classification rules.
 * - The client rejects truncated feature collections instead of silently returning partial results.
 *
 * @internal
 */
final class DkJordWfsClient {

	private App $app;
	private string $baseUrl;
	private int|float $timeout;
	private int|float $connectTimeout;

	/**
	 * Create the internal DKjord WFS client and validate package configuration.
	 *
	 * @param App $app Application container.
	 * @throws \UnexpectedValueException When package configuration is invalid.
	 */
	public function __construct(App $app) {
		$this->app = $app;

		$cfg = $this->app->cfg->danish_public_sector_data->dkjord_wfs;
		$baseUrl = \rtrim((string)$cfg->base_url, '/');

		if ($baseUrl === '' || !\str_starts_with($baseUrl, 'https://')) {
			throw new \UnexpectedValueException('DKjord WFS base_url must be a non-empty HTTPS URL.');
		}

		$this->baseUrl = $baseUrl;
		$this->timeout = $this->normalizeTimeout($cfg->timeout, 'timeout');
		$this->connectTimeout = $this->normalizeTimeout($cfg->connect_timeout, 'connect_timeout');
	}

	/**
	 * Return DKjord features from one WFS layer for one official ejerlav code.
	 *
	 * @param string $typeName Exact WFS type name, for example "DKJord:View_Lokaliteter".
	 * @param int $cadastralDistrictIdentifier Official ejerlav identifier.
	 * @param list<string> $propertyNames Properties to request: letters, digits and underscores only.
	 * @return list<array<string,mixed>> GeoJSON features with the requested properties.
	 * @throws \InvalidArgumentException When the layer name, the district identifier or a property name is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException When DKjord rate-limits the request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException When transport or HTTP execution fails.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the response cannot be interpreted safely.
	 */
	public function getFeatures(string $typeName, int $cadastralDistrictIdentifier, array $propertyNames): array {
		if (!\preg_match('/^DKJord:View_[A-Za-z0-9]+$/D', $typeName)) {
			throw new \InvalidArgumentException('DKjord WFS type name is invalid.');
		}
		if ($cadastralDistrictIdentifier < 1) {
			throw new \InvalidArgumentException('DKjord cadastral district identifier must be positive.');
		}

		$propertyNames = self::buildPropertyNames($propertyNames);

		try {
			$response = $this->app->curl->execute([
				'url' => $this->baseUrl,
				'method' => 'GET',
				'query' => [
					'SERVICE' => 'WFS',
					'VERSION' => '2.0.0',
					'REQUEST' => 'GetFeature',
					'TYPENAMES' => $typeName,
					'OUTPUTFORMAT' => 'application/json',
					'PROPERTYNAME' => $propertyNames,
					'CQL_FILTER' => 'Lokalitetsejerlavkode=' . $cadastralDistrictIdentifier,
				],
				'headers' => [
					'Accept: application/json',
				],
				'timeout' => $this->timeout,
				'connect_timeout' => $this->connectTimeout,
				'follow_redirects' => false,
				'return_headers' => false,
				'capture_info' => false,
			]);
		} catch (CurlException) {
			throw new RemoteServiceException('DKjord WFS transport failed for layer ' . $typeName . '.');
		}

		$statusCode = (int)$response['status_code'];
		if ($statusCode === 429) {
			throw new RateLimitException('DKjord WFS rate limit exceeded for layer ' . $typeName . '.', $statusCode);
		}
		if (!$response['is_http_success']) {
			throw new RemoteServiceException(
				'DKjord WFS layer ' . $typeName . ' returned HTTP ' . $statusCode . '.',
				$statusCode
			);
		}

		try {
			$decoded = \json_decode($response['body'], true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			throw new InvalidResponseException('DKjord WFS returned invalid JSON.');
		}

		if (!\is_array($decoded) || ($decoded['type'] ?? null) !== 'FeatureCollection') {
			throw new InvalidResponseException('DKjord WFS returned an invalid GeoJSON feature collection.');
		}

		$features = $decoded['features'] ?? null;
		if (!\is_array($features) || !\array_is_list($features)) {
			throw new InvalidResponseException('DKjord WFS feature collection does not contain a valid feature list.');
		}

		foreach ($features as $feature) {
			if (!\is_array($feature) || ($feature['type'] ?? null) !== 'Feature' || !\is_array($feature['properties'] ?? null)) {
				throw new InvalidResponseException('DKjord WFS feature collection contains an invalid feature.');
			}
		}

		$numberReturned = $decoded['numberReturned'] ?? null;
		if ($numberReturned !== null && (!\is_int($numberReturned) || $numberReturned !== \count($features))) {
			throw new InvalidResponseException('DKjord WFS returned inconsistent numberReturned metadata.');
		}

		$numberMatched = $decoded['numberMatched'] ?? null;
		if (\is_int($numberMatched) && $numberMatched > \count($features)) {
			throw new InvalidResponseException('DKjord WFS feature collection was truncated.');
		}

		return $features;
	}

	/**
	 * Build the WFS property projection from the requested property names.
	 *
	 * @param list<string> $propertyNames Property names: letters, digits and underscores only.
	 * @return string Comma-separated WFS property names, in the given order.
	 * @throws \InvalidArgumentException When the list is empty or a name is not a valid property name.
	 */
	private static function buildPropertyNames(array $propertyNames): string {
		if ($propertyNames === []) {
			throw new \InvalidArgumentException('DKjord WFS property names must not be empty.');
		}

		foreach ($propertyNames as $propertyName) {
			// A comma or other separator would silently change the projection.
			if (!\is_string($propertyName) || !\preg_match('/^[A-Za-z0-9_]+$/D', $propertyName)) {
				throw new \InvalidArgumentException('DKjord WFS property name is invalid.');
			}
		}

		return \implode(',', $propertyNames);
	}

	/**
	 * Normalize one configured timeout value.
	 *
	 * @param mixed $value Raw timeout value.
	 * @param string $name Configuration key name.
	 * @return int|float Timeout in seconds.
	 * @throws \UnexpectedValueException When the timeout is invalid.
	 */
	private function normalizeTimeout(mixed $value, string $name): int|float {
		if (!\is_int($value) && !\is_float($value)) {
			throw new \UnexpectedValueException('DKjord WFS ' . $name . ' must be numeric.');
		}
		if ($value <= 0 || (\is_float($value) && !\is_finite($value))) {
			throw new \UnexpectedValueException('DKjord WFS ' . $name . ' must be finite and greater than zero.');
		}

		return $value;
	}
}
