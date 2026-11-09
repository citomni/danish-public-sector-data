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
 * DkJordWfsClient: Support class that reads feature layers of the anonymous DKjord WFS.
 *
 * Gives the soil-contamination lookup the features of one ejerlav, checked as a complete GeoJSON
 * feature collection. Which layer and properties to read, and what a feature means, belong to the
 * caller.
 *
 * Rules:
 * - Request only the properties the caller names, so no geometry is downloaded unless asked for.
 * - Never return a feature collection that the server reports as truncated.
 *
 * @internal
 */
class DkJordWfsClient {

	/** App whose cfg and curl service the client uses. */
	private App $app;

	/** URL of the WFS endpoint, without a trailing slash. */
	private string $baseUrl;

	/** Request timeout in seconds. */
	private int|float $timeout;

	/** Connect timeout in seconds. */
	private int|float $connectTimeout;

	/**
	 * Read and check the DKjord WFS cfg.
	 *
	 * @param  App  $app
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.dkjord_wfs has a base_url that is not an HTTPS URL, or a timeout that is not a finite number above 0.
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
	 * Return the features of one layer that lie in one ejerlav.
	 *
	 * Behavior:
	 * - Selects the features whose Lokalitetsejerlavkode is the ejerlav code, with only the named
	 *   properties.
	 * - Every feature returned is a GeoJSON feature with a properties object.
	 *
	 * Typical usage:
	 *   $features = (new DkJordWfsClient($this->app))->getFeatures('DKJord:View_Lokaliteter', 2005352, ['Lokalitetsnr']);
	 *
	 * @param  string  $typeName  WFS type of a DKjord view, such as DKJord:View_Lokaliteter.
	 * @param  int  $cadastralDistrictIdentifier  Official ejerlav code, at least 1.
	 * @param  list<string>  $propertyNames  Properties to request, in this order: Letters, digits and underscores only.
	 * @return list<array<string,mixed>>
	 * @throws \InvalidArgumentException  When the type, the ejerlav code or a property name is invalid, or no property is named.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RateLimitException  When DKjord rate-limits the request.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\RemoteServiceException  When the request fails or DKjord answers with an unsuccessful status.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the answer is not a complete GeoJSON feature collection.
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
	 * Build the PROPERTYNAME projection of a WFS request.
	 *
	 * @param  list<string>  $propertyNames  Property names: Letters, digits and underscores only.
	 * @return string  The names separated by commas, in the given order.
	 * @throws \InvalidArgumentException  When the list is empty or a name has another character.
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
	 * Check one timeout from cfg.
	 *
	 * @param  mixed  $value  Timeout from cfg.
	 * @param  string  $name  Cfg key, for the exception message.
	 * @return int|float  Timeout in seconds.
	 * @throws \UnexpectedValueException  When the value is not a finite number above 0.
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
