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

namespace CitOmni\DanishPublicSectorData\Tests;

use CitOmni\DanishPublicSectorData\Boot\Registry;
use CitOmni\DanishPublicSectorData\Command\BbrSchemaCheckCommand;
use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaComparator;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaDocument;

/*
 * Isolated suite for the BBR schema check: BbrSchemaDocument on the bundled schemas,
 * BbrSchemaComparator, the schema download that CheckBbrSchema relies on, and the registration
 * of bbr:schema-check. No request is sent.
 *
 * Usage:
 *   php tests/bbr-schema-check/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/**
 * Read one bundled schema document.
 *
 * @param  string  $file  File name in resources/bbr.
 * @return string|false  False when the file cannot be read.
 */
function bundledSchema(string $file): string|false {
	$path = \dirname(__DIR__, 2) . '/resources/bbr/' . $file;

	return \is_file($path) ? \file_get_contents($path) : false;
}


test('BbrSchemaDocument reads the BBR object types of both bundled schemas with their field types', function (): void {
	$currentSource = bundledSchema('FLEX_CURRENT_V003.schema.graphql');
	$historySource = bundledSchema('BBR_V003.schema.graphql');
	check($currentSource !== false && $currentSource !== '', 'Runtime FLEX_CURRENT v3 schema is readable.');
	check($historySource !== false && $historySource !== '', 'Runtime BBR v3 schema is readable.');

	$current = BbrSchemaDocument::types((string)$currentSource);
	$history = BbrSchemaDocument::types((string)$historySource);
	check(isset($current['BBR_Bygning']['byg026Opfoerelsesaar']), 'Current schema parser includes BBR_Bygning construction year.');
	check($current['BBR_Bygning']['byg026Opfoerelsesaar'] === 'Long', 'Current schema parser preserves scalar type signatures.');
	check(isset($history['BBR_TekniskAnlaeg']['tek027Placering']), 'History schema parser includes technical installation placement.');
	check(\count($current) >= 10, 'Current runtime schema exposes multiple BBR domain types.');
	check(\count($history) >= 10, 'History runtime schema exposes multiple BBR domain types.');
});

test('BbrSchemaDocument rejects an empty schema', function (): void {
	throws(\UnexpectedValueException::class, fn() => BbrSchemaDocument::types(''));
});

test('BbrSchemaComparator reports new types, added and removed fields and changed field types as drift', function (): void {
	$remote = [
		'BBR_Bygning' => [
			'byg026Opfoerelsesaar' => 'String',
			'newField' => 'Boolean',
		],
		'BBR_NyType' => [],
	];
	$bundled = [
		'BBR_Bygning' => [
			'byg026Opfoerelsesaar' => 'Long',
			'oldField' => 'String',
		],
	];
	$diff = BbrSchemaComparator::compare($bundled, $remote);

	check($diff['has_drift'] === true, 'Comparator reports drift.');
	check($diff['new_types'] === ['BBR_NyType'], 'Comparator reports new BBR object types.');
	check(($diff['changed_types']['BBR_Bygning']['added_fields']['newField'] ?? null) === 'Boolean', 'Comparator reports added fields.');
	check(($diff['changed_types']['BBR_Bygning']['removed_fields']['oldField'] ?? null) === 'String', 'Comparator reports removed fields.');
	check(($diff['changed_types']['BBR_Bygning']['changed_field_types']['byg026Opfoerelsesaar']['remote'] ?? null) === 'String', 'Comparator reports changed field signatures.');
});

test('DatafordelerClient::schema() is public, so CheckBbrSchema can download a schema', function (): void {
	$client = new \ReflectionClass(DatafordelerClient::class);

	check($client->hasMethod('schema'), 'Datafordeler client exposes the documented schema downloader.');
	check($client->getMethod('schema')->isPublic(), 'Datafordeler schema downloader is public for maintenance operations.');
});

test('The Registry maps bbr:schema-check to BbrSchemaCheckCommand', function (): void {
	$command = Registry::COMMANDS_CLI['bbr:schema-check'] ?? null;

	check(\is_array($command), 'Provider registers bbr:schema-check.');
	check(($command['command'] ?? null) === BbrSchemaCheckCommand::class, 'bbr:schema-check maps to the schema-check command.');
});

done();
