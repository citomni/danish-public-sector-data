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

/*
 * This provider package intentionally has no package-local Composer vendor tree.
 * The parser test therefore supplies only the minimal CitOmni base-service
 * contract required to load the real production Bbr class.
 */
namespace CitOmni\Kernel\Service {
	abstract class BaseService {
	}
}

namespace {
	use CitOmni\DanishPublicSectorData\Service\Bbr;

	$root = \dirname(__DIR__);

	require_once $root . '/src/Exception/PublicDataException.php';
	require_once $root . '/src/Exception/InvalidResponseException.php';
	require_once $root . '/src/Util/BbrCodeLists.php';
	require_once $root . '/src/Util/BbrFieldCatalog.php';
	require_once $root . '/src/Service/Bbr.php';

	$service = (new \ReflectionClass(Bbr::class))->newInstanceWithoutConstructor();
	$checks = 0;

	$invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
		return (new \ReflectionMethod(Bbr::class, $method))->invoke($service, ...$arguments);
	};

	$check = static function (bool $condition, string $message) use (&$checks): void {
		if (!$condition) {
			throw new \RuntimeException('FAIL: ' . $message);
		}
		++$checks;
	};

	$parsed = $invoke('parseAuctionAddress', 'Præstbrovej 318, 7950 Erslev m.fl.');
	$check($parsed['lookupInput'] === 'Præstbrovej 318, 7950 Erslev', 'Trailing m.fl. is removed only from lookup input.');
	$check($parsed['multiplePropertiesHint'] === true, 'Trailing m.fl. preserves the multiple-property hint.');
	$check($parsed['street'] === 'Præstbrovej', 'Street is parsed from m.fl. input.');
	$check($parsed['houseNumber'] === '318', 'House number is parsed from m.fl. input.');
	$check($parsed['postcode'] === '7950', 'Postcode is parsed from m.fl. input.');

	$parsed = $invoke('parseAuctionAddress', 'Præstbrovej 318, 7950 Erslev mfl.');
	$check($parsed['lookupInput'] === 'Præstbrovej 318, 7950 Erslev', 'Trailing mfl. is removed only from lookup input.');
	$check($parsed['multiplePropertiesHint'] === true, 'Trailing mfl. preserves the multiple-property hint.');

	$parsed = $invoke('parseAuctionAddress', 'Nørmarkvej 29, 2 22, 7600 Struer');
	$check($parsed['street'] === 'Nørmarkvej', 'Owner-apartment street is parsed.');
	$check($parsed['houseNumber'] === '29', 'Owner-apartment house number is parsed.');
	$check(($parsed['unit']['floor'] ?? null) === '2', 'Owner-apartment floor is normalized.');
	$check(($parsed['unit']['door'] ?? null) === '22', 'Owner-apartment door is normalized.');

	$parsed = $invoke('parseAuctionAddress', 'Vads Dal 27A, 6973 Ørnhøj');
	$check($parsed['street'] === 'Vads Dal', 'Street names containing spaces are parsed.');
	$check($parsed['houseNumber'] === '27A', 'House-number suffix letters are preserved.');
	$check($parsed['postcode'] === '6973', 'Special-case postcode is parsed.');

	$streetRows = [
		[
			'id_lokalId' => '00000000-0000-0000-0000-000000000001',
			'vejnavn' => 'Præstbrovej',
		],
		[
			'id_lokalId' => '00000000-0000-0000-0000-000000000002',
			'vejnavn' => 'Dragstrupvej',
		],
		[
			'id_lokalId' => '00000000-0000-0000-0000-000000000003',
			'vejnavn' => 'Ansgarvej',
		],
	];

	$street = $invoke('chooseStreet', 'Præstbrovej', $streetRows);
	$check($street['status'] === 'exact', 'Exact street name wins deterministically.');
	$check($street['matched'] === 'Præstbrovej', 'Exact street result exposes the matched official name.');

	$street = $invoke('chooseStreet', 'Præstbrovei', $streetRows);
	$check($street['status'] === 'fuzzy_unique', 'One-character street typo can resolve when the candidate is clearly unique.');
	$check($street['matched'] === 'Præstbrovej', 'Fuzzy street result exposes the corrected official name.');

	$addresses = [
		[
			'id_lokalId' => '00000000-0000-0000-0000-000000000011',
			'etagebetegnelse' => '2',
			'doerbetegnelse' => '21',
		],
		[
			'id_lokalId' => '00000000-0000-0000-0000-000000000012',
			'etagebetegnelse' => '2',
			'doerbetegnelse' => '22',
		],
	];

	$selection = $invoke('selectAddress', $addresses, [
		'raw' => '2 22',
		'floor' => '2',
		'door' => '22',
	]);
	$check($selection['status'] === 'unit_exact', 'Floor/door selects one exact DAR address.');
	$check(($selection['selected']['id_lokalId'] ?? null) === '00000000-0000-0000-0000-000000000012', 'Correct unit address is selected.');


	$ground = $invoke('normalizeGround', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000021',
		'kommunekode' => '0810',
		'gru022MedlemskabAfSpildevandsforsyning' => '2',
		'gru023PaabudVedrSpildevandsafledning' => '5',
		'gru024FristVedrSpildevandsafledning' => '2015-04-01T00:00:00Z',
	], 'property');
	$check(($ground['municipalityLabel'] ?? null) === 'Brønderslev Kommune', 'Ground municipality code receives its bundled label.');
	$check(($ground['wastewaterMembershipLabel'] ?? null) === 'Medlemskab af spildevandsforsyning', 'Ground wastewater membership receives its bundled label.');
	$check(($ground['wastewaterOrderLabel'] ?? null) === 'Rensning skal forbedres til O', 'Ground wastewater order receives its bundled label.');
	$check(($ground['wastewaterOrderDeadline'] ?? null) === '2015-04-01T00:00:00Z', 'Ground wastewater order deadline is normalized.');

	$building = $invoke('normalizeBuilding', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000024',
		'byg123MedlemskabAfSpildevandsforsyning' => '2',
		'byg124PaabudVedrSpildevandsafledning' => '5',
		'byg125FristVedrSpildevandsafledning' => '2015-04-01T00:00:00Z',
	], 'property');
	$check(($building['wastewaterMembershipLabel'] ?? null) === 'Medlemskab af spildevandsforsyning', 'Building wastewater membership receives its bundled label.');
	$check(($building['wastewaterOrderLabel'] ?? null) === 'Rensning skal forbedres til O', 'Building wastewater order receives its bundled label.');
	$check(($building['wastewaterOrderDeadline'] ?? null) === '2015-04-01T00:00:00Z', 'Building wastewater order deadline is normalized.');

	$unit = $invoke('normalizeUnit', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000025',
		'enh065AntalVandskylledeToiletter' => 2,
		'enh066AntalBadevaerelser' => 1,
	], 'property');
	$check(($unit['flushToiletCount'] ?? null) === 2, 'Unit flush-toilet count is normalized.');
	$check(($unit['bathroomCount'] ?? null) === 1, 'Unit bathroom count is normalized.');

	$groundFields = $invoke('groundFields');
	$check(\str_contains($groundFields, 'gru024FristVedrSpildevandsafledning'), 'Ground query requests wastewater order deadline.');

	$buildingFields = $invoke('buildingFields');
	$check(\str_contains($buildingFields, 'byg124PaabudVedrSpildevandsafledning'), 'Building query requests wastewater order.');
	$check(\str_contains($buildingFields, 'byg125FristVedrSpildevandsafledning'), 'Building query requests wastewater order deadline.');

	$unitFields = $invoke('unitFields');
	$check(\str_contains($unitFields, 'enh065AntalVandskylledeToiletter'), 'Unit query requests flush-toilet count.');
	$check(\str_contains($unitFields, 'enh066AntalBadevaerelser'), 'Unit query requests bathroom count.');

	$floor = $invoke('normalizeFloor', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000022',
		'eta025Etagetype' => '0',
	], 'property');
	$check(($floor['typeLabel'] ?? null) === 'Ikke tagetage', 'Floor type receives its bundled label.');

	$entrance = $invoke('normalizeEntrance', [
		'id_lokalId' => '00000000-0000-0000-0000-000000000023',
		'opg020Elevator' => '0',
		'opg021HusnummerFunktion' => '0',
	], 'property');
	$check(($entrance['elevatorLabel'] ?? null) === 'Der er ikke elevator i opgangen', 'Entrance elevator receives its bundled label.');
	$check(($entrance['houseNumberFunctionLabel'] ?? null) === 'Fastsat til denne', 'Entrance house-number role receives its bundled label.');

	\fwrite(
		STDOUT,
		'PASS ' . $checks . ' checks. BBR auction address parsing and deterministic street matching. PHP '
		. \PHP_VERSION . '.' . \PHP_EOL
	);
}
