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
use CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;

/*
 * Isolated suite for resolveAddress() of the bbr service: How it finds the property of a typed
 * address through the buildings of its house number, and through the buildings that the house
 * number gives access to when those give none, against a scripted transport. No network request
 * is made. Every query document that is sent is also checked against the bundled
 * flexibleCurrent v3 schema.
 *
 * Usage:
 *   php tests/bbr-address-resolution/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Address that every case resolves. */
const TYPED_ADDRESS = 'Eksempelvej 10L, 7200 Grindsted';

/** Ids of the objects in the answers, one UUID per name. */
const IDS = [
	'postcode'        => 'a0000000-0000-4000-8000-000000000001',
	'street'          => 'a0000000-0000-4000-8000-000000000002',
	'street-postcode' => 'a0000000-0000-4000-8000-000000000003',
	'house'           => 'a0000000-0000-4000-8000-000000000004',
	'address'         => 'a0000000-0000-4000-8000-000000000005',
	'address-2'       => 'a0000000-0000-4000-8000-000000000006',
	'building'        => 'b0000000-0000-4000-8000-000000000001',
	'building-2'      => 'b0000000-0000-4000-8000-000000000002',
	'own-building'    => 'b0000000-0000-4000-8000-000000000003',
	'entrance'        => 'c0000000-0000-4000-8000-000000000001',
	'unit'            => 'd0000000-0000-4000-8000-000000000001',
	'unit-2'          => 'd0000000-0000-4000-8000-000000000002',
	'unit-relation'   => 'd0000000-0000-4000-8000-000000000003',
	'unit-relation-2' => 'd0000000-0000-4000-8000-000000000004',
	'ground'          => 'e0000000-0000-4000-8000-000000000001',
	'ground-2'        => 'e0000000-0000-4000-8000-000000000002',
	'own-ground'      => 'e0000000-0000-4000-8000-000000000003',
	'property'        => 'f0000000-0000-4000-8000-000000000001',
	'property-2'      => 'f0000000-0000-4000-8000-000000000002',
	'own-property'    => 'f0000000-0000-4000-8000-000000000003',
	'apartment'       => 'f0000000-0000-4000-8000-000000000004',
	'apartment-2'     => 'f0000000-0000-4000-8000-000000000005',
];


// ----------------------------------------------------------------
// Transport, secrets and service
// ----------------------------------------------------------------

/**
 * Return the scripted transport that every case shares.
 *
 * It answers each request with the first scripted answer for the root connection of its query
 * document, and keeps the query document of every request for the schema case.
 *
 * @return object
 */
function transport(): object {
	static $curl = null;

	return $curl ??= new class {
		/** @var list<array<string,mixed>> Requests of the current case. */
		public array $requests = [];

		/** @var list<string> Query documents of every case. */
		public array $sent = [];

		/** @var list<array{root:string,status:int,body:string}> Answers for the coming requests. */
		public array $responses = [];

		/**
		 * Record the request and answer it with the first scripted answer for its root connection.
		 *
		 * @param  array<string,mixed>  $request  Request of the curl service.
		 * @return array<string,mixed>  Response of the curl service.
		 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When no answer is scripted for the root connection.
		 */
		public function execute(array $request): array {
			$this->requests[] = $request;
			$query = queryOf($request);
			$this->sent[] = $query;
			$root = rootOf($query);

			$index = null;
			foreach ($this->responses as $i => $response) {
				if ($response['root'] === $root) {
					$index = $i;
					break;
				}
			}
			check($index !== null, 'Unexpected request for ' . ($root ?? 'an unknown connection'));

			$answer = $this->responses[$index];
			\array_splice($this->responses, $index, 1);

			return [
				'status_code' => $answer['status'],
				'is_http_success' => $answer['status'] >= 200 && $answer['status'] < 300,
				'body' => $answer['body'],
			];
		}
	};
}


/**
 * Return the bbr service that the cases share, on an app double with the cfg it reads, the
 * scripted transport and an API key.
 *
 * @return Bbr
 */
