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

use CitOmni\DanishPublicSectorData\Service\Bbr;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;
use CitOmni\DanishPublicSectorData\Util\BbrCodeLists;
use CitOmni\DanishPublicSectorData\Util\BbrFieldCatalog;

/*
 * Isolated suite for the bbr service: How it reads typed auction addresses and chooses streets
 * and DAR addresses, how it labels and normalizes BBR nodes, the bundled code lists, and that its
 * queries select every BBR v3 field, each kept under a key of its own or by BbrFieldCatalog. The
 * app double registers no curl service, so no request can be sent.
 *
 * Usage:
 *   php tests/bbr/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Datafordeler fields of every BBR type that the service does not select. */
const INTERNAL_FIELDS = [
	'datafordelerOpdateringstid',
	'datafordelerRegisterImportSequenceNumber',
	'datafordelerRowId',
	'datafordelerRowVersion',
	'id_namespace',
];

/** Each BBR entity of the service: Its schema type, field prefix, selection method and normalizer, keyed as BbrFieldCatalog keys it. */
const ENTITIES = [
	'ground' => ['type' => 'BBR_Grund', 'prefix' => 'gru', 'selection' => 'groundFields', 'normalizer' => 'normalizeGround'],
	'building' => ['type' => 'BBR_Bygning', 'prefix' => 'byg', 'selection' => 'buildingFields', 'normalizer' => 'normalizeBuilding'],
	'unit' => ['type' => 'BBR_Enhed', 'prefix' => 'enh', 'selection' => 'unitFields', 'normalizer' => 'normalizeUnit'],
	'floor' => ['type' => 'BBR_Etage', 'prefix' => 'eta', 'selection' => 'floorFields', 'normalizer' => 'normalizeFloor'],
	'entrance' => ['type' => 'BBR_Opgang', 'prefix' => 'opg', 'selection' => 'entranceFields', 'normalizer' => 'normalizeEntrance'],
	'technicalInstallation' => ['type' => 'BBR_TekniskAnlaeg', 'prefix' => 'tek', 'selection' => 'technicalFields', 'normalizer' => 'normalizeTechnicalInstallation'],
];


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/**
 * Call a private method of a service built on the cfg it reads.
 *
 * @param  string  $method
 * @param  mixed  ...$arguments
 * @return mixed
 */
function call(string $method, mixed ...$arguments): mixed {
	static $service = null;
	$service ??= new Bbr(new App(['danish_public_sector_data' => [
		'bbr' => [
			'current_service' => 'flexibleCurrent',
			'current_version' => 'v3',
			'history_service' => 'BBR',
			'history_version' => 'v3',
		],
		'datafordeler' => [
			'base_url'        => 'https://graphql.datafordeler.dk',
			'timeout'         => 15,
			'connect_timeout' => 5,
		],
	]]));

	return (new \ReflectionMethod(Bbr::class, $method))->invoke($service, ...$arguments);
}


/**
 * Read a file of the package.
 *
 * @param  string  $path  Path relative to the package root.
 * @return string|false  False when the file cannot be read.
 */
function packageFile(string $path): string|false {
	$file = \dirname(__DIR__, 2) . '/' . $path;

	return \is_file($file) ? \file_get_contents($file) : false;
}


/**
 * Return the fields of one type of a bundled schema with their types.
 *
 * @param  string  $schemaSource  GraphQL SDL.
 * @param  string  $type  Object type.
 * @return array<string,string>  Field types keyed by field name.
 */
function schemaFields(string $schemaSource, string $type): array {
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
}


/**
 * Return the fields that a selection method of the service source selects.
 *
 * @param  string  $source  Source of the Bbr service.
 * @param  string  $method  Selection method, such as groundFields.
 * @return list<string>  Field names, sorted.
 */
function selectionFields(string $source, string $method): array {
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
}


/**
 * Return the node fields that a normalizer of the service source reads.
 *
 * @param  string  $source  Source of the Bbr service.
 * @param  string  $method  Normalizer, such as normalizeGround.
 * @return list<string>  Field names, sorted.
 */
function normalizerRefs(string $source, string $method): array {
	$quotedMethod = \preg_quote($method, '/');
	if (!\preg_match('/private function ' . $quotedMethod . '\([^\{]+\): array \{(.*?)(?=\R\t\/\*\*)/s', $source, $match)) {
		throw new \RuntimeException('Normalizer method not found: ' . $method);
	}

	\preg_match_all('/\$node\[\'([^\']+)\'\]/', $match[1], $refs);
	$fields = \array_values(\array_unique($refs[1] ?? []));
	\sort($fields, \SORT_STRING);

	return $fields;
}


