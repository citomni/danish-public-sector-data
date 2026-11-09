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
use CitOmni\DanishPublicSectorData\Service\Cvr;
use CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;

/*
 * Isolated suite for the cvr service: The requests it sends, and the records and employee figures
 * it returns, against a scripted transport. No network request is made. Every query document that
 * is sent is also checked against the bundled flexibleCurrent v3 schema.
 *
 * Usage:
 *   php tests/cvr/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Keys of a company record, in order. */
const COMPANY_KEYS = ['cvrNumber', 'name', 'status', 'startDate', 'endDate', 'companyType', 'address', 'postalAddress', 'contact', 'industries', 'productionUnits'];

/** Keys of a production-unit record, in order. */
const UNIT_KEYS = ['pNumber', 'cvrNumber', 'name', 'status', 'startDate', 'endDate', 'attachmentStartDate', 'attachmentEndDate', 'address', 'postalAddress', 'contact', 'industries'];

/** Keys of an employee figure, in order. */
const EMPLOYEE_KEYS = ['count', 'intervalFrom', 'intervalTo', 'periodStart', 'periodEnd'];


// ----------------------------------------------------------------
// Transport and service
// ----------------------------------------------------------------

/**
 * Return the scripted transport that every case shares.
 *
 * It answers each request with the next scripted GraphQL data object, and keeps the query
 * document of every request for the schema case.
 *
 * @return object
 */
function transport(): object {
	static $curl = null;

	return $curl ??= new class {
		/** @var list<array{url:string,query:string}> Requests of the current case. */
		public array $requests = [];

		/** @var list<string> Query documents of every case. */
		public array $sent = [];

		/** @var list<array<string,mixed>> GraphQL data objects for the coming requests. */
		public array $responses = [];

		/** Number of coming requests after which the answer waits for the next wall-clock second. */
		public int $secondBoundaries = 0;

		/**
		 * Record the request and answer it with the next scripted data object.
		 *
		 * @param  array<string,mixed>  $request  Request of the curl service.
		 * @return array<string,mixed>  Response of the curl service.
		 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When no answer is scripted.
		 */
		public function execute(array $request): array {
			$payload = \json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR);
			$this->requests[] = ['url' => (string)$request['url'], 'query' => (string)$payload['query']];
			$this->sent[] = (string)$payload['query'];

			check($this->responses !== [], 'Unexpected request: ' . $payload['query']);

			// A request that read the clock itself would now see another second than this one.
			if ($this->secondBoundaries > 0) {
				$this->secondBoundaries--;
				$second = \time();
				while (\time() === $second) {
					\usleep(20000);
				}
			}

			return [
				'status_code' => 200,
				'is_http_success' => true,
				'body' => \json_encode(['data' => \array_shift($this->responses)], \JSON_THROW_ON_ERROR),
			];
		}
	};
}


/**
 * Return the cvr service that every case shares, on the cfg it reads and the scripted transport.
 *
 * @return Cvr
 */
function cvr(): Cvr {
	static $cvr = null;

	if ($cvr === null) {
		$app = new App(['danish_public_sector_data' => [
			'datafordeler' => [
				'base_url'        => 'https://graphql.datafordeler.dk',
				'timeout'         => 15,
				'connect_timeout' => 5,
			],
			'cvr' => [
				'service' => 'flexibleCurrent',
				'version' => 'v3',
			],
		]]);
		$app->set('curl', transport());
		$app->set('secrets', new class {
			/**
			 * Return the API key; no other secret exists.
			 *
			 * @param  string  $key
			 * @return string
			 * @throws \OutOfBoundsException  When the key is not datafordeler.api_key.
			 */
			public function get(string $key): string {
				return $key === 'datafordeler.api_key' ? 'test-key' : throw new \OutOfBoundsException($key);
			}
		});
		$cvr = new Cvr($app);
	}

	return $cvr;
}


/**
 * Start a case: Forget the requests so far and script the coming answers.
 *
 * @param  list<array<string,mixed>>  $responses  GraphQL data objects, one per request.
 * @param  int  $secondBoundaries  Number of answers that first wait for the next second.
 * @return void
 */
function script(array $responses, int $secondBoundaries = 0): void {
	$curl = transport();
	$curl->requests = [];
	$curl->responses = $responses;
	$curl->secondBoundaries = $secondBoundaries;
}


/**
 * Return the query documents of the current case.
 *
 * @return list<string>
 */
function queries(): array {
	return \array_column(transport()->requests, 'query');
}


/**
 * Return the page size of a query document.
 *
 * @param  string  $query
 * @return int|null
 */
function pageSize(string $query): ?int {
	return \preg_match('/^\t\tfirst: ([0-9]+)$/m', $query, $match) ? (int)$match[1] : null;
}


/**
 * Return the numbers of the in filter of a query document.
 *
 * @param  string  $query
 * @return list<int>
 */