function bbr(): Bbr {
	static $bbr = null;

	if ($bbr === null) {
		$app = new App(['danish_public_sector_data' => [
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
		]]);
		$app->set('curl', transport());
		$app->set('secrets', new class {
			/**
			 * Return the API key, the only secret the service reads.
			 *
			 * @param  string  $key
			 * @return string
			 * @throws \OutOfBoundsException  For any other key, as the secrets service does.
			 */
			public function get(string $key): string {
				return $key === 'datafordeler.api_key' ? 'api-key' : throw new \OutOfBoundsException($key);
			}
		});

		$bbr = new Bbr($app);
	}

	return $bbr;
}


/**
 * Start a case: Forget the requests so far and script the coming answers.
 *
 * @param  list<array{root:string,status:int,body:string}>  $responses  Answers; those for one root connection in the order it is asked.
 * @return void
 */
function script(array $responses): void {
	$curl = transport();
	$curl->requests = [];
	$curl->responses = $responses;
}


/**
 * Return the query documents of the current case.
 *
 * @return list<string>
 */
function queries(): array {
	return \array_map(queryOf(...), transport()->requests);
}


/**
 * Return the root connections that the current case asked, in order.
 *
 * @return list<string|null>
 */
function roots(): array {
	return \array_map(rootOf(...), queries());
}


/**
 * Return the query document of a request.
 *
 * @param  array<string,mixed>  $request  Request of the curl service.
 * @return string
 */
function queryOf(array $request): string {
	return (string)\json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR)['query'];
}


/**
 * Return the root field of a query document.
 *
 * @param  string  $query
 * @return string|null
 */
function rootOf(string $query): ?string {
	return \preg_match('/^\s*(BBR_[A-Za-z]+|DAR_[A-Za-z]+)\(/m', $query, $match) ? $match[1] : null;
}


/**
 * Return the effect time of a query document.
 *
 * @param  string  $query
 * @return string|null
 */
function effectTime(string $query): ?string {
	return \preg_match('/virkningstid: "([^"]+)"/', $query, $match) ? $match[1] : null;
}


/**
 * Return the query documents of the current case that ask one root connection.
 *
 * @param  string  $root
 * @return list<string>
 */
function queriesOf(string $root): array {
	return \array_values(\array_filter(queries(), static fn(string $query): bool => rootOf($query) === $root));
}


// ----------------------------------------------------------------
// Answers
// ----------------------------------------------------------------

/**
 * Build an answer with one page of a root connection.
 *
 * @param  string  $root
 * @param  list<array<string,mixed>>  $nodes
 * @return array{root:string,status:int,body:string}
 */
function page(string $root, array $nodes): array {
	return ['root' => $root, 'status' => 200, 'body' => \json_encode(
		['data' => [$root => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]],
		\JSON_THROW_ON_ERROR
	)];
}


/**
 * Build the DAR answers of the typed address: One postcode, one street and one house number.
 *
 * @param  string|null  $accessBuilding  Key of IDS for the building that DAR names for the house number, or null for none.
 * @param  bool  $floors  True for a house number with two addresses on two floors, false for one address without floor and door.
 * @param  string|null  $townName  Supplementary town name that DAR writes for the house number, or null for none.
 * @return list<array{root:string,status:int,body:string}>
 */
function dar(?string $accessBuilding, bool $floors = false, ?string $townName = null): array {
	$town = $townName !== null ? $townName . ', ' : '';
	$addresses = $floors
		? [
			['id_lokalId' => IDS['address'], 'adressebetegnelse' => 'Eksempelvej 10L, st. tv, ' . $town . '7200 Grindsted', 'etagebetegnelse' => 'st', 'doerbetegnelse' => 'tv'],
			['id_lokalId' => IDS['address-2'], 'adressebetegnelse' => 'Eksempelvej 10L, 1. tv, ' . $town . '7200 Grindsted', 'etagebetegnelse' => '1', 'doerbetegnelse' => 'tv'],
		]
		: [
			['id_lokalId' => IDS['address'], 'adressebetegnelse' => 'Eksempelvej 10L, ' . $town . '7200 Grindsted', 'etagebetegnelse' => '', 'doerbetegnelse' => ''],
		];

	return [
		page('DAR_Postnummer', [['id_lokalId' => IDS['postcode'], 'postnr' => '7200', 'navn' => 'Grindsted']]),
		page('DAR_NavngivenVej', [['id_lokalId' => IDS['street'], 'vejnavn' => 'Eksempelvej']]),
		page('DAR_NavngivenVejPostnummer', [['id_lokalId' => IDS['street-postcode'], 'navngivenVej' => IDS['street'], 'postnummer' => IDS['postcode']]]),
		page('DAR_Husnummer', [[
			'id_lokalId' => IDS['house'],
			'adgangsadressebetegnelse' => 'Eksempelvej 10L, ' . $town . '7200 Grindsted',
			'husnummertekst' => '10L',
			'adgangTilBygning' => $accessBuilding !== null ? IDS[$accessBuilding] : null,
		]]),
		page('DAR_Adresse', $addresses),
	];
}