/**
 * Return the three bundled sources of BbrCodeLists.
 *
 * @return array{primary:mixed,verified:mixed,supplement:mixed}
 */
function codeListSources(): array {
	$reflection = new \ReflectionClass(BbrCodeLists::class);

	return [
		'primary' => $reflection->getConstant('LISTS'),
		'verified' => $reflection->getConstant('VERIFIED_LISTS'),
		'supplement' => $reflection->getConstant('SUPPLEMENT_LISTS'),
	];
}


/**
 * Return the code lists of all three sources merged.
 *
 * @return array<string,array<string,string>>  Labels by code-list name and code.
 */
function mergedCodeLists(): array {
	$sources = codeListSources();
	$merged = $sources['primary'];

	foreach ([$sources['verified'], $sources['supplement']] as $lists) {
		foreach ($lists as $list => $codes) {
			foreach ($codes as $code => $label) {
				$merged[$list][$code] = $label;
			}
		}
	}

	return $merged;
}


// ----------------------------------------------------------------
// Addresses
// ----------------------------------------------------------------

test('parseAuctionAddress() removes a trailing m.fl. or mfl. from the lookup input and keeps it as a hint', function (): void {
	$parsed = call('parseAuctionAddress', 'Præstbrovej 318, 7950 Erslev m.fl.');
	check($parsed['lookupInput'] === 'Præstbrovej 318, 7950 Erslev', 'Trailing m.fl. is removed only from lookup input.');
	check($parsed['multiplePropertiesHint'] === true, 'Trailing m.fl. preserves the multiple-property hint.');
	check($parsed['street'] === 'Præstbrovej', 'Street is parsed from m.fl. input.');
	check($parsed['houseNumber'] === '318', 'House number is parsed from m.fl. input.');
	check($parsed['postcode'] === '7950', 'Postcode is parsed from m.fl. input.');

	$parsed = call('parseAuctionAddress', 'Præstbrovej 318, 7950 Erslev mfl.');
	check($parsed['lookupInput'] === 'Præstbrovej 318, 7950 Erslev', 'Trailing mfl. is removed only from lookup input.');
	check($parsed['multiplePropertiesHint'] === true, 'Trailing mfl. preserves the multiple-property hint.');
});

test('parseAuctionAddress() reads the floor and door of an owner apartment', function (): void {
	$parsed = call('parseAuctionAddress', 'Nørmarkvej 29, 2 22, 7600 Struer');

	check($parsed['street'] === 'Nørmarkvej', 'Owner-apartment street is parsed.');
	check($parsed['houseNumber'] === '29', 'Owner-apartment house number is parsed.');
	check(($parsed['unit']['floor'] ?? null) === '2', 'Owner-apartment floor is normalized.');
	check(($parsed['unit']['door'] ?? null) === '22', 'Owner-apartment door is normalized.');
});

test('parseAuctionAddress() keeps a street name with a space and a house number with a letter', function (): void {
	$parsed = call('parseAuctionAddress', 'Vads Dal 27A, 6973 Ørnhøj');

	check($parsed['street'] === 'Vads Dal', 'Street names containing spaces are parsed.');
	check($parsed['houseNumber'] === '27A', 'House-number suffix letters are preserved.');
	check($parsed['postcode'] === '6973', 'Special-case postcode is parsed.');
});

test('chooseStreet() prefers the exact name and corrects a one-letter typo when one street is clearly closest', function (): void {
	$streets = [
		['id_lokalId' => '00000000-0000-0000-0000-000000000001', 'vejnavn' => 'Præstbrovej'],
		['id_lokalId' => '00000000-0000-0000-0000-000000000002', 'vejnavn' => 'Dragstrupvej'],
		['id_lokalId' => '00000000-0000-0000-0000-000000000003', 'vejnavn' => 'Ansgarvej'],
	];

	$street = call('chooseStreet', 'Præstbrovej', $streets);
	check($street['status'] === 'exact', 'Exact street name wins deterministically.');
	check($street['matched'] === 'Præstbrovej', 'Exact street result exposes the matched official name.');

	$street = call('chooseStreet', 'Præstbrovei', $streets);
	check($street['status'] === 'fuzzy_unique', 'One-character street typo can resolve when the candidate is clearly unique.');
	check($street['matched'] === 'Præstbrovej', 'Fuzzy street result exposes the corrected official name.');
});