function numbersIn(string $query): array {
	if (!\preg_match('/where: \{ [A-Za-z]+: \{ in: \[([0-9, ]*)\] \} \}/', $query, $match)) {
		return [];
	}

	return \array_map('intval', \explode(', ', $match[1]));
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
 * Return the entity ids of the in filter of an employment query document.
 *
 * @param  string  $query
 * @return list<mixed>  A bare number decodes as an int, so a strict comparison catches an id sent as a number.
 */
function idsIn(string $query): array {
	if (!\preg_match('/where: \{ CVREnhedsId: \{ in: \[(.*)\] \} \}/', $query, $match)) {
		return [];
	}

	return \json_decode('[' . $match[1] . ']', true, 512, \JSON_THROW_ON_ERROR);
}


// ----------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------

/**
 * Build the CVR entity relation of a company or production unit.
 *
 * @param  string  $name
 * @param  array<string,mixed>  $relations  Relations that replace the empty defaults.
 * @return array<string,mixed>
 */
function entity(string $name, array $relations = []): array {
	return [
		'nodes' => [
			\array_replace([
				'id_CVR_Navn_CVREnhedsId_ref' => ['vaerdi' => $name],
				'id_CVR_Adressering_CVREnhedsId_ref' => ['nodes' => []],
				'id_CVR_Branche_CVREnhedsId_ref' => ['nodes' => []],
				'id_CVR_e_mailadresse_CVREnhedsId_ref' => null,
				'id_CVR_Telefonnummer_CVREnhedsId_ref' => null,
				'id_CVR_Reklamebeskyttelse_CVREnhedsId_ref' => null,
			], $relations),
		],
	];
}


/**
 * Build a CVR address node.
 *
 * @param  string  $use  beliggenhedsadresse or postadresse.
 * @param  string  $street
 * @param  string  $houseNumber
 * @param  string  $postalCode
 * @param  string  $city
 * @return array<string,mixed>
 */
function address(string $use, string $street, string $houseNumber, string $postalCode, string $city): array {
	return [
		'AdresseringAnvendelse' => $use,
		'coNavn' => null,
		'CVRAdresse_vejnavn' => $street,
		'CVRAdresse_husnummerFra' => $houseNumber,
		'CVRAdresse_husnummerTil' => null,
		'CVRAdresse_etagebetegnelse' => null,
		'CVRAdresse_doerbetegnelse' => null,
		'CVRAdresse_postnummer' => $postalCode,
		'CVRAdresse_postdistrikt' => $city,
		'CVRAdresse_supplerendeBynavn' => null,
		'CVRAdresse_landekode' => 'DK',
		'CVRAdresse_adresseFritekst' => null,
	];
}


/**
 * Build a CVR_Virksomhed node.
 *
 * @param  int  $cvrNumber
 * @param  string  $name
 * @param  array<string,mixed>  $relations  Entity relations that replace the empty defaults.
 * @return array<string,mixed>
 */
function company(int $cvrNumber, string $name, array $relations = []): array {
	return [
		'status' => 'aktiv',
		'CVRNummer' => $cvrNumber,
		'virksomhedStartdato' => '2019-03-28',
		'virksomhedOphoersdato' => null,
		'id_CVR_CVREnhed_id_ref' => entity($name, $relations),
		'id_CVR_Virksomhedsform_CVREnhedsId_ref' => ['vaerdi' => '80', 'vaerdiTekst' => 'Anpartsselskab'],
	];
}


/**
 * Build a CVR_Produktionsenhed node.
 *
 * @param  int  $pNumber
 * @param  int  $cvrNumber  CVR number of the company.
 * @param  string  $name
 * @param  array<string,mixed>  $relations  Entity relations that replace the empty defaults.
 * @return array<string,mixed>
 */
function unit(int $pNumber, int $cvrNumber, string $name, array $relations = []): array {
	return [
		'pNummer' => $pNumber,
		'tilknyttetVirksomhedsCVRNummer' => $cvrNumber,
		'status' => 'aktiv',
		'produktionsenhedStartdato' => '2019-03-28',
		'produktionsenhedOphoersdato' => null,
		'tilknyttetTilVirksomhedStartdato' => '2019-04-01',
		'tilknyttetTilVirksomhedOphoersdato' => null,
		'id_CVR_CVREnhed_id_ref' => entity($name, $relations),
	];
}


/**
 * Build the data object of one page of a root connection.
 *
 * @param  string  $root
 * @param  list<array<string,mixed>>  $nodes
 * @param  string|null  $endCursor  Cursor of a next page, or null for the last page.
 * @return array<string,mixed>
 */
function page(string $root, array $nodes, ?string $endCursor = null): array {
	return [
		$root => [
			'nodes' => $nodes,
			'pageInfo' => ['hasNextPage' => $endCursor !== null, 'endCursor' => $endCursor],
		],
	];
}


/**
 * Build a node of an entity-id query.
 *
 * @param  string  $numberField  CVRNummer or pNummer.
 * @param  int  $number
 * @param  string|null  $entityId
 * @return array<string,mixed>
 */
function idNode(string $numberField, int $number, ?string $entityId): array {
	return [$numberField => $number, 'id' => $entityId];
}


/**
 * Build a CVR_Beskaeftigelse node.
 *
 * @param  string  $entityId
 * @param  string  $type  beskaeftigelsestalstype.
 * @param  int|null  $count
 * @param  string  $periodStart
 * @param  string  $periodEnd
 * @param  int|null  $intervalFrom
 * @param  int|null  $intervalTo
 * @param  string  $registeredAt
 * @return array<string,mixed>
 */
function figure(string $entityId, string $type, ?int $count, string $periodStart, string $periodEnd, ?int $intervalFrom = null, ?int $intervalTo = null, string $registeredAt = '2026-01-10T08:00:00.000000+01:00'): array {
	return [
		'CVREnhedsId' => $entityId,
		'beskaeftigelsestalstype' => $type,
		'antal' => $count,
		'intervalFra' => $intervalFrom,
		'intervalTil' => $intervalTo,
		'datoFra' => $periodStart,
		'datoTil' => $periodEnd,
		'registreringsdato' => $registeredAt,
	];
}


/**
 * Return the answers to a company lookup with its production units: One company with both
 * addresses, industries and contact data, and two units, the first with relations of its own.
 *
 * @return list<array<string,mixed>>
 */
function companyWithUnits(): array {
	return [
		page('CVR_Virksomhed', [
			company(40386270, 'Example ApS', [
				'id_CVR_Adressering_CVREnhedsId_ref' => ['nodes' => [
					address('postadresse', 'Postboksvej', '1', '7400', 'Herning'),
					address('beliggenhedsadresse', 'Eksempelvej', '12', '7400', 'Herning'),
				]],
				'id_CVR_Branche_CVREnhedsId_ref' => ['nodes' => [
					['sekvens' => 1, 'vaerdi' => '620200', 'vaerdiTekst' => 'Konsulentbistand vedr. informationsteknologi'],
					['sekvens' => 0, 'vaerdi' => '620100', 'vaerdiTekst' => 'Computerprogrammering'],
				]],
				'id_CVR_e_mailadresse_CVREnhedsId_ref' => ['vaerdi' => 'info@example.test'],
				'id_CVR_Telefonnummer_CVREnhedsId_ref' => ['vaerdi' => '12345678'],
				'id_CVR_Reklamebeskyttelse_CVREnhedsId_ref' => ['vaerdi' => true],
			]),
		]),
		page('CVR_Produktionsenhed', [
			unit(1024552249, 40386270, 'Example ApS, lager'),
			unit(1024552248, 40386270, 'Example ApS', [
				'id_CVR_Adressering_CVREnhedsId_ref' => ['nodes' => [
					address('beliggenhedsadresse', 'Butiksgade', '3', '8000', 'Aarhus C'),
				]],
				'id_CVR_Branche_CVREnhedsId_ref' => ['nodes' => [
					['sekvens' => 0, 'vaerdi' => '471100', 'vaerdiTekst' => 'Supermarkeder'],
				]],
				'id_CVR_e_mailadresse_CVREnhedsId_ref' => ['vaerdi' => 'butik@example.test'],
				'id_CVR_Telefonnummer_CVREnhedsId_ref' => ['vaerdi' => '87654321'],
				'id_CVR_Reklamebeskyttelse_CVREnhedsId_ref' => ['vaerdi' => false],
			]),
		]),
	];
}


// ----------------------------------------------------------------
// Companies
// ----------------------------------------------------------------

test('getCompany() with production units sends one company and one unit request with one UTC effect time', function (): void {
	script(companyWithUnits());
	cvr()->getCompany('40386270', withProductionUnits: true);
	$sent = queries();

	check(\count($sent) === 2, 'A company with production units takes one company request and one unit request.');
	check(transport()->requests[0]['url'] === 'https://graphql.datafordeler.dk/flexibleCurrent/v3', 'Requests go to the configured flexibleCurrent endpoint.');
	check(\str_contains($sent[0], 'CVR_Virksomhed(') && numbersIn($sent[0]) === [40386270], 'The company request filters on the CVR number.');
	check(pageSize($sent[0]) === 100, 'The company request pages by the identifier batch size.');
	check(\str_contains($sent[1], 'tilknyttetVirksomhedsCVRNummer: { in: [40386270] }'), 'Production units are selected by the CVR number of their company.');
	check(pageSize($sent[1]) === 1000 && !\str_contains($sent[1], 'after:'), 'The first unit page has the largest page size and no cursor.');
	check(
		\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)effectTime($sent[0])) === 1 && effectTime($sent[0]) === effectTime($sent[1]),
		'Both requests of one lookup use the same UTC effect time.'
	);
});

