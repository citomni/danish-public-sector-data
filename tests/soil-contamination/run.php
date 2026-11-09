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

use CitOmni\DanishPublicSectorData\Enum\SoilContaminationStatus;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Service\SoilContamination;
use CitOmni\DanishPublicSectorData\Support\DkJordWfsClient;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;

/*
 * Isolated suite for the soilContamination service: How it reads the DKjord locality layer into
 * parcel statuses, picks the current Matriklen object, and builds its WFS projection and
 * Matriklen queries. The app double registers no curl service, so no request can be sent.
 *
 * Usage:
 *   php tests/soil-contamination/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Ejerlav code of the cases. */
const DISTRICT = 2005352;

/** Lokalitetsmatrikler of one locality: 4bz repeats inside it. */
const MAIN_PARCELS = '311a,V2 kortlagt;311b,V2 kortlagt;301a,V1 kortlagt;4ca,V1 og V2 kortlagt;4bz,V1 og V2 kortlagt;4bz,V1 og V2 kortlagt;312,V2 kortlagt';

/** Effect time of the query cases. */
const EFFECT_TIME = '2026-09-30T10:00:00Z';


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
	$service ??= new SoilContamination(new App(['danish_public_sector_data' => [
		'soil_contamination' => [
			'matrikel_service' => 'flexibleCurrent',
			'matrikel_version' => 'v3',
		],
		'datafordeler' => [
			'base_url'        => 'https://graphql.datafordeler.dk',
			'timeout'         => 15,
			'connect_timeout' => 5,
		],
		'dkjord_wfs' => [
			'base_url'        => 'https://jord.miljoeportal.dk/geo/wfs',
			'timeout'         => 15,
			'connect_timeout' => 5,
		],
	]]));

	return (new \ReflectionMethod(SoilContamination::class, $method))->invoke($service, ...$arguments);
}


/**
 * Build a GeoJSON feature of the DKjord locality layer.
 *
 * @param  string  $locationReference  Lokalitetsnr.
 * @param  string|null  $locationName  Lokalitetsnavn.
 * @param  int  $district  Lokalitetsejerlavkode.
 * @param  string|null  $parcels  Lokalitetsmatrikler.
 * @return array<string,mixed>
 */
function feature(string $locationReference, ?string $locationName, int $district, ?string $parcels): array {
	return [
		'type' => 'Feature',
		'properties' => [
			'Lokalitetsnr' => $locationReference,
			'Lokalitetsnavn' => $locationName,
			'Lokalitetsejerlavkode' => $district,
			'Lokalitetsmatrikler' => $parcels,
		],
	];
}


/**
 * Return the status of one parcel of the localities that most cases share.
 *
 * The same locality arrives in two features with the same list, and a parcel is listed by
 * several localities.
 *
 * @param  string  $parcel  Matrikelnummer.
 * @return array<string,mixed>
 */