test('selectAddress() selects the one DAR address of the typed floor and door', function (): void {
	$selection = call('selectAddress', [
		['id_lokalId' => '00000000-0000-0000-0000-000000000011', 'etagebetegnelse' => '2', 'doerbetegnelse' => '21'],
		['id_lokalId' => '00000000-0000-0000-0000-000000000012', 'etagebetegnelse' => '2', 'doerbetegnelse' => '22'],
	], [
		'raw' => '2 22',
		'floor' => '2',
		'door' => '22',
	]);

	check($selection['status'] === 'unit_exact', 'Floor/door selects one exact DAR address.');
	check(($selection['selected']['id_lokalId'] ?? null) === '00000000-0000-0000-0000-000000000012', 'Correct unit address is selected.');
});


// ----------------------------------------------------------------
// Normalization
// ----------------------------------------------------------------

test('normalizeGround() labels the municipality and wastewater codes and keeps the order deadline', function (): void {
	$ground = call('normalizeGround', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000021',
		'kommunekode' => '0810',
		'gru022MedlemskabAfSpildevandsforsyning' => '2',
		'gru023PaabudVedrSpildevandsafledning' => '5',
		'gru024FristVedrSpildevandsafledning' => '2015-04-01T00:00:00Z',
	], 'property');

	check(($ground['municipalityLabel'] ?? null) === 'Brønderslev Kommune', 'Ground municipality code receives its bundled label.');
	check(($ground['wastewaterMembershipLabel'] ?? null) === 'Medlemskab af spildevandsforsyning', 'Ground wastewater membership receives its bundled label.');
	check(($ground['wastewaterOrderLabel'] ?? null) === 'Rensning skal forbedres til O', 'Ground wastewater order receives its bundled label.');
	check(($ground['wastewaterOrderDeadline'] ?? null) === '2015-04-01T00:00:00Z', 'Ground wastewater order deadline is normalized.');
});

test('normalizeBuilding() labels the wastewater codes and keeps the order deadline', function (): void {
	$building = call('normalizeBuilding', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000024',
		'byg123MedlemskabAfSpildevandsforsyning' => '2',
		'byg124PaabudVedrSpildevandsafledning' => '5',
		'byg125FristVedrSpildevandsafledning' => '2015-04-01T00:00:00Z',
	], 'property');

	check(($building['wastewaterMembershipLabel'] ?? null) === 'Medlemskab af spildevandsforsyning', 'Building wastewater membership receives its bundled label.');
	check(($building['wastewaterOrderLabel'] ?? null) === 'Rensning skal forbedres til O', 'Building wastewater order receives its bundled label.');
	check(($building['wastewaterOrderDeadline'] ?? null) === '2015-04-01T00:00:00Z', 'Building wastewater order deadline is normalized.');
});

test('normalizeUnit() keeps the flush-toilet and bathroom counts', function (): void {
	$unit = call('normalizeUnit', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000025',
		'enh065AntalVandskylledeToiletter' => 2,
		'enh066AntalBadevaerelser' => 1,
	], 'property');

	check(($unit['flushToiletCount'] ?? null) === 2, 'Unit flush-toilet count is normalized.');
	check(($unit['bathroomCount'] ?? null) === 1, 'Unit bathroom count is normalized.');
});

test('The ground, building and unit queries select the wastewater and sanitation fields', function (): void {
	check(\str_contains(call('groundFields'), 'gru024FristVedrSpildevandsafledning'), 'Ground query requests wastewater order deadline.');

	$buildingFields = call('buildingFields');
	check(\str_contains($buildingFields, 'byg124PaabudVedrSpildevandsafledning'), 'Building query requests wastewater order.');
	check(\str_contains($buildingFields, 'byg125FristVedrSpildevandsafledning'), 'Building query requests wastewater order deadline.');

	$unitFields = call('unitFields');
	check(\str_contains($unitFields, 'enh065AntalVandskylledeToiletter'), 'Unit query requests flush-toilet count.');
	check(\str_contains($unitFields, 'enh066AntalBadevaerelser'), 'Unit query requests bathroom count.');
});

