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

use CitOmni\DanishPublicSectorData\Boot\Registry;
use CitOmni\DanishPublicSectorData\Command\BbrSchemaCheckCommand;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaComparator;
use CitOmni\DanishPublicSectorData\Util\BbrSchemaDocument;
use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;

$root = \dirname(__DIR__);
require_once $root . '/src/Util/BbrSchemaDocument.php';
require_once $root . '/src/Util/BbrSchemaComparator.php';
require_once $root . '/src/Boot/Registry.php';
require_once $root . '/src/Support/DatafordelerClient.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new \RuntimeException('FAIL: ' . $message);
	}

	++$checks;
};

$currentSource = \file_get_contents($root . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
$historySource = \file_get_contents($root . '/resources/bbr/BBR_V003.schema.graphql');
$check($currentSource !== false && $currentSource !== '', 'Runtime FLEX_CURRENT v3 schema is readable.');
$check($historySource !== false && $historySource !== '', 'Runtime BBR v3 schema is readable.');

$current = BbrSchemaDocument::types((string)$currentSource);
$history = BbrSchemaDocument::types((string)$historySource);
$check(isset($current['BBR_Bygning']['byg026Opfoerelsesaar']), 'Current schema parser includes BBR_Bygning construction year.');
$check($current['BBR_Bygning']['byg026Opfoerelsesaar'] === 'Long', 'Current schema parser preserves scalar type signatures.');
$check(isset($history['BBR_TekniskAnlaeg']['tek027Placering']), 'History schema parser includes technical installation placement.');
$check(\count($current) >= 10, 'Current runtime schema exposes multiple BBR domain types.');
$check(\count($history) >= 10, 'History runtime schema exposes multiple BBR domain types.');

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
$check($diff['has_drift'] === true, 'Comparator reports drift.');
$check($diff['new_types'] === ['BBR_NyType'], 'Comparator reports new BBR object types.');
$check(($diff['changed_types']['BBR_Bygning']['added_fields']['newField'] ?? null) === 'Boolean', 'Comparator reports added fields.');
$check(($diff['changed_types']['BBR_Bygning']['removed_fields']['oldField'] ?? null) === 'String', 'Comparator reports removed fields.');
$check(($diff['changed_types']['BBR_Bygning']['changed_field_types']['byg026Opfoerelsesaar']['remote'] ?? null) === 'String', 'Comparator reports changed field signatures.');

$clientReflection = new \ReflectionClass(DatafordelerClient::class);
$check($clientReflection->hasMethod('schema'), 'Datafordeler client exposes the documented schema downloader.');
$schemaMethod = $clientReflection->getMethod('schema');
$check($schemaMethod->isPublic(), 'Datafordeler schema downloader is public for maintenance operations.');

$command = Registry::COMMANDS_CLI['bbr:schema-check'] ?? null;
$check(\is_array($command), 'Provider registers bbr:schema-check.');
$check(($command['command'] ?? null) === BbrSchemaCheckCommand::class, 'bbr:schema-check maps to the schema-check command.');

$threw = false;
try {
	BbrSchemaDocument::types('');
} catch (\UnexpectedValueException) {
	$threw = true;
}
$check($threw, 'Empty schema documents fail fast.');

\fwrite(
	STDOUT,
	'PASS ' . $checks . ' checks. Runtime BBR schema parsing, comparator and CLI registration. PHP '
	. \PHP_VERSION . '.' . \PHP_EOL
);