function status(string $parcel): array {
	$parcels = call('parseLocalities', DISTRICT, [
		feature('851-00047', 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej', DISTRICT, MAIN_PARCELS),
		feature('851-00047', 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej', DISTRICT, MAIN_PARCELS),
		feature('851-00470', null, DISTRICT, '7000e,Udgået efter kortlægning;4bz,V1 og V2 kortlagt'),
		feature('851-01234', 'Tidligere tankstation', DISTRICT, ' 1ee , Lokaliseret (uafklaret) ;; 2e,Udgået inden kortlægning;'),
	]);

	return call('normalizeParcelStatus', DISTRICT, $parcel, $parcels);
}


// ----------------------------------------------------------------
// Parcel status
// ----------------------------------------------------------------

test('A listed parcel gets the status that DKjord registers on it, its text and its locality once', function (): void {
	$parcel = status('311a');

	check($parcel['cadastralDistrictIdentifier'] === DISTRICT, 'Cadastral district is preserved.');
	check($parcel['landParcelIdentifier'] === '311a', 'Exact land parcel identifier is preserved.');
	check($parcel['hasDkJordMatch'] === true, 'A listed parcel is reported as a DKjord match.');
	check($parcel['status'] === SoilContaminationStatus::V2->value, 'V2 kortlagt becomes the v2 status.');
	check($parcel['statusText'] === 'V2 kortlagt', 'DKjord status text is preserved.');
	check(
		$parcel['locations'] === [['locationReference' => '851-00047', 'locationName' => 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej']],
		'Repeated features of one locality collapse into one location.'
	);
});

test('A parcel in several localities has one status and lists each locality once, ordered by number', function (): void {
	$parcel = status('4bz');

	check($parcel['status'] === SoilContaminationStatus::V1AndV2->value, 'V1 og V2 kortlagt becomes one v1_v2 status, not two.');
	check(
		\array_column($parcel['locations'], 'locationReference') === ['851-00047', '851-00470'],
		'A parcel in several localities lists each locality once, ordered by number.'
	);
	check($parcel['locations'][1]['locationName'] === null, 'A locality without a name keeps a null name.');
});

test('Each DKjord status text becomes its status, also in a trimmed entry and for a numeric matrikelnummer', function (): void {
	check(status('301a')['status'] === SoilContaminationStatus::V1->value, 'V1 kortlagt becomes the v1 status.');
	check(status('7000e')['status'] === SoilContaminationStatus::RemovedAfterMapping->value, 'Udgået efter kortlægning becomes removed_after_mapping.');
	check(status('2e')['status'] === SoilContaminationStatus::RemovedBeforeMapping->value, 'Udgået inden kortlægning becomes removed_before_mapping.');
	check(status('1ee')['status'] === SoilContaminationStatus::Localized->value, 'Entries are trimmed and empty entries are skipped.');
	check(status('312')['status'] === SoilContaminationStatus::V2->value, 'A numeric matrikelnummer is matched as well.');
});

test('A parcel that no locality lists has no match, no status and no locations; 311 does not match 311a', function (): void {
	$parcel = status('311');

	check($parcel['hasDkJordMatch'] === false, 'Parcel matching does not use substring semantics.');
	check($parcel['status'] === null && $parcel['statusText'] === null, 'An unlisted parcel has no status.');
	check($parcel['locations'] === [], 'An unlisted parcel has no locations.');
});

test('A parcel with two statuses or an unknown status text is rejected, and the other parcels keep theirs', function (): void {
	$conflicting = call('parseLocalities', DISTRICT, [
		feature('851-00001', null, DISTRICT, '10a,V1 kortlagt;10b,V2 kortlagt'),
		feature('851-00002', null, DISTRICT, '10a,V2 kortlagt'),
	]);
	throws(InvalidResponseException::class, fn() => call('normalizeParcelStatus', DISTRICT, '10a', $conflicting));
	check(
		call('normalizeParcelStatus', DISTRICT, '10b', $conflicting)['status'] === SoilContaminationStatus::V2->value,
		'A conflict on one parcel does not affect the other parcels of the ejerlav.'
	);

	$unknown = call('parseLocalities', DISTRICT, [feature('851-00003', null, DISTRICT, '11a,V3 kortlagt')]);
	throws(InvalidResponseException::class, fn() => call('normalizeParcelStatus', DISTRICT, '11a', $unknown));
});

test('parseLocalities() rejects a malformed entry, a locality outside the ejerlav and a feature without properties', function (): void {
	foreach (['311a', ',V2 kortlagt', '311a,', ' , '] as $entry) {
		throws(InvalidResponseException::class, fn() => call('parseLocalities', DISTRICT, [feature('851-00004', null, DISTRICT, $entry)]));
	}
	throws(InvalidResponseException::class, fn() => call('parseLocalities', DISTRICT, [feature('851-00005', null, DISTRICT + 1, '1a,V1 kortlagt')]));
	throws(InvalidResponseException::class, fn() => call('parseLocalities', DISTRICT, [['type' => 'Feature']]));

	check(call('parseLocalities', DISTRICT, [feature('851-00006', null, DISTRICT, null)]) === [], 'A locality without parcels adds nothing.');
});

test('Every status text seen in DKjord data is known, and every SoilContaminationStatus has a text', function (): void {
	$statusTexts = (new \ReflectionClass(SoilContamination::class))->getConstant('DKJORD_STATUS_TEXTS');

	foreach (['V1 kortlagt', 'V2 kortlagt', 'V1 og V2 kortlagt', 'Lokaliseret (uafklaret)', 'Udgået efter kortlægning', 'Udgået inden kortlægning'] as $text) {
		check(isset($statusTexts[$text]), 'Status text seen in DKjord WFS data is known: ' . $text . '.');
	}
	check(
		\count(\array_unique(\array_map(static fn(SoilContaminationStatus $value): string => $value->value, $statusTexts))) === \count(SoilContaminationStatus::cases()),
		'Every SoilContaminationStatus case has at least one DKjord status text.'
	);
});

test('orderedStatuses() follows the SoilContaminationStatus case order', function (): void {
	check(
		call('orderedStatuses', ['removed_after_mapping' => true, 'v1_v2' => true, 'v1' => true]) === ['v1', 'v1_v2', 'removed_after_mapping'],
		'Property statuses follow SoilContaminationStatus case order.'
	);
});

test('splitSemicolonList() trims each value, skips empty ones and turns null into an empty list', function (): void {
	check(call('splitSemicolonList', ' 4bz ; 301a;; ') === ['4bz', '301a'], 'Semicolon lists are trimmed without fuzzy matching.');
	check(call('splitSemicolonList', null) === [], 'Null semicolon lists normalize to an empty list.');
});


// ----------------------------------------------------------------
// Matriklen
// ----------------------------------------------------------------

test('isCurrentStatus() accepts Gældende in any letter case and rejects Foreløbig', function (): void {
	check(call('isCurrentStatus', 'GÆLDENDE') === true, 'Uppercase current Matriklen status is accepted.');
	check(call('isCurrentStatus', 'Gældende') === true, 'Mixed-case current Matriklen status is accepted.');
	check(call('isCurrentStatus', 'Foreløbig') === false, 'Non-current Matriklen status is rejected.');
});

test('currentNode() ignores a preliminary version next to the current one and counts identical nodes once', function (): void {
	// A pending cadastral case adds a preliminary version of the property next to the current one.
	$current = ['id_lokalId' => 'sfe-1', 'status' => 'Gældende'];
	$preliminary = ['id_lokalId' => 'sfe-1', 'status' => 'Foreløbig'];

	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', []) === null, 'A lookup without nodes has no current node.');
	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current]) === $current, 'One current node is returned.');
	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$preliminary, $current]) === $current, 'A preliminary version next to the current one is ignored.');
	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$preliminary]) === null, 'A preliminary version alone is no current node.');
	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, $current]) === $current, 'Identical current nodes count once.');
	check(call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [['id_lokalId' => 'sfe-2']]) === ['id_lokalId' => 'sfe-2'], 'A node without status counts as current.');
});