test('normalizeFloor() and normalizeEntrance() label the floor type, the elevator and the house-number role', function (): void {
	$floor = call('normalizeFloor', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000022',
		'eta025Etagetype' => '0',
	], 'property');
	check(($floor['typeLabel'] ?? null) === 'Ikke tagetage', 'Floor type receives its bundled label.');

	$entrance = call('normalizeEntrance', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000023',
		'opg020Elevator' => '0',
		'opg021HusnummerFunktion' => '0',
	], 'property');
	check(($entrance['elevatorLabel'] ?? null) === 'Der er ikke elevator i opgangen', 'Entrance elevator receives its bundled label.');
	check(($entrance['houseNumberFunctionLabel'] ?? null) === 'Fastsat til denne', 'Entrance house-number role receives its bundled label.');
});


// ----------------------------------------------------------------
// Code lists
// ----------------------------------------------------------------

test('BbrCodeLists names the dates of its three sources', function (): void {
	check(BbrCodeLists::SNAPSHOT_DATE === '2026-09-30', 'Primary code-list snapshot date is explicit.');
	check(BbrCodeLists::VERIFIED_SOURCE_CHECK_DATE === '2026-10-01', 'Individually verified BBR Teknik source date is explicit.');
	check(BbrCodeLists::SUPPLEMENT_COVERAGE_DATE === '2026-02-24', 'Supplement coverage date is explicit and separate.');
});