/**
 * Build a building node.
 *
 * @param  string  $id  Key of IDS.
 * @param  string|null  $ground  Key of IDS for its ground, or null for none.
 * @return array<string,mixed>
 */
function building(string $id, ?string $ground): array {
	return ['id_lokalId' => IDS[$id], 'grund' => $ground !== null ? IDS[$ground] : null, 'ejerlejlighed' => null];
}


/**
 * Build a ground node.
 *
 * @param  string  $id  Key of IDS.
 * @param  string  $property  Key of IDS for its SFE.
 * @return array<string,mixed>
 */
function ground(string $id, string $property): array {
	return ['id_lokalId' => IDS[$id], 'bestemtFastEjendom' => IDS[$property]];
}


/**
 * Build a unit node.
 *
 * @param  string  $id  Key of IDS.
 * @param  string|null  $building  Key of IDS for its building, or null for none.
 * @return array<string,mixed>
 */
function unit(string $id, ?string $building): array {
	return ['id_lokalId' => IDS[$id], 'adresseIdentificerer' => IDS['address'], 'bygning' => $building !== null ? IDS[$building] : null];
}


/**
 * Build a property relation node.
 *
 * @param  string  $id  Key of IDS.
 * @param  int  $bfeNumber
 * @param  string  $type  BBR ejendomstype: 1, 2 or 3.
 * @return array<string,mixed>
 */
function property(string $id, int $bfeNumber, string $type): array {
	return ['id_lokalId' => IDS[$id], 'bfeNummer' => $bfeNumber, 'ejendomstype' => $type];
}


/**
 * Build a candidate as resolveAddress() returns it.
 *
 * @param  int  $bfeNumber
 * @param  string  $propertyType  sfe, bpfg or ejerlejlighed.
 * @param  string  $id  Key of IDS for the property relation.
 * @param  list<string>  $evidence
 * @return array{bfeNumber:int,propertyType:string,propertyRelationId:string,evidence:list<string>}
 */
function candidate(int $bfeNumber, string $propertyType, string $id, array $evidence): array {
	return ['bfeNumber' => $bfeNumber, 'propertyType' => $propertyType, 'propertyRelationId' => IDS[$id], 'evidence' => $evidence];
}


// ----------------------------------------------------------------
// Access buildings
// ----------------------------------------------------------------