test('currentNode() rejects two different current nodes and names the lookup and the statuses of all nodes', function (): void {
	$current = ['id_lokalId' => 'sfe-1', 'status' => 'Gældende'];
	$preliminary = ['id_lokalId' => 'sfe-1', 'status' => 'Foreløbig'];

	throws(InvalidResponseException::class, fn() => call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, ['id_lokalId' => 'sfe-2', 'status' => 'Gældende']]));

	$error = throws(InvalidResponseException::class, fn() => call('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, $preliminary, ['id_lokalId' => 'sfe-1', 'status' => 'Gældende', 'samletFastEjendomLokalId' => 'x']]));
	check(
		$error->getMessage() === 'Datafordeler returned 2 current MAT_SamletFastEjendom nodes for BFE 1 (statuses of all nodes: Gældende, Foreløbig, Gældende).',
		'The ambiguity message names the lookup and the statuses of all nodes.'
	);
});

test('getParcelsByBfe() is public, so a BFE number resolves to parcels without DKjord', function (): void {
	check((new \ReflectionMethod(SoilContamination::class, 'getParcelsByBfe'))->isPublic(), 'Pure BFE-to-parcel resolution is exposed without requiring DKjord WFS.');
});


// ----------------------------------------------------------------
// Requests
// ----------------------------------------------------------------

test('The locality request names only the properties that the lookup reads', function (): void {
	check(
		(new \ReflectionClass(SoilContamination::class))->getConstant('DKJORD_LOCALITY_PROPERTIES') === ['Lokalitetsnr', 'Lokalitetsnavn', 'Lokalitetsejerlavkode', 'Lokalitetsmatrikler'],
		'The locality request names only the properties the lookup reads.'
	);
});

test('The WFS projection joins the property names in order and rejects an empty list or an invalid name', function (): void {
	$buildPropertyNames = new \ReflectionMethod(DkJordWfsClient::class, 'buildPropertyNames');

	check(
		$buildPropertyNames->invoke(null, ['Lokalitetsnr', 'Lokalitetsmatrikler']) === 'Lokalitetsnr,Lokalitetsmatrikler',
		'WFS projection joins the requested property names in order.'
	);
	foreach ([[], ['Lokalitetsnr,GUID'], ['Lokalitets nr'], [42]] as $names) {
		throws(\InvalidArgumentException::class, fn() => $buildPropertyNames->invoke(null, $names));
	}
});

test('The BFE lookups of Matriklen are exact, carry the effect time and select the status of each node', function (): void {
	$query = call('buildSfeByBfeQuery', 4268969, EFFECT_TIME, null);
	check(\str_contains($query, 'MAT_SamletFastEjendom('), 'BFE lookup targets Matriklen SFE.');
	check(\str_contains($query, 'BFEnummer: { eq: 4268969 }'), 'BFE lookup is exact.');
	check(\str_contains($query, 'virkningstid: "' . EFFECT_TIME . '"'), 'BFE lookup carries an explicit effect time.');

	foreach (['buildSfeByBfeQuery', 'buildBpfgFladeByBfeQuery', 'buildBpfgPunktByBfeQuery', 'buildOwnerApartmentByBfeQuery'] as $builder) {
		check(\preg_match('/nodes \{\s+id_lokalId\s+status\s/', call($builder, 4268969, EFFECT_TIME, null)) === 1, $builder . ' selects the status of each node.');
	}
	foreach (['buildBpfgFladeByIdQuery', 'buildBpfgPunktByIdQuery'] as $builder) {
		check(\preg_match('/nodes \{\s+id_lokalId\s+status\s/', call($builder, 'id-1', EFFECT_TIME, null)) === 1, $builder . ' selects the status of each node.');
	}
});

test('The parcel lookup of Matriklen filters on the exact SFE id with the page size', function (): void {
	$query = call('buildParcelsBySfeQuery', '11111111-1111-1111-1111-111111111111', EFFECT_TIME, null);

	check(\str_contains($query, 'MAT_Jordstykke('), 'Parcel lookup targets Matriklen parcels.');
	check(\str_contains($query, 'first: 1000'), 'Parcel lookup uses the configured page size.');
	check(\str_contains($query, 'samletFastEjendomLokalId: { eq: "11111111-1111-1111-1111-111111111111" }'), 'Parcel lookup scopes by exact SFE id.');
});

done();
