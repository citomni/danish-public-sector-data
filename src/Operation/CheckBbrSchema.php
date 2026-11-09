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
 * CheckBbrSchema: Operation that compares the live Datafordeler BBR schemas with the schemas the package bundles.
 *
 * Gives maintainers a check that the BBR types Datafordeler publishes still match the ones the
 * package was written against, so a change shows before it breaks a lookup. It reads the bundled
 * schemas and downloads the live ones; parsing and comparing them belongs to BbrSchemaDocument and
 * BbrSchemaComparator, and presenting the result to the adapters.
 *
 * Rules:
 * - Report drift as data, never as an exception.
 */
class CheckBbrSchema extends BaseOperation {

	/** No checked endpoint differs from its bundled schema. */
	public const string RESULT_OK = 'ok';

	/** At least one checked endpoint differs from its bundled schema or has another version. */
	public const string RESULT_DRIFT = 'drift';

	/** Datafordeler version that the bundled schemas describe. */
	private const string BUNDLED_VERSION = 'v3';

	/** Bundled schema of the current-state endpoint, in resources/bbr. */
	private const string CURRENT_SCHEMA_FILE = 'FLEX_CURRENT_V003.schema.graphql';

	/** Bundled schema of the history endpoint, in resources/bbr. */
	private const string HISTORY_SCHEMA_FILE = 'BBR_V003.schema.graphql';

	/**
	 * Compare the schemas of the configured BBR endpoints with the bundled ones.
	 *
	 * Downloads the GraphQL schema of each endpoint in scope from Datafordeler and compares its BBR
	 * object types with the bundled schema of that endpoint.
	 *
	 * Behavior:
	 * - checks holds one entry per endpoint in scope, the current-state endpoint first.
	 * - An endpoint has drift when its BBR types differ from the bundled ones, or when its
	 *   configured version is not the bundled version.
	 * - status is RESULT_DRIFT when an endpoint has drift, and RESULT_OK otherwise.
	 *
	 * Typical usage:
	 *   $result = (new CheckBbrSchema($this->app))->execute('all');
	 *
	 * @param  string  $scope  all, current or history.
	 * @return array{status:string,checks:list<array{scope:string,service:string,version:string,bundled_version:string,bundled_schema:string,bundled_type_count:int,remote_type_count:int,version_mismatch:bool,has_drift:bool,new_types:list<string>,removed_types:list<string>,changed_types:array<string,array<string,mixed>>}>}
	 * @throws \InvalidArgumentException  When the scope is unknown, or cfg danish_public_sector_data.bbr leaves the service or the version of an endpoint in scope empty.
	 * @throws \UnexpectedValueException  When the Datafordeler cfg is invalid, or a schema holds no BBR object type.
	 * @throws \RuntimeException  When a bundled schema cannot be read.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a schema cannot be downloaded.
	 * @see \CitOmni\DanishPublicSectorData\Util\BbrSchemaComparator::compare()  What new_types, removed_types and changed_types hold.
	 */
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

	/**
	 * Compare one endpoint with its bundled schema.
	 *
	 * @param  DatafordelerClient  $client  Client that downloads the schema.
	 * @param  string  $scope  current or history.
	 * @param  string  $service  Datafordeler service of the endpoint.
	 * @param  string  $version  Version of that service.
	 * @param  string  $schemaFile  File name of the bundled schema in resources/bbr.
	 * @return array{scope:string,service:string,version:string,bundled_version:string,bundled_schema:string,bundled_type_count:int,remote_type_count:int,version_mismatch:bool,has_drift:bool,new_types:list<string>,removed_types:list<string>,changed_types:array<string,array<string,mixed>>}
	 * @throws \InvalidArgumentException  When the service or the version is empty.
	 * @throws \UnexpectedValueException  When a schema holds no BBR object type.
	 * @throws \RuntimeException  When the bundled schema cannot be read.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When the schema cannot be downloaded.
	 */
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

	/**
	 * Read one bundled schema.
	 *
	 * @param  string  $filename  File name in resources/bbr.
	 * @return string  GraphQL SDL; never empty.
	 * @throws \RuntimeException  When the file cannot be read or is empty.
	 */
	private function readBundledSchema(string $filename): string {
		$path = \dirname(__DIR__, 2) . '/resources/bbr/' . $filename;
		$source = @\file_get_contents($path);

		if ($source === false || $source === '') {
			throw new \RuntimeException('Bundled BBR runtime schema cannot be read: ' . $filename . '.');
		}

		return $source;
	}
}