test('getCompany() normalizes the company and its production units, ordered by P-number', function (): void {
	script(companyWithUnits());
	$result = cvr()->getCompany('40386270', withProductionUnits: true);

	check(\is_array($result) && \array_keys($result) === COMPANY_KEYS, 'The company record keeps its key order and ends with productionUnits.');
	check($result['cvrNumber'] === '40386270' && $result['name'] === 'Example ApS', 'Company identity is normalized.');
	check($result['companyType'] === ['code' => '80', 'name' => 'Anpartsselskab'], 'Company form is normalized.');
	check($result['address']['formatted'] === 'Eksempelvej 12, 7400 Herning', 'The location address wins over the postal address.');
	check($result['postalAddress']['formatted'] === 'Postboksvej 1, 7400 Herning', 'The postal address is kept separately.');
	check($result['contact'] === ['email' => 'info@example.test', 'phone' => '12345678', 'marketingProtected' => true], 'Company contact data is normalized.');
	check($result['industries']['primary']['code'] === '620100' && \count($result['industries']['secondary']) === 1, 'Company industries are split by sequence.');
	check(\array_column($result['productionUnits'], 'pNumber') === ['1024552248', '1024552249'], 'Production units are ordered by P-number.');

	$store = $result['productionUnits'][0];
	check(\array_keys($store) === UNIT_KEYS, 'The production-unit record has the documented keys in order.');
	check($store['cvrNumber'] === '40386270' && $store['name'] === 'Example ApS', 'A production unit carries its name and the CVR number of its company.');
	check($store['startDate'] === '2019-03-28' && $store['attachmentStartDate'] === '2019-04-01' && $store['endDate'] === null, 'Production-unit dates are normalized.');
	check($store['address']['formatted'] === 'Butiksgade 3, 8000 Aarhus C' && $store['postalAddress'] === null, 'A production unit has its own address.');
	check($store['contact'] === ['email' => 'butik@example.test', 'phone' => '87654321', 'marketingProtected' => false], 'A production unit has its own contact data.');
	check($store['industries']['primary']['code'] === '471100', 'A production unit has its own industries.');
	check(
		$result['productionUnits'][1]['contact'] === ['email' => null, 'phone' => null, 'marketingProtected' => null] && $result['productionUnits'][1]['address'] === null,
		'Missing relations of a production unit normalize to null.'
	);
});

