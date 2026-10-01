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

namespace CitOmni\DanishPublicSectorData\Operation;

use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaComparator;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaDocument;
use CitOmni\Kernel\Operation\BaseOperation;

/**
 * Compare live Datafordeler BBR GraphQL schemas with the bundled runtime schemas.
 *
 * Behavior:
 * - Checks the configured current and/or history BBR endpoint.
 * - Fetches Datafordeler's published GraphQL SDL through the documented GET /schema endpoint.
 * - Reads the same full GraphQL SDL documents distributed with the package.
 * - Compares every field and GraphQL type signature for every BBR domain object type.
 *
 * Notes:
 * - This is a maintenance operation and performs live network IO plus local file reads.
 * - Generated Connection/Edge pagination types are intentionally ignored by the schema parser.
 * - Runtime schemas live under resources/bbr and are also the canonical inputs for local coverage tests.
 *
 * Typical usage:
 *   $result = (new CheckBbrSchema($app))->execute('all');
 *
 * @param string $scope One of "all", "current", or "history".
 * @return array{status:string,checks:list<array<string,mixed>>} Schema comparison result.
 * @throws \InvalidArgumentException When scope or BBR configuration is invalid.
 * @throws \RuntimeException When a bundled runtime schema cannot be read.
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When Datafordeleren cannot be inspected.
 */
final class CheckBbrSchema extends BaseOperation {

	public const string RESULT_OK = 'ok';
	public const string RESULT_DRIFT = 'drift';

	private const string BUNDLED_VERSION = 'v3';
	private const string CURRENT_SCHEMA_FILE = 'FLEX_CURRENT_V003.schema.graphql';
	private const string HISTORY_SCHEMA_FILE = 'BBR_V003.schema.graphql';

	public function execute(string $scope = 'all'): array {
		if (!\in_array($scope, ['all', 'current', 'history'], true)) {
			throw new \InvalidArgumentException('BBR schema-check scope must be all, current, or history.');
		}

		$cfg = $this->app->cfg->danish_public_sector_data->bbr;
		$client = new DatafordelerClient($this->app);
		$checks = [];

		if ($scope === 'all' || $scope === 'current') {
			$checks[] = $this->checkEndpoint(
				$client,
				'current',
				(string)$cfg->current_service,
				(string)$cfg->current_version,
				self::CURRENT_SCHEMA_FILE
			);
		}

		if ($scope === 'all' || $scope === 'history') {
			$checks[] = $this->checkEndpoint(
				$client,
				'history',
				(string)$cfg->history_service,
				(string)$cfg->history_version,
				self::HISTORY_SCHEMA_FILE
			);
		}

		foreach ($checks as $check) {
			if (($check['has_drift'] ?? false) === true) {
				return ['status' => self::RESULT_DRIFT, 'checks' => $checks];
			}
		}

		return ['status' => self::RESULT_OK, 'checks' => $checks];
	}

	/** Compare one configured endpoint with one bundled GraphQL SDL document. */
	private function checkEndpoint(
		DatafordelerClient $client,
		string $scope,
		string $service,
		string $version,
		string $schemaFile
	): array {
		if ($service === '' || $version === '') {
			throw new \InvalidArgumentException('BBR ' . $scope . ' service and version must be configured.');
		}

		$bundled = BbrSchemaDocument::types($this->readBundledSchema($schemaFile));
		$remote = BbrSchemaDocument::types($client->schema($service, $version));
		$diff = BbrSchemaComparator::compare($bundled, $remote);
		$versionMismatch = $version !== self::BUNDLED_VERSION;

		return [
			'scope' => $scope,
			'service' => $service,
			'version' => $version,
			'bundled_version' => self::BUNDLED_VERSION,
			'bundled_schema' => $schemaFile,
			'bundled_type_count' => \count($bundled),
			'remote_type_count' => \count($remote),
			'version_mismatch' => $versionMismatch,
			'has_drift' => $versionMismatch || $diff['has_drift'],
			'new_types' => $diff['new_types'],
			'removed_types' => $diff['removed_types'],
			'changed_types' => $diff['changed_types'],
		];
	}

	/** Read one package-distributed GraphQL schema document. */
	private function readBundledSchema(string $filename): string {
		$path = \dirname(__DIR__, 2) . '/resources/bbr/' . $filename;
		$source = @\file_get_contents($path);

		if ($source === false || $source === '') {
			throw new \RuntimeException('Bundled BBR runtime schema cannot be read: ' . $filename . '.');
		}

		return $source;
	}
}