test('BbrCodeLists::label() resolves codes of all three sources, deprecated codes included', function (): void {
	check(BbrCodeLists::label('Tagdaekningsmateriale', '5') === 'Tegl', 'Roof material code 5 resolves to Tegl.');
	check(BbrCodeLists::label('Tagdaekningsmateriale', '80') === '(UDFASES) Ingen', 'Deprecated roof material remains historically resolvable.');
	check(BbrCodeLists::label('Vandforsyning', '2') === 'Privat vandforsyningsanlæg', 'Water supply code 2 resolves.');
	check(BbrCodeLists::label('Afloebsforhold', '10') === 'Afløb til offentligt kloaksystem', 'Drainage code 10 resolves.');
	check(BbrCodeLists::label('MedlemsskabAfSplidevandforsyning', '2') === 'Medlemskab af spildevandsforsyning', 'Wastewater membership code 2 resolves.');
	check(BbrCodeLists::label('Rensningspaabud', '5') === 'Rensning skal forbedres til O', 'Wastewater improvement order code 5 resolves.');
	check(BbrCodeLists::label('Rensningspaabud', '6') === 'Skal tilsluttes spildevandsforsyningsselskab', 'Supplemented wastewater order code 6 resolves.');
	check(BbrCodeLists::label('Rensningspaabud', '7') === 'Skal tilsluttes separatkloakering', 'Supplemented wastewater order code 7 resolves.');
	check(BbrCodeLists::label('Varmeinstallation', '2') === 'Centralvarme med én fyringsenhed', 'Heating installation code 2 resolves.');
	check(BbrCodeLists::label('BygAnvendelse', '120') === 'Fritliggende enfamiliehus', 'Building application code 120 resolves.');
	check(BbrCodeLists::label('BygAnvendelse', '910') === 'Garage', 'Secondary building application code 910 resolves.');
	check(BbrCodeLists::label('EnhAnvendelse', '120') === 'Fritliggende enfamiliehus', 'Unit application code 120 resolves.');
	check(BbrCodeLists::label('Boligtype', '1') === 'Egentlig beboelseslejlighed med eget køkken', 'Housing type code 1 resolves.');
	check(BbrCodeLists::label('Toiletforhold', 'T') === 'Vandskyllende toilet i enheden', 'Toilet code T resolves.');
	check(BbrCodeLists::label('Badeforhold', 'V') === 'Badeværelse i enheden', 'Bath code V resolves.');
	check(BbrCodeLists::label('Koekkenforhold', 'E') === 'Eget køkken med afløb', 'Kitchen code E resolves.');
	check(BbrCodeLists::label('Kommunekode', '0810') === 'Brønderslev Kommune', 'Municipality code 0810 resolves.');
	check(BbrCodeLists::label('Kommunekode', '0615') === 'Horsens Kommune', 'Municipality code 0615 resolves.');
	check(BbrCodeLists::label('Klassifikation', '1110') === 'Tank', 'Technical classification 1110 resolves.');
	check(BbrCodeLists::label('Placering', '0') === 'Ukendt', 'Supplemented technical placement code 0 resolves.');
	check(BbrCodeLists::label('Placering', '2') === 'Over terræn, udendørs', 'Technical placement code 2 resolves.');
	check(BbrCodeLists::label('Placering', '3') === 'Indendørs', 'Technical placement code 3 resolves.');
	check(BbrCodeLists::label('Indhold', '12') === 'Fyringsgasolie', 'Tank content code 12 resolves.');
	check(BbrCodeLists::label('Driftstatus', '2') === 'Ikke i drift', 'Operating status code 2 resolves.');
	check(BbrCodeLists::label('Elevator', '0') === 'Der er ikke elevator i opgangen', 'Elevator code 0 resolves.');
	check(BbrCodeLists::label('Elevator', '1') === 'Der er elevator i opgangen', 'Elevator code 1 resolves.');
	check(BbrCodeLists::label('EtageType', '0') === 'Ikke tagetage', 'Primary floor type code 0 remains authoritative.');
	check(BbrCodeLists::label('EtageType', '1') === 'Tagetage', 'Floor type code 1 resolves.');
	check(BbrCodeLists::label('EtageType', '2') === 'Kælder', 'Supplemented floor type code 2 resolves.');
	check(BbrCodeLists::label('HusnummerRolle', '0') === 'Fastsat til denne', 'House-number role code 0 resolves.');
	check(BbrCodeLists::label('HusnummerRolle', '1') === 'Kun vejledende', 'House-number role code 1 resolves.');
	check(BbrCodeLists::label('Sloejfning', '1') === 'Tanken er afblændet', 'Tank decommission code 1 resolves.');
	check(BbrCodeLists::label('Livscyklus', '10') === 'Historisk', 'Lifecycle code 10 resolves.');
	check(BbrCodeLists::label('AsbestholdigtMateriale', '1') === 'Asbestholdigt ydervægsmateriale', 'Supplemented asbestos code list resolves.');
	check(BbrCodeLists::label('Stoerrelsesklasse', '1') === 'Under 6.000 l', 'Supplemented tank size class resolves.');
	check(BbrCodeLists::label('Udlejningsforhold', '1') === 'Udlejet', 'Supplemented rental-status code list resolves.');
	check(BbrCodeLists::label('KvalitetAfKoordinatsaet', '1') === 'Sikker geokodning', 'Supplemented coordinate-quality code list resolves.');
	check(BbrCodeLists::label('AdresseRolle', '0') === 'Fastsat til denne', 'Verified address role code 0 resolves.');
	check(BbrCodeLists::label('AdresseRolle', '1') === 'Kun vejledende', 'Verified address role code 1 resolves.');
	check(BbrCodeLists::label('BeregningsprincipForArealAfCarport', '1') === 'Carportareal er målt efter tagflade', 'Verified carport area principle code 1 resolves.');
	check(BbrCodeLists::label('BeregningsprincipForArealAfCarport', '2') === 'Carportarealet er målt ½ meter inde på åbne sider', 'Verified carport area principle code 2 resolves.');
	check(BbrCodeLists::label('Konstruktion', '1') === 'Åben konstruktion', 'Verified construction code 1 resolves.');
	check(BbrCodeLists::label('Konstruktion', '2') === 'Lukket konstruktion', 'Verified construction code 2 resolves.');
	check(BbrCodeLists::label('Konstruktionsforhold', '1') === 'Bygningen har jernbetonskelet', 'Verified construction condition code 1 resolves.');
	check(BbrCodeLists::label('Konstruktionsforhold', '2') === 'Bygningen har ikke jernbetonskelet', 'Verified construction condition code 2 resolves.');
	check(BbrCodeLists::label('Materiale', '1') === 'Plast', 'Verified technical material code 1 resolves.');
	check(BbrCodeLists::label('Materiale', '2') === 'Stål', 'Verified technical material code 2 resolves.');
	check(BbrCodeLists::label('Materiale', '3') === 'Plasttank med udvendig stålvæg', 'Verified technical material code 3 resolves.');
	check(BbrCodeLists::label('TypeAfVaegge', '1') === 'Enkeltvægget', 'Verified wall type code 1 resolves.');
	check(BbrCodeLists::label('TypeAfVaegge', '2') === 'Dobbeltvægget', 'Verified wall type code 2 resolves.');
	check(BbrCodeLists::label('TypeAfVaegge', '3') === 'Dobbeltvægget med overvågning', 'Verified wall type code 3 resolves.');
	check(BbrCodeLists::label('TypeAfVaegge', '4') === 'Overjordisk anlæg, hele anlægget er tilgængeligt for udvendig visuel inspektion', 'Verified wall type code 4 resolves.');
	check(BbrCodeLists::label('TypeAfVaegge', '5') === 'Tanke som er installeret før 1970, udvendig korrosionsbeskyttelse med bitumenbelægning', 'Verified wall type code 5 resolves.');
});