test('getCompany() without production units takes one request and has productionUnits null', function (): void {
	script([page('CVR_Virksomhed', [company(40386270, 'Example ApS')])]);
	$result = cvr()->getCompany('40386270');

	check(\count(queries()) === 1, 'By default a company lookup takes one request, as before production units.');
	check(\is_array($result) && \array_key_exists('productionUnits', $result) && $result['productionUnits'] === null, 'productionUnits is null when it was not requested.');
});

test('getCompany() returns null for an unknown CVR number and sends no unit request', function (): void {
	script([page('CVR_Virksomhed', [])]);

	check(cvr()->getCompany('12345678', withProductionUnits: true) === null, 'An unknown CVR number returns null.');
	check(\count(queries()) === 1, 'No unit request is sent when no company was found.');
});

test('getCompany() follows the company and unit pages by cursor, with one effect time across a second boundary', function (): void {
	// The first answer waits for the next second, so a request that read the clock again would differ.
	script([
		page('CVR_Virksomhed', [], 'company-cursor-1'),
		page('CVR_Virksomhed', [company(40386270, 'Example ApS')]),
		page('CVR_Produktionsenhed', [unit(1000000003, 40386270, 'C'), unit(1000000001, 40386270, 'A')], 'unit-cursor-1'),
		page('CVR_Produktionsenhed', [unit(1000000002, 40386270, 'B')]),
	], 1);
	$result = cvr()->getCompany('40386270', withProductionUnits: true);
	$sent = queries();

	check(\count($sent) === 4, 'Every further page takes one more request.');
	check(\str_contains($sent[1], 'after: "company-cursor-1"') && \str_contains($sent[1], 'CVR_Virksomhed('), 'The next company page continues from the returned cursor.');
	check(!\str_contains($sent[2], 'after:') && \str_contains($sent[3], 'after: "unit-cursor-1"') && \str_contains($sent[3], 'CVR_Produktionsenhed('), 'The unit pages start without a cursor and continue from the returned one.');
	check(
		effectTime($sent[0]) !== null && \count(\array_unique(\array_map(effectTime(...), $sent))) === 1,
		'Every request of one call uses the effect time of the call, across a second boundary.'
	);
	check($result['name'] === 'Example ApS', 'A company on a later page is found.');
	check(\array_column($result['productionUnits'], 'name') === ['A', 'B', 'C'], 'Units of every page are merged and ordered by P-number.');
});

test('The first row of a company or a production unit wins over a repeated row', function (): void {
	script([
		page('CVR_Virksomhed', [company(40386270, 'First row'), company(40386270, 'Repeated row')]),
		page('CVR_Produktionsenhed', [unit(1000000001, 40386270, 'First unit row'), unit(1000000001, 40386270, 'Repeated unit row')]),
	]);
	$result = cvr()->getCompany('40386270', withProductionUnits: true);

	check($result['name'] === 'First row', 'The first row of a company wins over a repeated row.');
	check(\array_column($result['productionUnits'], 'name') === ['First unit row'], 'The first row of a production unit wins over a repeated row.');
});