test('A side entrance whose building BBR registers under another house number resolves to the SFE of that building', function (): void {
	script([
		...dar('building'),
		page('BBR_Bygning', []),
		page('BBR_Enhed', [unit('unit', 'building')]),
		page('BBR_EnhedEjendomsrelation', []),
		page('BBR_Opgang', [['id_lokalId' => IDS['entrance'], 'bygning' => IDS['building'], 'adgangFraHusnummer' => IDS['house']]]),
		page('BBR_Bygning', [building('building', 'ground')]),
		page('BBR_Grund', [ground('ground', 'property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('property', 1001, '1')]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);
	$queries = queries();

	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 1001 && $result['warnings'] === [], 'The SFE of the access building is selected.');
	check($result['bfeCandidates'] === [candidate(1001, 'sfe', 'property', ['access_ground_sfe'])], 'The candidate is marked as found through an access building.');
	check($result['matchedAddress'] === 'Eksempelvej 10L, 7200 Grindsted', 'The DAR address of the side entrance is the matched address.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
	check(\array_slice(roots(), 5) === ['BBR_Bygning', 'BBR_Enhed', 'BBR_EnhedEjendomsrelation', 'BBR_Opgang', 'BBR_Bygning', 'BBR_Grund', 'BBR_BygningEjendomsrelation', 'BBR_Ejendomsrelation'], 'The access buildings are looked for after the house number and its unit give no property.');
	check(\str_contains(queriesOf('BBR_Opgang')[0], 'where: { adgangFraHusnummer: { in: ["' . IDS['house'] . '"] } }'), 'The entrances are asked for by the house number they are reached from.');
	check(\str_contains(queriesOf('BBR_Bygning')[1], 'where: { id_lokalId: { in: ["' . IDS['building'] . '"] } }'), 'The building that DAR, the entrance and the unit all name is asked for once.');
	check(\count(\array_unique(\array_map(effectTime(...), $queries))) === 1, 'Every request of the call has the same effect time.');
});

test('The building that DAR names, an entrance or the unit at the address each lead to the access building on their own', function (): void {
	$property = [
		page('BBR_Bygning', [building('building', 'ground')]),
		page('BBR_Grund', [ground('ground', 'property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('property', 1001, '1')]),
	];
	$links = [
		'DAR' => [...dar('building'), page('BBR_Bygning', []), page('BBR_Enhed', []), page('BBR_Opgang', []), ...$property],
		'the entrance' => [...dar(null), page('BBR_Bygning', []), page('BBR_Enhed', []), page('BBR_Opgang', [['id_lokalId' => IDS['entrance'], 'bygning' => IDS['building'], 'adgangFraHusnummer' => IDS['house']]]), ...$property],
		'the unit' => [...dar(null), page('BBR_Bygning', []), page('BBR_Enhed', [unit('unit', 'building')]), page('BBR_EnhedEjendomsrelation', []), page('BBR_Opgang', []), ...$property],
	];

	foreach ($links as $link => $answers) {
		script($answers);
		$result = bbr()->resolveAddress(TYPED_ADDRESS);

		check($result['selectedBfeNumber'] === 1001 && $result['bfeCandidates'] === [candidate(1001, 'sfe', 'property', ['access_ground_sfe'])], 'Only ' . $link . ' names the building, and its SFE is selected.');
		check(transport()->responses === [], 'Every scripted answer is asked for when only ' . $link . ' names the building.');
	}
});

test('Access buildings on two properties give ambiguous_bfe and select neither', function (): void {
	script([
		...dar('building'),
		page('BBR_Bygning', []),
		page('BBR_Enhed', []),
		page('BBR_Opgang', [['id_lokalId' => IDS['entrance'], 'bygning' => IDS['building-2'], 'adgangFraHusnummer' => IDS['house']]]),
		page('BBR_Bygning', [building('building', 'ground'), building('building-2', 'ground-2')]),
		page('BBR_Grund', [ground('ground', 'property'), ground('ground-2', 'property-2')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('property-2', 1002, '1'), property('property', 1001, '1')]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);

	check($result['status'] === 'ambiguous_bfe', 'The status is ambiguous_bfe.');
	check($result['selectedBfeNumber'] === null, 'No property is selected.');
	check($result['bfeCandidates'] === [candidate(1001, 'sfe', 'property', ['access_ground_sfe']), candidate(1002, 'sfe', 'property-2', ['access_ground_sfe'])], 'Both properties are candidates, ordered by BFE number.');
	check($result['warnings'] === ['multiple_bfe_candidates'], 'The ambiguity is reported as a warning.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
	check(\str_contains(queriesOf('BBR_Bygning')[1], 'where: { id_lokalId: { in: ["' . IDS['building'] . '","' . IDS['building-2'] . '"] } }'), 'Both access buildings are asked for in one request.');
});

test('Without a selected address the owner apartments in an access building are candidates, so the SFE of its ground is not selected', function (): void {
	script([
		...dar('building', true),
		page('BBR_Bygning', []),
		page('BBR_Opgang', []),
		page('BBR_Bygning', [building('building', 'ground')]),
		page('BBR_Grund', [ground('ground', 'property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'building'), unit('unit-2', 'building')]),
		page('BBR_EnhedEjendomsrelation', [
			['id_lokalId' => IDS['unit-relation'], 'enhed' => IDS['unit'], 'ejerlejlighed' => IDS['apartment']],
			['id_lokalId' => IDS['unit-relation-2'], 'enhed' => IDS['unit-2'], 'ejerlejlighed' => IDS['apartment-2']],
		]),
		page('BBR_Ejendomsrelation', [property('property', 1001, '1'), property('apartment', 1101, '3'), property('apartment-2', 1102, '3')]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);

	check($result['status'] === 'ambiguous_bfe', 'The status is ambiguous_bfe.');
	check($result['selectedBfeNumber'] === null, 'No property is selected.');
	check($result['bfeCandidates'] === [
		candidate(1001, 'sfe', 'property', ['access_ground_sfe']),
		candidate(1101, 'ejerlejlighed', 'apartment', ['access_unit_ejerlejlighed']),
		candidate(1102, 'ejerlejlighed', 'apartment-2', ['access_unit_ejerlejlighed']),
	], 'The SFE and both owner apartments are candidates.');
	check($result['warnings'] === ['multiple_addresses_without_unit', 'multiple_bfe_candidates'], 'The missing floor and door and the ambiguity are reported.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
	check(\str_contains(queriesOf('BBR_Enhed')[0], 'where: { bygning: { in: ["' . IDS['building'] . '"] } }'), 'The units are asked for by the access building.');
});

test('A house number whose own building has no property and that gives access to no other building stays no_bfe', function (): void {
	script([
		...dar('own-building'),
		page('BBR_Bygning', [building('own-building', null)]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', []),
		page('BBR_Opgang', [['id_lokalId' => IDS['entrance'], 'bygning' => IDS['own-building'], 'adgangFraHusnummer' => IDS['house']]]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);

	check($result['status'] === 'no_bfe' && $result['bfeCandidates'] === [] && $result['selectedBfeNumber'] === null, 'No candidate is found.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
	check(\array_slice(roots(), 5) === ['BBR_Bygning', 'BBR_Enhed', 'BBR_BygningEjendomsrelation', 'BBR_EnhedEjendomsrelation', 'BBR_Opgang'], 'The own building is not asked for again as an access building.');
});


// ----------------------------------------------------------------
// House numbers with a property of their own
// ----------------------------------------------------------------

test('A house number whose own building has a property resolves through it, without asking for entrances', function (): void {
	script([
		...dar('own-building'),
		page('BBR_Bygning', [building('own-building', 'own-ground')]),
		page('BBR_Grund', [ground('own-ground', 'own-property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', [['id_lokalId' => IDS['unit-relation'], 'enhed' => IDS['unit'], 'ejerlejlighed' => IDS['apartment']]]),
		page('BBR_Ejendomsrelation', [property('own-property', 2001, '1'), property('apartment', 2101, '3')]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);

	check(transport()->responses === [], 'Every scripted answer is asked for, and nothing else.');
	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 2101 && $result['warnings'] === [], 'The owner apartment of the unit at the address is selected.');
	check($result['bfeCandidates'] === [candidate(2001, 'sfe', 'own-property', ['ground_sfe']), candidate(2101, 'ejerlejlighed', 'apartment', ['selected_unit_ejerlejlighed'])], 'The SFE and the owner apartment are candidates without access evidence.');

	script([
		...dar('own-building', true),
		page('BBR_Bygning', [building('own-building', 'own-ground')]),
		page('BBR_Grund', [ground('own-ground', 'own-property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'own-building'), unit('unit-2', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('own-property', 2001, '1')]),
	]);
	$result = bbr()->resolveAddress(TYPED_ADDRESS);

	check(transport()->responses === [], 'Every scripted answer is asked for without a selected address, and nothing else.');
	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 2001, 'Without a selected address the SFE of the building is selected.');
	check($result['bfeCandidates'] === [candidate(2001, 'sfe', 'own-property', ['ground_sfe'])], 'The SFE is the only candidate.');
	check($result['warnings'] === ['multiple_addresses_without_unit'], 'The missing floor and door are reported.');
});


// ----------------------------------------------------------------
// Supplementary town names
// ----------------------------------------------------------------

test('A supplementary town name after the house number is not taken for a floor and a door', function (): void {
	script([
		...dar('own-building', false, 'Tofterup'),
		page('BBR_Bygning', [building('own-building', 'own-ground')]),
		page('BBR_Grund', [ground('own-ground', 'own-property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('own-property', 2001, '1')]),
	]);
	$result = bbr()->resolveAddress('Eksempelvej 10L, Tofterup, 7200 Grindsted');

	check($result['matchedAddress'] === 'Eksempelvej 10L, Tofterup, 7200 Grindsted', 'The only address of the house number is selected.');
	check($result['parsed']['unit'] === null, 'No floor and door are left.');
	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 2001 && $result['warnings'] === [], 'The SFE is selected.');
	check(\str_contains(queriesOf('BBR_Enhed')[0], 'where: { adresseIdentificerer: { eq: "' . IDS['address'] . '" } }'), 'The units are asked for by the selected address.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
});

test('The DAR designation of an owner apartment, with its supplementary town name, selects that apartment', function (): void {
	script([
		...dar('own-building', true, 'Tofterup'),
		page('BBR_Bygning', [building('own-building', 'own-ground')]),
		page('BBR_Grund', [ground('own-ground', 'own-property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit-2', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', [['id_lokalId' => IDS['unit-relation-2'], 'enhed' => IDS['unit-2'], 'ejerlejlighed' => IDS['apartment-2']]]),
		page('BBR_Ejendomsrelation', [property('own-property', 2001, '1'), property('apartment-2', 2102, '3')]),
	]);
	// The designation that DAR answers for address-2 above.
	$designation = 'Eksempelvej 10L, 1. tv, Tofterup, 7200 Grindsted';
	$result = bbr()->resolveAddress($designation);

	check($result['matchedAddress'] === $designation, 'The designation selects its own address.');
	check($result['parsed']['unit'] === ['raw' => '1. tv', 'floor' => '1', 'door' => 'tv'], 'The floor and door are read without the town name.');
	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 2102, 'The owner apartment of the unit at the address is selected.');
	check($result['bfeCandidates'] === [candidate(2001, 'sfe', 'own-property', ['ground_sfe']), candidate(2102, 'ejerlejlighed', 'apartment-2', ['selected_unit_ejerlejlighed'])], 'The SFE and the owner apartment of the selected unit are candidates.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
});

test('A part that DAR does not write for the house number is still read as a floor and a door', function (): void {
	script([
		...dar('own-building', false, 'Tofterup'),
		page('BBR_Bygning', [building('own-building', 'own-ground')]),
		page('BBR_Grund', [ground('own-ground', 'own-property')]),
		page('BBR_BygningEjendomsrelation', []),
		page('BBR_Enhed', [unit('unit', 'own-building')]),
		page('BBR_EnhedEjendomsrelation', []),
		page('BBR_Ejendomsrelation', [property('own-property', 2001, '1')]),
	]);
	$result = bbr()->resolveAddress('Eksempelvej 10L, Andeby, 7200 Grindsted');

	check($result['parsed']['unit'] === ['raw' => 'Andeby', 'floor' => 'andeby', 'door' => null], 'The other part stays the typed floor.');
	check($result['matchedAddress'] === null, 'No address is selected.');
	check(\str_contains(queriesOf('BBR_Enhed')[0], 'where: { bygning: { in: ["' . IDS['own-building'] . '"] } }'), 'The units are asked for by the buildings of the house number.');
	check($result['status'] === 'resolved' && $result['selectedBfeNumber'] === 2001, 'The only candidate is still selected.');
	check(transport()->responses === [], 'Every scripted answer is asked for.');
});


// ----------------------------------------------------------------
// Query documents
// ----------------------------------------------------------------

test('Every query document sent fits the bundled flexibleCurrent v3 schema', function (): void {
	$schema = GraphqlSchema::fromFile(\dirname(__DIR__, 2) . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');

	foreach (\array_unique(transport()->sent) as $document) {
		$stats = $schema->check($document);
		check(\count($stats['roots']) === 1, 'Every request has exactly one root field.');
	}
});

done();