test('BbrCodeLists::label() returns null for an unknown list, an unknown code and a null code', function (): void {
	check(BbrCodeLists::label('UnknownList', '1') === null, 'Unknown code-list names safely return null.');
	check(BbrCodeLists::label('Tagdaekningsmateriale', '9999') === null, 'Unknown code values safely return null.');
	check(BbrCodeLists::label('Tagdaekningsmateriale', null) === null, 'Null code values remain null.');
});

test('Each list/code pair of the bundled code lists is held by one source only', function (): void {
	$sources = codeListSources();
	check(\is_array($sources['primary']), 'Primary code-list snapshot remains an array.');
	check(\is_array($sources['verified']), 'Individually verified code lists are an array.');
	check(\is_array($sources['supplement']), 'Supplement code-list snapshot is an array.');

	$verifiedOverlap = [];
	foreach ($sources['verified'] as $list => $codes) {
		foreach (\array_keys($codes) as $code) {
			if ((isset($sources['primary'][$list]) && \array_key_exists($code, $sources['primary'][$list]))
				|| (isset($sources['supplement'][$list]) && \array_key_exists($code, $sources['supplement'][$list]))) {
				$verifiedOverlap[] = $list . ':' . $code;
			}
		}
	}

	$supplementOverlap = [];
	foreach ($sources['supplement'] as $list => $codes) {
		foreach (\array_keys($codes) as $code) {
			if (isset($sources['primary'][$list]) && \array_key_exists($code, $sources['primary'][$list])) {
				$supplementOverlap[] = $list . ':' . $code;
			}
		}
	}

	check($verifiedOverlap === [], 'Individually verified lists do not duplicate primary or KDS supplement list/code pairs.');
	check($supplementOverlap === [], 'Older supplement contains no list/code pair from the primary snapshot.');
});

test('The bundled code lists hold the expected numbers of lists and codes', function (): void {
	$sources = codeListSources();
	$count = static fn(array $lists): int => \array_sum(\array_map('count', $lists));
	$merged = mergedCodeLists();

	check(\count($sources['verified']) === 6, 'Individually verified source covers the expected 6 code lists.');
	check($count($sources['verified']) === 16, 'Individually verified source covers the expected 16 list/code pairs.');
	check(\count($sources['supplement']) === 45, 'Supplement contains the expected 45 affected code lists.');
	check($count($sources['supplement']) === 363, 'Supplement contains the expected 363 missing list/code pairs.');
	check(\count($merged) === 78, 'Bundled lookup covers 78 code lists across all sources.');
	check($count($merged) === 954, 'Bundled lookup covers 954 unique list/code pairs across all sources.');
});

test('Every code list that Bbr and BbrFieldCatalog reference is bundled', function (): void {
	$bbrSource = packageFile('src/Service/Bbr.php');
	$fieldCatalogSource = packageFile('src/Util/BbrFieldCatalog.php');
	check($bbrSource !== false, 'BBR service source is readable for code-list usage coverage.');
	check($fieldCatalogSource !== false, 'BBR field catalog source is readable for code-list usage coverage.');

	\preg_match_all("/codeLabel\\(\\s*'([^']+)'/", (string)$bbrSource, $serviceMatches);
	\preg_match_all("/'codeList' => '([^']+)'/", (string)$fieldCatalogSource, $catalogMatches);
	$referencedLists = \array_values(\array_unique(\array_merge($serviceMatches[1] ?? [], $catalogMatches[1] ?? [])));
	\sort($referencedLists, \SORT_STRING);

	$availableLists = \array_fill_keys(\array_keys(mergedCodeLists()), true);
	$missingReferencedLists = \array_values(\array_filter(
		$referencedLists,
		static fn(string $list): bool => !isset($availableLists[$list])
	));

	check($missingReferencedLists === [], 'Every code list referenced by BBR normalization is bundled.');
});


// ----------------------------------------------------------------
// Field coverage
// ----------------------------------------------------------------