test('getCompanies() sends 150 numbers in batches of 100 and keys the results by number in input order', function (): void {
	$numbers = \array_map('strval', \range(10000000, 10000149));
	script([
		page('CVR_Virksomhed', [company(10000099, 'Second'), company(10000000, 'First')]),
		page('CVR_Produktionsenhed', [unit(1100000000, 10000000, 'First unit')]),
		page('CVR_Virksomhed', [company(10000149, 'Last')]),
		page('CVR_Produktionsenhed', []),
	]);
	$result = cvr()->getCompanies([...$numbers, '10000000', '10000149'], withProductionUnits: true);
	$sent = queries();

	check(\count($sent) === 4, '150 numbers take two company requests and one unit request per batch with a found company.');
	check(numbersIn($sent[0]) === \range(10000000, 10000099), 'The first batch holds the first 100 distinct numbers in input order.');
	check(numbersIn($sent[1]) === [10000099, 10000000], 'Units are requested only for the companies found in the batch.');
	check(numbersIn($sent[2]) === \range(10000100, 10000149), 'The second batch holds the remaining 50 numbers.');
	check(\array_keys($result) === \range(10000000, 10000149), 'Results are keyed by CVR number in input order, and repeats collapse.');
	check($result[10000000]['name'] === 'First' && $result[10000099]['name'] === 'Second' && $result[10000001] === null, 'Found and unknown numbers are told apart.');
	check(\array_column($result[10000000]['productionUnits'], 'pNumber') === ['1100000000'] && $result[10000099]['productionUnits'] === [], 'Units are attached to their own company.');
	check($result[10000149]['productionUnits'] === [], 'A found company without units has an empty list.');
});

test('getCompanies() and getCompany() accept CVR numbers as ints, such as the keys of a result', function (): void {
	script([page('CVR_Virksomhed', [company(10000000, 'First')])]);
	$again = cvr()->getCompanies([10000000, 10000001]);
	check(numbersIn(queries()[0]) === [10000000, 10000001] && \array_keys($again) === [10000000, 10000001], 'CVR numbers are accepted as ints.');

	script([page('CVR_Virksomhed', [company(40386270, 'Example ApS')])]);
	check(cvr()->getCompany(40386270)['name'] === 'Example ApS', 'getCompany() accepts an int.');
});


// ----------------------------------------------------------------
// Production units
// ----------------------------------------------------------------

test('getProductionUnits() filters on the distinct P-numbers and keys the results by P-number', function (): void {
	script([
		page('CVR_Produktionsenhed', [
			unit(1024552248, 40386270, 'First row'),
			unit(1024552248, 40386270, 'Repeated row'),
		]),
	]);
	$result = cvr()->getProductionUnits(['1024552248', '1999999999', '1024552248']);
	$sent = queries();

	check(\count($sent) === 1, 'Up to 100 P-numbers take one request.');
	check(\str_contains($sent[0], 'pNummer: { in: [1024552248, 1999999999] }') && pageSize($sent[0]) === 100, 'The request filters on the distinct P-numbers and pages by the batch size.');
	check(\array_keys($result) === [1024552248, 1999999999] && $result[1999999999] === null, 'Results are keyed by P-number, null when unknown.');
	check($result[1024552248]['name'] === 'First row' && $result[1024552248]['cvrNumber'] === '40386270', 'The first row of a P-number wins, and the record names its company.');
});

test('getProductionUnit() returns the same record shape and accepts an int', function (): void {
	script([page('CVR_Produktionsenhed', [unit(1024552248, 40386270, 'Example ApS')])]);
	$single = cvr()->getProductionUnit('1024552248');
	check(\is_array($single) && \array_keys($single) === UNIT_KEYS && $single['pNumber'] === '1024552248', 'getProductionUnit() returns the same record shape.');

	script([page('CVR_Produktionsenhed', [unit(1024552248, 40386270, 'Example ApS')])]);
	check(cvr()->getProductionUnit(1024552248)['pNumber'] === '1024552248', 'getProductionUnit() accepts an int.');
});

test('getProductionUnits() sends 101 P-numbers in two requests', function (): void {
	script([page('CVR_Produktionsenhed', []), page('CVR_Produktionsenhed', [])]);
	cvr()->getProductionUnits(\array_map('strval', \range(1000000000, 1000000100)));
	$sent = queries();

	check(\count($sent) === 2 && \count(numbersIn($sent[0])) === 100 && numbersIn($sent[1]) === [1000000100], '101 P-numbers take two requests.');
});


// ----------------------------------------------------------------
// Input and responses that do not fit
// ----------------------------------------------------------------

