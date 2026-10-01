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

use CitOmni\DanishPublicSectorData\Util\BbrCodeLists;
use CitOmni\DanishPublicSectorData\Util\BbrFieldCatalog;

$root = \dirname(__DIR__);
require_once $root . '/src/Util/BbrCodeLists.php';
require_once $root . '/src/Util/BbrFieldCatalog.php';

$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new \RuntimeException('FAIL: ' . $message);
	}

	++$checks;
};

$schema = \file_get_contents($root . '/resources/bbr/BBR_V003.schema.graphql');
$currentSchema = \file_get_contents($root . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
$bbrSource = \file_get_contents($root . '/src/Service/Bbr.php');

$check($schema !== false, 'Bundled BBR v3 schema is readable.');
$check($currentSchema !== false, 'Bundled FLEX_CURRENT v3 schema is readable.');
$check($bbrSource !== false, 'BBR service source is readable.');

$schemaFields = static function (string $schemaSource, string $type): array {
	$quotedType = \preg_quote($type, '/');
	if (!\preg_match('/^type ' . $quotedType . ' \{\R(.*?)^\}/ms', $schemaSource, $match)) {
		throw new \RuntimeException('Schema type not found: ' . $type);
	}

	$block = \preg_replace('/  """.*?  """\R/s', '', $match[1]);
	if ($block === null) {
		throw new \RuntimeException('Could not strip schema descriptions for: ' . $type);
	}

	$fields = [];
	foreach (\preg_split('/\R/', $block) as $line) {
		if (!\preg_match('/^  ([A-Za-z_][A-Za-z0-9_]*)(?:\([^)]*\))?:\s*([^ @]+)/', $line, $field)) {
			continue;
		}

		$fields[$field[1]] = $field[2];
	}

	return $fields;
};

$selectionFields = static function (string $source, string $method): array {
	$quotedMethod = \preg_quote($method, '/');
	if (!\preg_match('/private function ' . $quotedMethod . '\(\): string \{\R\s*return <<<\'GRAPHQL\'\R(.*?)\RGRAPHQL;/s', $source, $match)) {
		throw new \RuntimeException('Field-selection method not found: ' . $method);
	}

	$fields = [];
	foreach (\preg_split('/\R/', $match[1]) as $line) {
		if (\preg_match('/^([A-Za-z_][A-Za-z0-9_]*)/', $line, $field)) {
			$fields[] = $field[1];
		}
	}

	\sort($fields, \SORT_STRING);

	return $fields;
};

$normalizerRefs = static function (string $source, string $method): array {
	$quotedMethod = \preg_quote($method, '/');
	if (!\preg_match('/private function ' . $quotedMethod . '\([^\{]+\): array \{(.*?)(?=\R\t\/\*\*)/s', $source, $match)) {
		throw new \RuntimeException('Normalizer method not found: ' . $method);
	}

	\preg_match_all('/\$node\[\'([^\']+)\'\]/', $match[1], $refs);
	$fields = \array_values(\array_unique($refs[1] ?? []));
	\sort($fields, \SORT_STRING);

	return $fields;
};

$internalFields = [
	'datafordelerOpdateringstid',
	'datafordelerRegisterImportSequenceNumber',
	'datafordelerRowId',
	'datafordelerRowVersion',
	'id_namespace',
];

$entities = [
	'ground' => ['type' => 'BBR_Grund', 'prefix' => 'gru', 'selection' => 'groundFields', 'normalizer' => 'normalizeGround'],
	'building' => ['type' => 'BBR_Bygning', 'prefix' => 'byg', 'selection' => 'buildingFields', 'normalizer' => 'normalizeBuilding'],
	'unit' => ['type' => 'BBR_Enhed', 'prefix' => 'enh', 'selection' => 'unitFields', 'normalizer' => 'normalizeUnit'],
	'floor' => ['type' => 'BBR_Etage', 'prefix' => 'eta', 'selection' => 'floorFields', 'normalizer' => 'normalizeFloor'],
	'entrance' => ['type' => 'BBR_Opgang', 'prefix' => 'opg', 'selection' => 'entranceFields', 'normalizer' => 'normalizeEntrance'],
	'technicalInstallation' => ['type' => 'BBR_TekniskAnlaeg', 'prefix' => 'tek', 'selection' => 'technicalFields', 'normalizer' => 'normalizeTechnicalInstallation'],
];

$catalogReflection = new \ReflectionClass(BbrFieldCatalog::class);
$catalog = $catalogReflection->getConstant('ADDITIONAL_FIELDS');
$check(\is_array($catalog), 'Additional-field catalog is an array.');

foreach ($entities as $entity => $definition) {
	$fields = $schemaFields((string)$schema, $definition['type']);
	$currentFields = $schemaFields((string)$currentSchema, $definition['type']);
	$expectedSelection = \array_values(\array_diff(\array_keys($fields), $internalFields));
	\sort($expectedSelection, \SORT_STRING);

	$actualSelection = $selectionFields((string)$bbrSource, $definition['selection']);
	$check($actualSelection === $expectedSelection, $definition['type'] . ' query selects every non-internal BBR v3 field exactly once.');

	$missingFromCurrent = \array_values(\array_diff($actualSelection, \array_keys($currentFields)));
	$check($missingFromCurrent === [], $definition['type'] . ' BBR selection is valid in FLEX_CURRENT v3.');

	$domainFields = \array_values(\array_filter(
		\array_keys($fields),
		static fn(string $field): bool => \str_starts_with($field, $definition['prefix'])
	));
	\sort($domainFields, \SORT_STRING);

	$explicitFields = \array_values(\array_filter(
		$normalizerRefs((string)$bbrSource, $definition['normalizer']),
		static fn(string $field): bool => \str_starts_with($field, $definition['prefix'])
	));
	\sort($explicitFields, \SORT_STRING);

	$catalogFields = \array_keys($catalog[$entity] ?? []);
	\sort($catalogFields, \SORT_STRING);

	$overlap = \array_values(\array_intersect($explicitFields, $catalogFields));
	$check($overlap === [], $entity . ' additional-field catalog does not duplicate dedicated normalized fields.');

	$accounted = \array_values(\array_unique(\array_merge($explicitFields, $catalogFields)));
	\sort($accounted, \SORT_STRING);
	$check($accounted === $domainFields, $entity . ' has zero unaccounted BBR domain fields.');
}

$additional = BbrFieldCatalog::normalizeAdditionalFields('building', [
	'byg046SamletArealAfLukkedeOverdaekningerPaaBygningen' => 0,
	'byg152AabenLukketKonstruktion' => '1',
	'byg404Koordinat' => ['crs' => 25832, 'wkt' => 'POINT (1 2)'],
	'byg403OevrigeBemaerkningerFraStormraadet' => null,
]);

$check(\count($additional) === 3, 'Additional-field normalization omits null values but preserves meaningful values.');
$check(($additional[0]['value'] ?? null) === 0, 'Additional-field normalization preserves numeric zero.');
$check(($additional[1]['valueLabel'] ?? null) === 'Åben konstruktion', 'Additional coded field receives its bundled label.');
$check(($additional[2]['value']['crs'] ?? null) === 25832, 'Additional coordinate preserves CRS.');
$check(($additional[2]['value']['wkt'] ?? null) === 'POINT (1 2)', 'Additional coordinate preserves WKT.');

$threw = false;
try {
	BbrFieldCatalog::normalizeAdditionalFields('unknown', []);
} catch (\LogicException) {
	$threw = true;
}
$check($threw, 'Unknown BBR field-catalog entities fail fast.');

\fwrite(
	STDOUT,
	'PASS ' . $checks . ' checks. Complete BBR v3 field selection and lossless additional-field catalog. PHP '
	. \PHP_VERSION . '.' . \PHP_EOL
);
