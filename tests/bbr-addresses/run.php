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

use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Service\Bbr;
use CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;

/*
 * Isolated suite for getAddressesByBfe() of the bbr service: The requests it sends for many BFE
 * numbers and the addresses it returns, against a scripted transport. No network request is
 * made. Every query document that is sent is also checked against the bundled flexibleCurrent v3
 * schema.
 *
 * Usage:
 *   php tests/bbr-addresses/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Endpoint of the current BBR and DAR queries. */
const GRAPHQL_URL = 'https://graphql.datafordeler.dk/flexibleCurrent/v3';

/** Ids of the objects in the answers, one UUID per name. */
const IDS = [
	'property-sfe'   => '11111111-1111-4111-8111-111111111111',
	'property-bpfg'  => '22222222-2222-4222-8222-222222222222',
	'property-el'    => '33333333-3333-4333-8333-333333333333',
	'property-sfe-a' => '44444444-4444-4444-8444-444444444444',
	'property-sfe-b' => '55555555-5555-4555-8555-555555555555',
	'ground-1'       => '66666666-6666-4666-8666-666666666661',
	'ground-2'       => '66666666-6666-4666-8666-666666666662',
	'ground-3'       => '66666666-6666-4666-8666-666666666663',
	'ground-4'       => '66666666-6666-4666-8666-666666666664',
	'ground-a'       => '66666666-6666-4666-8666-66666666666a',
	'ground-b'       => '66666666-6666-4666-8666-66666666666b',
	'building-rel'   => '77777777-7777-4777-8777-777777777771',
	'building'       => '77777777-7777-4777-8777-777777777772',
	'unit-rel'       => '88888888-8888-4888-8888-888888888881',
	'unit'           => '88888888-8888-4888-8888-888888888882',
	'house-1'        => '99999999-9999-4999-8999-999999999991',
	'house-2'        => '99999999-9999-4999-8999-999999999992',
	'house-3'        => '99999999-9999-4999-8999-999999999993',
	'house-a'        => '99999999-9999-4999-8999-99999999999a',
	'house-b'        => '99999999-9999-4999-8999-99999999999b',
	'address'        => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
];


// ----------------------------------------------------------------
// Transport, secrets and service
// ----------------------------------------------------------------

/**
 * Return the scripted transport that every case shares.
 *
 * It answers each request with the next scripted answer, and keeps the query document of every
 * request for the schema case.
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

		/** @var list<array{status:int,body:string}> Answers for the coming requests. */
		public array $responses = [];

		/**
		 * Record the request and answer it with the next scripted answer.
		 *
		 * @param  array<string,mixed>  $request  Request of the curl service.
		 * @return array<string,mixed>  Response of the curl service.
		 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When no answer is scripted.
		 */
		public function execute(array $request): array {
			$this->requests[] = $request;
			$this->sent[] = queryOf($request);

			check($this->responses !== [], 'Unexpected request to ' . $request['url']);

			$answer = \array_shift($this->responses);

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
 * @param  list<array{status:int,body:string}>  $responses  Answers, one per request.
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


// ----------------------------------------------------------------
// Answers
// ----------------------------------------------------------------

/**
 * Build an answer with one page of a root connection.
 *
 * @param  string  $root
 * @param  list<array<string,mixed>>  $nodes
 * @param  string|null  $cursor  Cursor of a next page, or null for the last page.
 * @return array{status:int,body:string}
 */
function page(string $root, array $nodes, ?string $cursor = null): array {
	return ['status' => 200, 'body' => \json_encode(
		['data' => [$root => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $cursor !== null, 'endCursor' => $cursor]]]],
		\JSON_THROW_ON_ERROR
	)];
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


// ----------------------------------------------------------------
// Addresses
// ----------------------------------------------------------------