test('Invalid CVR numbers and P-numbers are rejected before any request', function (): void {
	script([]);
	check(cvr()->getCompanies([]) === [] && cvr()->getProductionUnits([]) === [], 'An empty list returns an empty result.');

	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany('1234567'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany('123456789'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany('01234567'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany('1234567a'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany("12345678\n"));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany(1234567));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompany(-12345678));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompanies(['12345678', 12345678.0]));
	throws(\InvalidArgumentException::class, fn() => cvr()->getProductionUnit('123456789'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getProductionUnit('0123456789'));
	throws(\InvalidArgumentException::class, fn() => cvr()->getProductionUnits(['1234567890', '12345678901']));
	throws(\InvalidArgumentException::class, fn() => cvr()->getProductionUnits([12345678901]));

	check(transport()->requests === [], 'Rejected input sends no request.');
});

test('A company or a production unit that was not requested is rejected', function (): void {
	// The next page is announced but not scripted, so it must not be requested.
	script([page('CVR_Virksomhed', [company(12345678, 'Other')], 'more')]);
	throws(InvalidResponseException::class, fn() => cvr()->getCompany('40386270'));

	script([
		page('CVR_Virksomhed', [company(40386270, 'Example ApS')]),
		page('CVR_Produktionsenhed', [unit(1024552248, 12345678, 'Other')]),
	]);
	throws(InvalidResponseException::class, fn() => cvr()->getCompany('40386270', withProductionUnits: true));

	script([page('CVR_Produktionsenhed', [unit(1999999999, 40386270, 'Other')])]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnit('1024552248'));
});

test('A cursor that repeats, a next page without a cursor and a connection without pageInfo are rejected', function (): void {
	script([
		page('CVR_Virksomhed', [company(40386270, 'Example ApS')]),
		page('CVR_Produktionsenhed', [unit(1000000001, 40386270, 'A')], 'same-cursor'),
		page('CVR_Produktionsenhed', [unit(1000000002, 40386270, 'B')], 'same-cursor'),
	]);
	throws(InvalidResponseException::class, fn() => cvr()->getCompany('40386270', withProductionUnits: true));

	script([
		page('CVR_Produktionsenhed', [], 'cursor-a'),
		page('CVR_Produktionsenhed', [], 'cursor-b'),
		page('CVR_Produktionsenhed', [], 'cursor-a'),
	]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnit('1024552248'));
	check(\count(queries()) === 3, 'A cursor cycle stops at the first repeated cursor.');

	script([['CVR_Produktionsenhed' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnit('1024552248'));

	script([['CVR_Produktionsenhed' => ['nodes' => [unit(1024552248, 40386270, 'A')]]]]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnit('1024552248'));
});


// ----------------------------------------------------------------
// Employees
// ----------------------------------------------------------------

test('getProductionUnitEmployees() takes one id request and one employment request per batch', function (): void {
	script([
		page('CVR_Produktionsenhed', [
			idNode('pNummer', 1024552248, '4006724884'),
			idNode('pNummer', 1024552249, 'unit-b'),
		]),
		page('CVR_Beskaeftigelse', [figure('4006724884', 'AarsbeskaeftigelseAntalAnsatte', 12, '2025-01-01', '2025-12-31')]),
	]);
	cvr()->getProductionUnitEmployees(['1024552248', '1999999999', '1024552249', '1024552248']);
	$sent = queries();

	check(\count($sent) === 2, 'One batch of production units takes one id request and one employment request.');
	check(
		\str_contains($sent[0], 'CVR_Produktionsenhed(') && numbersIn($sent[0]) === [1024552248, 1999999999, 1024552249] && pageSize($sent[0]) === 100,
		'The id request filters on the distinct P-numbers and pages by the batch size.'
	);
	check(!\str_contains($sent[0], '_ref'), 'The id request joins nothing.');
	check(
		\str_contains($sent[1], 'CVR_Beskaeftigelse(') && idsIn($sent[1]) === ['4006724884', 'unit-b'] && pageSize($sent[1]) === 1000,
		'The employment request filters on the entity ids as strings, a numeric one included, and pages by the largest page.'
	);
	check(effectTime($sent[0]) !== null && effectTime($sent[0]) === effectTime($sent[1]), 'Both requests of one call use the same effect time.');
});

test('getProductionUnitEmployees() returns the latest figure that counts employees, keyed by P-number', function (): void {
	script([
		page('CVR_Produktionsenhed', [
			idNode('pNummer', 1024552248, '4006724884'),
			idNode('pNummer', 1024552249, 'unit-b'),
		]),
		page('CVR_Beskaeftigelse', [
			figure('4006724884', 'AarsbeskaeftigelseAntalAnsatte', 12, '2025-01-01', '2025-12-31'),
			figure('4006724884', 'MaanedsbeskaeftigelseAntalAnsatte', 14, '2026-08-01', '2026-08-31'),
			figure('4006724884', 'MaanedsbeskaeftigelseAntalAarsvaerk', 11, '2026-09-01', '2026-09-30'),
			figure('4006724884', 'KvartalsbeskaeftigelseAntalAnsatte', 13, '2026-04-01', '2026-06-30'),
			figure('unit-b', 'KvartalsbeskaeftigelseAntalAnsatteInterval', null, '2026-04-01', '2026-06-30', 10, 19),
			figure('unit-b', 'AarsbeskaeftigelseAntalAnsatte', 9, '2024-01-01', '2024-12-31'),
		]),
	]);
	$result = cvr()->getProductionUnitEmployees(['1024552248', '1999999999', '1024552249', '1024552248']);

	check(\array_keys($result) === [1024552248, 1999999999, 1024552249], 'Results are keyed by P-number in input order, and repeats collapse.');
	check($result[1999999999] === null, 'An unknown P-number has no figure.');
	check(\array_keys($result[1024552248]) === EMPLOYEE_KEYS, 'A figure has the documented keys in order.');
	check(
		$result[1024552248] === ['count' => 14, 'intervalFrom' => null, 'intervalTo' => null, 'periodStart' => '2026-08-01', 'periodEnd' => '2026-08-31'],
		'The figure that counts employees and ends last wins; a later figure of full-time equivalents is left out.'
	);
	check(
		$result[1024552249] === ['count' => null, 'intervalFrom' => 10, 'intervalTo' => 19, 'periodStart' => '2026-04-01', 'periodEnd' => '2026-06-30'],
		'A latest figure with only an interval keeps its bounds and a null count.'
	);
});

test('getProductionUnitEmployees() follows the pages of figures by cursor and compares the figures of every page', function (): void {
	script([
		page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, 'unit-a')]),
		page('CVR_Beskaeftigelse', [figure('unit-a', 'AarsbeskaeftigelseAntalAnsatte', 3, '2023-01-01', '2023-12-31')], 'figure-cursor-1'),
		page('CVR_Beskaeftigelse', [figure('unit-a', 'AarsbeskaeftigelseAntalAnsatte', 4, '2024-01-01', '2024-12-31')]),
	]);
	$result = cvr()->getProductionUnitEmployees([1024552248]);
	$sent = queries();

	check(\count($sent) === 3 && \str_contains($sent[2], 'CVR_Beskaeftigelse(') && \str_contains($sent[2], 'after: "figure-cursor-1"'), 'The next page of figures continues from the returned cursor.');
	check($result[1024552248]['count'] === 4, 'The figures of every page are compared.');
});