test('Each BBR entity query selects every field of its BBR v3 type once, apart from the internal ones', function (): void {
	$schema = packageFile('resources/bbr/BBR_V003.schema.graphql');
	$bbrSource = packageFile('src/Service/Bbr.php');
	check($schema !== false, 'Bundled BBR v3 schema is readable.');
	check($bbrSource !== false, 'BBR service source is readable.');

	foreach (ENTITIES as $definition) {
		$expectedSelection = \array_values(\array_diff(\array_keys(schemaFields((string)$schema, $definition['type'])), INTERNAL_FIELDS));
		\sort($expectedSelection, \SORT_STRING);

		check(selectionFields((string)$bbrSource, $definition['selection']) === $expectedSelection, $definition['type'] . ' query selects every non-internal BBR v3 field exactly once.');
	}
});

test('Each BBR entity query selects only fields that the flexibleCurrent v3 type has', function (): void {
	$currentSchema = packageFile('resources/bbr/FLEX_CURRENT_V003.schema.graphql');
	$bbrSource = packageFile('src/Service/Bbr.php');
	check($currentSchema !== false, 'Bundled FLEX_CURRENT v3 schema is readable.');

	foreach (ENTITIES as $definition) {
		$missingFromCurrent = \array_values(\array_diff(
			selectionFields((string)$bbrSource, $definition['selection']),
			\array_keys(schemaFields((string)$currentSchema, $definition['type']))
		));

		check($missingFromCurrent === [], $definition['type'] . ' BBR selection is valid in FLEX_CURRENT v3.');
	}
});

test('Each BBR domain field is read by its normalizer or kept by BbrFieldCatalog, never both', function (): void {
	$schema = (string)packageFile('resources/bbr/BBR_V003.schema.graphql');
	$bbrSource = (string)packageFile('src/Service/Bbr.php');
	$catalog = (new \ReflectionClass(BbrFieldCatalog::class))->getConstant('ADDITIONAL_FIELDS');
	check(\is_array($catalog), 'Additional-field catalog is an array.');

	foreach (ENTITIES as $entity => $definition) {
		$domainFields = \array_values(\array_filter(
			\array_keys(schemaFields($schema, $definition['type'])),
			static fn(string $field): bool => \str_starts_with($field, $definition['prefix'])
		));
		\sort($domainFields, \SORT_STRING);

		$explicitFields = \array_values(\array_filter(
			normalizerRefs($bbrSource, $definition['normalizer']),
			static fn(string $field): bool => \str_starts_with($field, $definition['prefix'])
		));
		\sort($explicitFields, \SORT_STRING);

		$catalogFields = \array_keys($catalog[$entity] ?? []);
		\sort($catalogFields, \SORT_STRING);

		$overlap = \array_values(\array_intersect($explicitFields, $catalogFields));
		check($overlap === [], $entity . ' additional-field catalog does not duplicate dedicated normalized fields.');

		$accounted = \array_values(\array_unique(\array_merge($explicitFields, $catalogFields)));
		\sort($accounted, \SORT_STRING);
		check($accounted === $domainFields, $entity . ' has zero unaccounted BBR domain fields.');
	}
});

test('BbrFieldCatalog keeps 0, labels a coded value, keeps a coordinate and leaves out null', function (): void {
	$additional = BbrFieldCatalog::normalizeAdditionalFields('building', [
		'byg046SamletArealAfLukkedeOverdaekningerPaaBygningen' => 0,
		'byg152AabenLukketKonstruktion' => '1',
		'byg404Koordinat' => ['crs' => 25832, 'wkt' => 'POINT (1 2)'],
		'byg403OevrigeBemaerkningerFraStormraadet' => null,
	]);

	check(\count($additional) === 3, 'Additional-field normalization omits null values but preserves meaningful values.');
	check(($additional[0]['value'] ?? null) === 0, 'Additional-field normalization preserves numeric zero.');
	check(($additional[1]['valueLabel'] ?? null) === 'Åben konstruktion', 'Additional coded field receives its bundled label.');
	check(($additional[2]['value']['crs'] ?? null) === 25832, 'Additional coordinate preserves CRS.');
	check(($additional[2]['value']['wkt'] ?? null) === 'POINT (1 2)', 'Additional coordinate preserves WKT.');
});

test('BbrFieldCatalog rejects an unknown entity', function (): void {
	throws(\LogicException::class, fn() => BbrFieldCatalog::normalizeAdditionalFields('unknown', []));
});

done();