test('Each kind of property gets the designations of its own objects, each once and in natural order', function (): void {
	script([
		page('BBR_Ejendomsrelation', [property('property-sfe', 101, '1'), property('property-bpfg', 102, '2'), property('property-el', 103, '3')]),
		page('BBR_Grund', [
			['id_lokalId' => IDS['ground-1'], 'bestemtFastEjendom' => IDS['property-sfe'], 'husnummer' => IDS['house-1']],
			['id_lokalId' => IDS['ground-2'], 'bestemtFastEjendom' => IDS['property-sfe'], 'husnummer' => IDS['house-2']],
			['id_lokalId' => IDS['ground-3'], 'bestemtFastEjendom' => IDS['property-sfe'], 'husnummer' => null],
			['id_lokalId' => IDS['ground-4'], 'bestemtFastEjendom' => IDS['property-sfe'], 'husnummer' => IDS['house-1']],
		]),
		page('BBR_BygningEjendomsrelation', [['id_lokalId' => IDS['building-rel'], 'bygning' => IDS['building'], 'bygningPaaFremmedGrund' => IDS['property-bpfg']]]),
		page('BBR_Bygning', [['id_lokalId' => IDS['building'], 'husnummer' => IDS['house-3']]]),
		page('BBR_EnhedEjendomsrelation', [['id_lokalId' => IDS['unit-rel'], 'enhed' => IDS['unit'], 'ejerlejlighed' => IDS['property-el']]]),
		page('BBR_Enhed', [['id_lokalId' => IDS['unit'], 'adresseIdentificerer' => IDS['address']]]),
		page('DAR_Husnummer', [
			['id_lokalId' => IDS['house-1'], 'adgangsadressebetegnelse' => 'Eksempelvej 10, 7800 Skive'],
			['id_lokalId' => IDS['house-2'], 'adgangsadressebetegnelse' => 'Eksempelvej 2, 7800 Skive'],
			['id_lokalId' => IDS['house-3'], 'adgangsadressebetegnelse' => 'Gade 1, 7800 Skive'],
		]),
		page('DAR_Adresse', [['id_lokalId' => IDS['address'], 'adressebetegnelse' => 'Eksempelvej 5, 2. tv, 7800 Skive']]),
	]);
	$result = bbr()->getAddressesByBfe([101, 102, 103, 104]);
	$queries = queries();

	check(\array_map(rootOf(...), $queries) === ['BBR_Ejendomsrelation', 'BBR_Grund', 'BBR_BygningEjendomsrelation', 'BBR_Bygning', 'BBR_EnhedEjendomsrelation', 'BBR_Enhed', 'DAR_Husnummer', 'DAR_Adresse'], 'One request per kind of object, the DAR lookups last.');
	check(\str_contains($queries[0], 'where: { bfeNummer: { in: [101,102,103,104] } }'), 'The properties are asked for by BFE number.');
	check(\str_contains($queries[1], 'where: { bestemtFastEjendom: { in: ["' . IDS['property-sfe'] . '"] } }'), 'The grounds are asked for by the id of their property.');
	check(\str_contains($queries[2], 'where: { bygningPaaFremmedGrund: { in: ["' . IDS['property-bpfg'] . '"] } }') && \str_contains($queries[4], 'where: { ejerlejlighed: { in: ["' . IDS['property-el'] . '"] } }'), 'The building and unit relations are asked for by the id of their property.');
	check(\count(\array_unique(\array_map(effectTime(...), $queries))) === 1, 'Every request of the call has the same effect time.');
	check($result === [
		101 => ['propertyType' => 'sfe', 'addresses' => ['Eksempelvej 2, 7800 Skive', 'Eksempelvej 10, 7800 Skive']],
		102 => ['propertyType' => 'bpfg', 'addresses' => ['Gade 1, 7800 Skive']],
		103 => ['propertyType' => 'ejerlejlighed', 'addresses' => ['Eksempelvej 5, 2. tv, 7800 Skive']],
		104 => ['propertyType' => null, 'addresses' => []],
	], 'Each property has its type and its addresses, and a number without a property has neither.');
});

test('A number with several current properties has no type and the addresses of all of them, and no address ids send no DAR address request', function (): void {
	script([
		page('BBR_Ejendomsrelation', [property('property-sfe-a', 201, '1'), property('property-sfe-b', 201, '1')]),
		page('BBR_Grund', [
			['id_lokalId' => IDS['ground-a'], 'bestemtFastEjendom' => IDS['property-sfe-a'], 'husnummer' => IDS['house-a']],
			['id_lokalId' => IDS['ground-b'], 'bestemtFastEjendom' => IDS['property-sfe-b'], 'husnummer' => IDS['house-b']],
		]),
		page('DAR_Husnummer', [
			['id_lokalId' => IDS['house-b'], 'adgangsadressebetegnelse' => 'Bvej 1, 1000 By'],
			['id_lokalId' => IDS['house-a'], 'adgangsadressebetegnelse' => 'Avej 1, 1000 By'],
		]),
	]);
	$result = bbr()->getAddressesByBfe(['201']);

	check(\array_map(rootOf(...), queries()) === ['BBR_Ejendomsrelation', 'BBR_Grund', 'DAR_Husnummer'], 'Only the objects that the properties have are asked for.');
	check($result === [201 => ['propertyType' => null, 'addresses' => ['Avej 1, 1000 By', 'Bvej 1, 1000 By']]], 'Several current properties give no type and the addresses of all of them.');
});

test('No numbers send nothing, invalid numbers are rejected without a request, and repeated numbers are looked up once in batches of 100', function (): void {
	script([]);
	check(bbr()->getAddressesByBfe([]) === [], 'No numbers give no entries.');

	foreach ([0, -1, '0', '012', '1.5', 1.5, 'abc', '99999999999999999999', null, true] as $invalid) {
		throws(\InvalidArgumentException::class, fn() => bbr()->getAddressesByBfe([1001, $invalid]));
	}
	check(transport()->requests === [], 'No numbers and invalid numbers send no request.');

	script([page('BBR_Ejendomsrelation', []), page('BBR_Ejendomsrelation', [])]);
	$result = bbr()->getAddressesByBfe([...\range(2001, 2150), ' 2001', 2002]);
	$queries = queries();

	check(\count($queries) === 2 && \str_contains($queries[0], 'in: [' . \implode(',', \range(2001, 2100)) . ']') && \str_contains($queries[1], 'in: [' . \implode(',', \range(2101, 2150)) . ']'), '150 distinct numbers go out in batches of 100 and 50.');
	check(\array_keys($result) === \range(2001, 2150), 'Entries are keyed by number in the order the numbers first occur.');
});

test('A property of a number that was not requested, or of an unknown type, is rejected', function (): void {
	script([page('BBR_Ejendomsrelation', [property('property-sfe', 999, '1')])]);
	throws(InvalidResponseException::class, fn() => bbr()->getAddressesByBfe([101]));

	script([page('BBR_Ejendomsrelation', [property('property-sfe', 101, '9')])]);
	throws(InvalidResponseException::class, fn() => bbr()->getAddressesByBfe([101]));
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