test('A unit whose figures count no employees, or hold no number, has no figure', function (): void {
	script([
		page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, 'unit-a')]),
		page('CVR_Beskaeftigelse', [
			figure('unit-a', 'AarsbeskaeftigelseAntalAarsvaerk', 3, '2024-01-01', '2024-12-31'),
			figure('unit-a', 'AarsbeskaeftigelseAntalAnsatte', null, '2024-01-01', '2024-12-31'),
		]),
	]);

	check(cvr()->getProductionUnitEmployees(['1024552248']) === [1024552248 => null], 'A unit whose figures count no employees, or hold no number, has no figure.');
});

test('getProductionUnitEmployees() sends 101 P-numbers in two id requests, and figure requests only for a batch with a unit found', function (): void {
	script([
		page('CVR_Produktionsenhed', []),
		page('CVR_Produktionsenhed', [idNode('pNummer', 1000000100, 'unit-last')]),
		page('CVR_Beskaeftigelse', [figure('unit-last', 'AarsbeskaeftigelseAntalAnsatte', 2, '2025-01-01', '2025-12-31')]),
	]);
	$result = cvr()->getProductionUnitEmployees(\array_map('strval', \range(1000000000, 1000000100)));
	$sent = queries();

	check(
		\count($sent) === 3 && \count(numbersIn($sent[0])) === 100 && numbersIn($sent[1]) === [1000000100] && \str_contains($sent[2], 'CVR_Beskaeftigelse('),
		'101 P-numbers take two id requests, and only the batch with a unit found takes an employment request.'
	);
	check(\count($result) === 101 && $result[1000000000] === null && $result[1000000100]['count'] === 2, 'Every number of every batch has an entry.');
});

test('getCompanyEmployees() filters on the CVR numbers and on the entity ids of the companies', function (): void {
	script([
		page('CVR_Virksomhed', [
			idNode('CVRNummer', 40386270, 'company-a'),
			idNode('CVRNummer', 40386271, 'company-b'),
			idNode('CVRNummer', 40386272, 'company-c'),
		]),
		page('CVR_Beskaeftigelse', []),
	]);
	cvr()->getCompanyEmployees([40386270, '40386271', '40386272']);
	$sent = queries();

	check(\str_contains($sent[0], 'CVR_Virksomhed(') && numbersIn($sent[0]) === [40386270, 40386271, 40386272], 'The id request of companies filters on the CVR numbers.');
	check(idsIn($sent[1]) === ['company-a', 'company-b', 'company-c'], 'The employment request of companies filters on their entity ids.');
});

test('Of figures that end on the same day the shorter period wins, then an exact count, then the later registration in UTC', function (): void {
	script([
		page('CVR_Virksomhed', [
			idNode('CVRNummer', 40386270, 'company-a'),
			idNode('CVRNummer', 40386271, 'company-b'),
			idNode('CVRNummer', 40386272, 'company-c'),
		]),
		page('CVR_Beskaeftigelse', [
			// A year and its last month end on the same day; the year has the higher count.
			figure('company-a', 'AarsbeskaeftigelseAntalAnsatte', 33, '2025-01-01', '2025-12-31'),
			figure('company-a', 'MaanedsbeskaeftigelseAntalAnsatte', 31, '2025-12-01', '2025-12-31'),
			// An interval and an exact count of the same period; the interval is registered later.
			figure('company-b', 'KvartalsbeskaeftigelseAntalAnsatteInterval', null, '2026-04-01', '2026-06-30', 50, 99, '2026-07-20T08:00:00.000000+02:00'),
			figure('company-b', 'KvartalsbeskaeftigelseAntalAnsatte', 52, '2026-04-01', '2026-06-30', null, null, '2026-07-10T08:00:00.000000+02:00'),
			// Two registrations of one figure: The first is later in UTC, the second sorts later as text.
			figure('company-c', 'MaanedsbeskaeftigelseAntalAnsatte', 7, '2026-03-01', '2026-03-31', null, null, '2026-03-29T02:30:00.000000+01:00'),
			figure('company-c', 'MaanedsbeskaeftigelseAntalAnsatte', 6, '2026-03-01', '2026-03-31', null, null, '2026-03-29T03:00:00.000000+02:00'),
		]),
	]);
	$result = cvr()->getCompanyEmployees([40386270, '40386271', '40386272']);

	check($result[40386270]['count'] === 31, 'Of figures that end on the same day, the shorter period wins.');
	check($result[40386271]['count'] === 52, 'Of figures of the same period, an exact count wins over an interval.');
	check($result[40386272]['count'] === 7, 'Of two registrations of one figure, the later one in UTC wins.');
});

test('getCompanyEmployees() returns null for an unknown CVR number and sends no employment request', function (): void {
	script([page('CVR_Virksomhed', [])]);

	check(cvr()->getCompanyEmployees(['12345678']) === [12345678 => null], 'An unknown CVR number has no figure.');
	check(\count(queries()) === 1, 'No employment request is sent when no company was found.');
});

test('The employee lookups return nothing for no numbers and reject invalid numbers before any request', function (): void {
	script([]);
	check(cvr()->getCompanyEmployees([]) === [] && cvr()->getProductionUnitEmployees([]) === [], 'An empty list of numbers returns an empty result.');
	throws(\InvalidArgumentException::class, fn() => cvr()->getProductionUnitEmployees(['123456789']));
	throws(\InvalidArgumentException::class, fn() => cvr()->getCompanyEmployees(['01234567']));
	check(transport()->requests === [], 'Rejected numbers send no request.');
});

test('An id row or a figure that does not fit the request is rejected', function (): void {
	script([page('CVR_Produktionsenhed', [idNode('pNummer', 1999999999, 'unit-x')])]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnitEmployees(['1024552248']));

	script([page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, null)])]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnitEmployees(['1024552248']));

	script([
		page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, 'unit-a')]),
		page('CVR_Beskaeftigelse', [figure('unit-z', 'AarsbeskaeftigelseAntalAnsatte', 1, '2025-01-01', '2025-12-31')]),
	]);
	throws(InvalidResponseException::class, fn() => cvr()->getProductionUnitEmployees(['1024552248']));
});

// One case per row: Each row replaces values of a valid figure, and its key says what makes the
// figure invalid.
foreach ([
	'A negative count' => ['antal' => -1],
	'A count that is not an integer' => ['antal' => '1.5'],
	'An interval that ends before it starts' => ['antal' => null, 'intervalFra' => 20, 'intervalTil' => 10],
	'A date that does not exist' => ['datoTil' => '2025-02-30'],
	'A period that ends before it starts' => ['datoFra' => '2025-12-31', 'datoTil' => '2025-01-01'],
	'A registration time that is not a time' => ['registreringsdato' => 'not a time'],
	'A missing type' => ['beskaeftigelsestalstype' => null],
] as $invalid => $values) {
	test($invalid . ' in a figure that counts employees is rejected', function () use ($values): void {
		script([
			page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, 'unit-a')]),
			page('CVR_Beskaeftigelse', [\array_replace(figure('unit-a', 'AarsbeskaeftigelseAntalAnsatte', 5, '2025-01-01', '2025-12-31'), $values)]),
		]);
		throws(InvalidResponseException::class, fn() => cvr()->getProductionUnitEmployees(['1024552248']));
	});
}

test('A figure of full-time equivalents is left out without a check of its values', function (): void {
	// The values of a figure that counts something else are never used, so they are not checked.
	script([
		page('CVR_Produktionsenhed', [idNode('pNummer', 1024552248, 'unit-a')]),
		page('CVR_Beskaeftigelse', [\array_replace(figure('unit-a', 'AarsbeskaeftigelseAntalAarsvaerk', 5, '2025-01-01', '2025-12-31'), ['antal' => -1, 'datoTil' => 'x'])]),
	]);

	check(cvr()->getProductionUnitEmployees(['1024552248']) === [1024552248 => null], 'A figure of full-time equivalents is left out without a check of its values.');
});


// ----------------------------------------------------------------
// Query documents
// ----------------------------------------------------------------

test('Every query document sent fits the bundled flexibleCurrent v3 schema, with one root field and at most 17 joins and 7 in depth', function (): void {
	$schema = GraphqlSchema::fromFile(\dirname(__DIR__, 2) . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
	check($schema->hasField('Query', 'CVR_Produktionsenhed') && $schema->hasInputField('CVR_ProduktionsenhedFilterInput', 'pNummer'), 'The bundled schema parses.');

	// Keyed by operation name, because the id query has the root field of the company or the unit query.
	$joinCounts = [];
	foreach (\array_unique(transport()->sent) as $document) {
		$stats = $schema->check($document);
		check(\count($stats['roots']) === 1, 'Every request has exactly one root field.');
		check($stats['joins'] <= 17 && $stats['depth'] <= 7, 'Every request stays within 17 joins in total and 7 in depth.');
		$joinCounts[$stats['operation']] = $stats['joins'];
	}
	\ksort($joinCounts);

	check(
		$joinCounts === ['CvrCompanies' => 8, 'CvrEmployment' => 0, 'CvrEntityIds' => 0, 'CvrProductionUnits' => 7],
		'The company query has 8 joins, the unit query 7, and the id and employment queries none.'
	);
});

done();
