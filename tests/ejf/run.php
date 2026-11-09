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

use CitOmni\DanishPublicSectorData\Exception\AuthenticationException;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
use CitOmni\DanishPublicSectorData\Service\Ejf;
use CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;
use CitOmni\Infrastructure\Exception\CurlException;

/*
 * Isolated suite for the ejf service and the Shared Secret flow of DatafordelerClient: The token
 * and query requests, and the ownerships and transfers the service returns, against a scripted
 * transport. No network request is made. Every query document that is sent is also checked
 * against the bundled flexibleCurrent v3 schema.
 *
 * The case of a failed token transfer throws the CurlException of citomni/infrastructure, so it
 * needs vendor/: Run "composer install" in the package root first.
 *
 * Usage:
 *   php tests/ejf/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Token endpoint in the cfg of the cases. */
const TOKEN_URL = 'https://auth.datafordeler.dk/realms/distribution/protocol/openid-connect/token';

/** Endpoint of the EJF queries. */
const GRAPHQL_URL = 'https://graphql.datafordeler.dk/flexibleCurrent/v3';

/** CPR address with every value null. */
const EMPTY_CPR_ADDRESS = [
	'vejadresseringsnavn' => null,
	'husnummer' => null,
	'etage' => null,
	'sidedoer' => null,
	'bygningsnummer' => null,
	'bynavn' => null,
	'postnummer' => null,
	'postdistrikt' => null,
	'cprKommunekode' => null,
	'cprKommunenavn' => null,
	'cprVejkode' => null,
];


// ----------------------------------------------------------------
// Transport, secrets and service
// ----------------------------------------------------------------

/**
 * Return the scripted transport that every case shares.
 *
 * It answers each request with the next scripted answer or throws the next scripted exception,
 * and keeps the query document of every EJF query for the schema case.
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

		/** @var list<array{status:int,body:string}|\Throwable> Answers for the coming requests. */
		public array $responses = [];

		/**
		 * Record the request and answer it with the next scripted answer.
		 *
		 * @param  array<string,mixed>  $request  Request of the curl service.
		 * @return array<string,mixed>  Response of the curl service.
		 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When no answer is scripted.
		 * @throws \Throwable  The next answer, when it is an exception.
		 */
		public function execute(array $request): array {
			$this->requests[] = $request;
			if ($request['url'] === GRAPHQL_URL) {
				$this->sent[] = queryOf($request);
			}

			check($this->responses !== [], 'Unexpected request to ' . $request['url']);

			$answer = \array_shift($this->responses);
			if ($answer instanceof \Throwable) {
				throw $answer;
			}

			return [
				'status_code' => $answer['status'],
				'is_http_success' => $answer['status'] >= 200 && $answer['status'] < 300,
				'body' => $answer['body'],
			];
		}
	};
}


/**
 * Return the secrets service that every case shares, with the secrets of the current case.
 *
 * @return object
 */
function secrets(): object {
	static $secrets = null;

	return $secrets ??= new class {
		/** @var array<string,string> Secrets by key. */
		public array $values = [];

		/**
		 * Return one secret.
		 *
		 * @param  string  $key
		 * @return string
		 * @throws \OutOfBoundsException  When no secret has the key, as the secrets service does.
		 */
		public function get(string $key): string {
			return \array_key_exists($key, $this->values) ? $this->values[$key] : throw new \OutOfBoundsException($key);
		}
	};
}


/**
 * Give the coming lookups a client id of their own, so their first query asks for a token first.
 *
 * A case takes a client id of its own because the client reuses a token across the process for
 * the same client id.
 *
 * @return void
 */
function newClient(): void {
	static $number = 0;

	$number++;
	secrets()->values = [
		'datafordeler.oauth_client_id' => 'client-' . $number,
		'datafordeler.oauth_client_secret' => 'secret&' . $number,
	];
}


/**
 * Build an App with the cfg the ejf service reads, the scripted transport and the secrets.
 *
 * @param  string  $tokenUrl
 * @param  string  $service  Service segment of the EJF endpoint.
 * @return App
 */
function app(string $tokenUrl = TOKEN_URL, string $service = 'flexibleCurrent'): App {
	$app = new App(['danish_public_sector_data' => [
		'datafordeler' => [
			'base_url'        => 'https://graphql.datafordeler.dk',
			'token_url'       => $tokenUrl,
			'timeout'         => 15,
			'connect_timeout' => 5,
		],
		'ejf' => [
			'service' => $service,
			'version' => 'v3',
		],
	]]);
	$app->set('curl', transport());
	$app->set('secrets', secrets());

	return $app;
}


/**
 * Return the ejf service that the cases share.
 *
 * @return Ejf
 */
function ejf(): Ejf {
	static $ejf = null;

	return $ejf ??= new Ejf(app());
}


/**
 * Start a case: Forget the requests so far and script the coming answers.
 *
 * @param  list<array{status:int,body:string}|\Throwable>  $responses  Answers, one per request.
 * @return void
 */
function script(array $responses): void {
	$curl = transport();
	$curl->requests = [];
	$curl->responses = $responses;
}


/**
 * Start a case with a client id of its own and a token answer in front of the scripted answers.
 *
 * @param  list<array{status:int,body:string}|\Throwable>  $responses  Answers to the queries.
 * @return void
 */
function scriptWithToken(array $responses): void {
	newClient();
	script([token('token'), ...$responses]);
}


/**
 * Return the requests of the current case to one URL.
 *
 * @param  string  $url
 * @return list<array<string,mixed>>
 */
function requestsTo(string $url): array {
	return \array_values(\array_filter(transport()->requests, static fn(array $request): bool => $request['url'] === $url));
}


/**
 * Return the query document of an EJF query.
 *
 * @param  array<string,mixed>  $request  Request of the curl service.
 * @return string
 */
function queryOf(array $request): string {
	return (string)\json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR)['query'];
}


/**
 * Return the BFE numbers of the in filter of a query document.
 *
 * @param  string  $query
 * @return list<int>
 */
function numbersIn(string $query): array {
	if (!\preg_match('/where: \{ bestemtFastEjendomBFENr: \{ in: \[([0-9, ]*)\] \} \}/', $query, $match)) {
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


// ----------------------------------------------------------------
// Answers and fixtures
// ----------------------------------------------------------------

/**
 * Build an answer of the token endpoint.
 *
 * @param  string  $token  Access token.
 * @param  int|null  $expiresIn  Lifetime in seconds, or null for an answer without expires_in.
 * @return array{status:int,body:string}
 */
function token(string $token, ?int $expiresIn = 3600): array {
	$answer = ['access_token' => $token, 'token_type' => 'Bearer'];
	if ($expiresIn !== null) {
		$answer['expires_in'] = $expiresIn;
	}

	return answer(200, \json_encode($answer, \JSON_THROW_ON_ERROR));
}


/**
 * Build an answer.
 *
 * @param  int  $status  HTTP status.
 * @param  string  $body
 * @return array{status:int,body:string}
 */
function answer(int $status, string $body = '{}'): array {
	return ['status' => $status, 'body' => $body];
}


/**
 * Build an answer with one page of a root connection.
 *
 * @param  string  $root
 * @param  list<array<string,mixed>>  $nodes
 * @param  string|null  $cursor  Cursor of a next page, or null for the last page.
 * @return array{status:int,body:string}
 */
function page(string $root, array $nodes, ?string $cursor = null): array {
	return answer(200, \json_encode(
		['data' => [$root => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $cursor !== null, 'endCursor' => $cursor]]]],
		\JSON_THROW_ON_ERROR
	));
}


/**
 * Build an answer with one page of ownerships.
 *
 * @param  list<array<string,mixed>>  $nodes
 * @param  string|null  $cursor  Cursor of a next page, or null for the last page.
 * @return array{status:int,body:string}
 */
function owners(array $nodes, ?string $cursor = null): array {
	return page('EJFCustom_EjerskabBegraenset', $nodes, $cursor);
}


/**
 * Build an answer with one page of transfers.
 *
 * @param  list<array<string,mixed>>  $nodes
 * @param  string|null  $cursor  Cursor of a next page, or null for the last page.
 * @return array{status:int,body:string}
 */
function changes(array $nodes, ?string $cursor = null): array {
	return page('EJF_Ejerskifte', $nodes, $cursor);
}


/**
 * Build a person owner who lives in Denmark.
 *
 * @param  array<string,mixed>  $values  Values that replace the defaults.
 * @return array<string,mixed>
 */
function person(array $values = []): array {
	return \array_replace([
		'id' => 'person-1',
		'status' => 'bopael_i_danmark',
		'statusdato' => '2015-03-01',
		'statusdatoUsikkerhedsmarkering' => false,
		'foedselsdato' => '1950-06-15',
		'foedselsdatoUsikkerhedsmarkering' => false,
		'navn' => ['navn' => ' Example Owner '],
		'beskyttelser' => [['beskyttelsestype' => 'markedsfoering'], ['beskyttelsestype' => 'lokal_vejviser'], ['beskyttelsestype' => 'markedsfoering']],
		'standardadresse' => 'Eksempelvej 12, 2. tv',
		'cprAdresse' => [
			'vejadresseringsnavn' => 'Eksempelvej',
			'husnummer' => '12',
			'etage' => '02',
			'sidedoer' => 'tv',
			'bygningsnummer' => null,
			'bynavn' => null,
			'postnummer' => '7400',
			'postdistrikt' => 'Herning',
			'cprKommunekode' => '0657',
			'cprKommunenavn' => 'Herning',
			'cprVejkode' => '1234',
		],
		'adresseoplysninger' => ['adresseringsnavn' => 'Example Owner'],
		'udrejseIndrejse' => null,
	], $values);
}


/**
 * Build a current ownership of the whole property, without an owner.
 *
 * @param  int  $bfeNumber
 * @param  string  $id
 * @param  array<string,mixed>  $values  Values that replace the defaults.
 * @return array<string,mixed>
 */
function ownership(int $bfeNumber, string $id, array $values = []): array {
	return \array_replace([
		'id_lokalId' => $id,
		'bestemtFastEjendomBFENr' => $bfeNumber,
		'status' => 'gældende',
		'ejerforholdskode' => '10',
		'faktiskEjerandel_taeller' => 1,
		'faktiskEjerandel_naevner' => 1,
		'tinglystEjerandel_taeller' => 1,
		'tinglystEjerandel_naevner' => 1,
		'ejendeVirksomhedCVRNr' => null,
		'ejeroplysningerLokalId' => null,
		'primaerKontakt' => true,
		'virkningFra' => '2019-06-30T22:00:00.000000Z',
		'forretningshaendelse' => 'Endeligt skøde',
		'forretningsproces' => 'Hændelse fra Digital Tinglysning',
		'ejendePersonBegraenset' => null,
		'oplysningerEjesAfEjerskab' => null,
	], $values);
}


/**
 * Build the trade information of a transfer of property 4001.
 *
 * @param  array<string,mixed>  $values  Values that replace the defaults.
 * @return array<string,mixed>
 */
function trade(array $values = []): array {
	return \array_replace([
		'id_lokalId' => 'trade-1',
		'samletKoebesum' => 1250000,
		'kontantKoebesum' => 1250000.5,
		'loesoeresum' => null,
		'valutakode' => 'DKK',
		'koebsaftaleDato' => '2019-05-02',
		'afstaaelsesdato' => '2019-05-02',
		'betalingsforpligtelsesdato' => null,
		'bygningerOmfattet' => 'Enfamiliesejendom',
		'handelsoplysningerBeskriverEjerskifte' => ['nodes' => [['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4001]]],
	], $values);
}


/**
 * Build a current transfer without trade information.
 *
 * @param  int  $bfeNumber
 * @param  string  $id
 * @param  array<string,mixed>  $values  Values that replace the defaults.
 * @return array<string,mixed>
 */
function transfer(int $bfeNumber, string $id, array $values = []): array {
	return \array_replace([
		'id_lokalId' => $id,
		'bestemtFastEjendomBFENr' => $bfeNumber,
		'status' => 'gældende',
		'overdragelsesmaade' => 'Almindelig fri handel',
		'overtagelsesdato' => '2019-06-30T22:00:00.000000Z',
		'anmeldelsesdato' => '2019-07-10T22:00:00.000000Z',
		'anmeldelsesidentifikator' => 'doc-1',
		'betinget' => false,
		'fristDato' => null,
		'forretningshaendelse' => 'Endeligt skøde',
		'forretningsproces' => 'Hændelse fra Digital Tinglysning',
		'handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => null,
	], $values);
}


/**
 * Look up the ownerships of property 3001, which has an ownership of every kind of owner.
 *
 * The ownerships: own-person, a current person owner; own-protected, a former person owner with
 * name and address protection; own-deceased and own-emigrated, persons without a Danish address;
 * own-company; own-other and own-other-id, owners without CPR or CVR number; own-unknown, without
 * an owner.
 *
 * @return array<string,array<string,mixed>>  Ownerships keyed by id, in the order the service returns them.
 */
function ownershipsOfEveryKind(): array {
	scriptWithToken([owners([
		ownership(3001, 'own-person', ['ejendePersonBegraenset' => person(), 'tinglystEjerandel_taeller' => null, 'tinglystEjerandel_naevner' => null]),
		ownership(3001, 'own-protected', [
			'status' => 'historisk',
			'virkningFra' => '2005-12-31T23:00:00.000000Z',
			'faktiskEjerandel_naevner' => 2,
			'primaerKontakt' => false,
			'ejendePersonBegraenset' => person([
				'id' => 'person-2',
				'navn' => null,
				'standardadresse' => null,
				'cprAdresse' => null,
				'adresseoplysninger' => null,
				'beskyttelser' => [['beskyttelsestype' => 'navne_og_adresse']],
			]),
		]),
		ownership(3001, 'own-deceased', [
			'virkningFra' => '1799-12-31T23:00:00.000000Z',
			'ejendePersonBegraenset' => person([
				'id' => 'person-3',
				'status' => 'doed',
				'statusdato' => '2026-10-20',
				'standardadresse' => '',
				'cprAdresse' => EMPTY_CPR_ADDRESS,
				'udrejseIndrejse' => ['udlandsadresselinje1' => null, 'udlandsadresselinje2' => null, 'udlandsadresselinje3' => null, 'udlandsadresselinje4' => null, 'udlandsadresselinje5' => null, 'cprLandUdrejse' => null],
			]),
		]),
		ownership(3001, 'own-emigrated', [
			'ejendePersonBegraenset' => person([
				'id' => 'person-4',
				'status' => 'udrejst',
				'standardadresse' => '',
				'cprAdresse' => EMPTY_CPR_ADDRESS,
				'udrejseIndrejse' => [
					'udlandsadresselinje1' => '1 Example Street',
					'udlandsadresselinje2' => ' ',
					'udlandsadresselinje3' => 'London',
					'udlandsadresselinje4' => null,
					'udlandsadresselinje5' => null,
					'cprLandUdrejse' => ['navn' => 'Storbritannien', 'landekode' => 'GB'],
				],
			]),
		]),
		ownership(3001, 'own-company', ['ejerforholdskode' => '30', 'ejendeVirksomhedCVRNr' => 12345678]),
		ownership(3001, 'own-other', [
			'ejerforholdskode' => '50',
			'oplysningerEjesAfEjerskab' => [
				'navn' => 'Boet efter Example Owner',
				'adresselinje1' => 'c/o Example Law',
				'adresselinje2' => null,
				'adresselinje3' => 'Example Street 1',
				'adresselinje4' => '',
				'adresselinje5' => null,
				'adresselinje6' => null,
				'adresselinje7' => null,
				'adresselinje8' => null,
				'adresselinje9' => null,
				'adresselinje10' => 'Copenhagen',
				'landeKodeNumerisk' => '208',
			],
		]),
		ownership(3001, 'own-other-id', ['ejeroplysningerLokalId' => 'pv-1']),
		ownership(3001, 'own-unknown', ['ejerforholdskode' => '99', 'virkningFra' => '1969-12-31T23:00:00Z', 'forretningshaendelse' => ' ']),
	])]);

	return \array_column(ejf()->getOwnerships([3001])[3001], null, 'id');
}


/**
 * Look up the transfers of property 4001: A sale, a rolled-back transfer, a transfer with a
 * placeholder takeover date and a deed without trade information.
 *
 * @return array<string,array<string,mixed>>  Transfers keyed by id, in the order the service returns them.
 */
function transfersOfEveryKind(): array {
	scriptWithToken([changes([
		transfer(4001, 'tr-sale', ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => trade([
			'handelsoplysningerBeskriverEjerskifte' => ['nodes' => [
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4002],
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4001],
				['status' => 'tilbagerullet', 'bestemtFastEjendomBFENr' => 4003],
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4001],
			]],
		])]),
		transfer(4001, 'tr-rolled-back', ['status' => 'tilbagerullet', 'overtagelsesdato' => '2020-01-01T23:00:00Z']),
		transfer(4001, 'tr-placeholder', [
			'overtagelsesdato' => '1799-12-31T23:00:00.000000Z',
			'anmeldelsesdato' => null,
			'anmeldelsesidentifikator' => null,
			'overdragelsesmaade' => 'Anden overdragelse',
			'betinget' => null,
			'handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => trade([
				'id_lokalId' => 'trade-2',
				'samletKoebesum' => 113000,
				'kontantKoebesum' => null,
				'valutakode' => null,
				'koebsaftaleDato' => null,
				'afstaaelsesdato' => '1973-08-01',
				'bygningerOmfattet' => null,
			]),
		]),
		transfer(4001, 'tr-deed', [
			'overtagelsesdato' => '2023-03-14T23:10:00.000000Z',
			'anmeldelsesdato' => '2023-05-16T22:00:00.000000Z',
			'overdragelsesmaade' => 'Anden overdragelse',
			'forretningshaendelse' => 'Auktionsskøde',
			'fristDato' => '2023-06-01',
			'betinget' => true,
		]),
	])]);

	return \array_column(ejf()->getTransfers([4001])[4001], null, 'id');
}


// ----------------------------------------------------------------
// Shared Secret
// ----------------------------------------------------------------

test('The first query of a client asks the token endpoint for a client credentials token and carries it as a bearer token', function (): void {
	newClient();
	script([token('token-a'), owners([])]);

	check(ejf()->getOwnerships([1001]) === [1001 => []], 'A property without ownerships has an empty list.');

	[$tokenRequest, $queryRequest] = transport()->requests + [null, null];
	check(\count(transport()->requests) === 2 && $tokenRequest['url'] === TOKEN_URL, 'The first lookup asks the token endpoint first.');

	\parse_str((string)$tokenRequest['body'], $form);
	$secrets = secrets()->values;
	check(
		$form === ['grant_type' => 'client_credentials', 'client_id' => $secrets['datafordeler.oauth_client_id'], 'client_secret' => $secrets['datafordeler.oauth_client_secret']],
		'The token request sends the client credentials grant with the client id and secret.'
	);
	check($tokenRequest['method'] === 'POST' && \in_array('Content-Type: application/x-www-form-urlencoded', $tokenRequest['headers'], true), 'The token request is a form POST.');
	check(!isset($tokenRequest['query']) && !\str_contains((string)$tokenRequest['url'], 'secret'), 'The client secret stays out of the token URL.');
	check($queryRequest['url'] === GRAPHQL_URL && ($queryRequest['bearer_token'] ?? null) === 'token-a', 'The query carries the access token as a bearer token.');
	check(!isset($queryRequest['query']) && !isset($queryRequest['sensitive_query_keys']), 'A Shared Secret query sends no API key.');
	check(\in_array('Accept: application/graphql-response+json', $queryRequest['headers'], true), 'The query asks for a GraphQL response, so a refusal does not answer 200.');
});

test('A valid token is reused, also by another client of the process with the same client id', function (): void {
	newClient();
	script([token('token-a'), owners([]), owners([]), owners([])]);
	ejf()->getOwnerships([1001]);
	ejf()->getOwnerships([1001]);
	check(\count(requestsTo(TOKEN_URL)) === 1 && \count(requestsTo(GRAPHQL_URL)) === 2, 'A valid token is reused.');

	(new Ejf(app()))->getOwnerships([1001]);
	check(\count(requestsTo(TOKEN_URL)) === 1, 'Another client of the process with the same client id reuses the token.');
});

test('A token that expires within the renewal margin is replaced before the next request', function (): void {
	newClient();
	script([token('token-b', 30), owners([]), token('token-c'), owners([])]);
	ejf()->getOwnerships([1001]);
	ejf()->getOwnerships([1001]);

	check(\count(requestsTo(TOKEN_URL)) === 2 && (requestsTo(GRAPHQL_URL)[1]['bearer_token'] ?? null) === 'token-c', 'The second lookup asks for a new token and carries it.');
});

test('A token answer without expires_in gives a token that the next lookup reuses', function (): void {
	newClient();
	script([token('token-d', null), owners([]), owners([])]);
	ejf()->getOwnerships([1001]);
	ejf()->getOwnerships([1001]);

	check(\count(requestsTo(TOKEN_URL)) === 1, 'A token answer without expires_in gives the token the documented lifetime.');
});

test('A missing client id or an empty client secret fails the lookup before anything is sent', function (): void {
	newClient();
	$secrets = secrets();
	unset($secrets->values['datafordeler.oauth_client_id']);
	script([]);
	throws(AuthenticationException::class, fn() => ejf()->getOwnerships([1001]));
	check(transport()->requests === [], 'Nothing is sent without a client id.');

	newClient();
	$secrets->values['datafordeler.oauth_client_secret'] = '';
	script([]);
	throws(AuthenticationException::class, fn() => ejf()->getOwnerships([1001]));
	check(transport()->requests === [], 'Nothing is sent with an empty client secret.');
});

// One case per row: Each row is the answer of the token endpoint, and the exception the lookup
// must fail with. The class must match exactly, since AuthenticationException and
// RateLimitException extend RemoteServiceException.
foreach ([
	'A 401 from the token endpoint' => [answer(401, '{"error":"invalid_client"}'), AuthenticationException::class],
	'A 400 from the token endpoint' => [answer(400, '{"error":"unauthorized_client"}'), AuthenticationException::class],
	'A 429 from the token endpoint' => [answer(429), RateLimitException::class],
	'A 500 from the token endpoint' => [answer(500), RemoteServiceException::class],
	'A token answer that is not JSON' => [answer(200, 'not json'), InvalidResponseException::class],
	'A token answer without access_token' => [answer(200, '{"token_type":"Bearer"}'), InvalidResponseException::class],
	'A token with a line break' => [token("token\r\nX-Injected: 1"), InvalidResponseException::class],
] as $failure => [$answer, $class]) {
	test($failure . ' fails the lookup and sends no query', function () use ($answer, $class): void {
		newClient();
		script([$answer]);
		$error = throws($class, fn() => ejf()->getOwnerships([1001]));
		check($error::class === $class, 'Expected exactly ' . $class . ', got ' . $error::class . '.');
		check(\count(transport()->requests) === 1, 'A failed token request sends no query.');
	});
}

test('A transport failure of the token request fails the lookup with a RemoteServiceException and sends no query', function (): void {
	check(\class_exists(CurlException::class), 'citomni/infrastructure was not found in vendor/. Run "composer install" in the package root first.');

	newClient();
	script([new CurlException('Timeout.')]);
	$error = throws(RemoteServiceException::class, fn() => ejf()->getOwnerships([1001]));
	check($error::class === RemoteServiceException::class, 'Expected exactly ' . RemoteServiceException::class . ', got ' . $error::class . '.');
	check(\count(transport()->requests) === 1, 'A failed token request sends no query.');
});

test('A 401 on the query fails the lookup and discards its token, so the next lookup asks for a new one', function (): void {
	newClient();
	script([token('token-e'), answer(401), token('token-f'), owners([])]);
	throws(AuthenticationException::class, fn() => ejf()->getOwnerships([1001]));
	ejf()->getOwnerships([1001]);

	check(\count(requestsTo(TOKEN_URL)) === 2 && (requestsTo(GRAPHQL_URL)[1]['bearer_token'] ?? null) === 'token-f', 'A rejected query discards its token, and the next lookup asks for a new one.');
});

test('A 429 on the query fails the lookup with a RateLimitException and keeps its token', function (): void {
	newClient();
	script([token('token-g'), answer(429), owners([])]);
	throws(RateLimitException::class, fn() => ejf()->getOwnerships([1001]));
	ejf()->getOwnerships([1001]);

	check(\count(requestsTo(TOKEN_URL)) === 1, 'A rate-limited query keeps its token.');
});

test('A token URL that is not HTTPS or an empty EJF service is rejected when the service is created', function (): void {
	throws(\UnexpectedValueException::class, fn() => new Ejf(app('http://auth.datafordeler.dk/token')));
	throws(\UnexpectedValueException::class, fn() => new Ejf(app(TOKEN_URL, '')));
});


// ----------------------------------------------------------------
// Input, batches and pages
// ----------------------------------------------------------------

test('No BFE numbers give no entries, and invalid BFE numbers are rejected, both without a request', function (): void {
	script([]);
	check(ejf()->getOwnerships([]) === [] && ejf()->getTransfers([]) === [], 'No numbers give no entries.');

	foreach (['0', '012', -1, 0, '1.5', 1.5, 'abc', ' 12', '12 ', '99999999999999999999', null, true] as $invalid) {
		throws(\InvalidArgumentException::class, fn() => ejf()->getOwnerships([1001, $invalid]));
		throws(\InvalidArgumentException::class, fn() => ejf()->getTransfers([$invalid]));
	}

	check(transport()->requests === [], 'No numbers and invalid numbers send no request.');
});

test('getOwnerships() sends 150 distinct numbers in batches of 100 and 50, with one UTC effect time, and keys the entries by number', function (): void {
	$numbers = \range(2001, 2150);
	scriptWithToken([owners([ownership(2001, 'own-a')]), owners([ownership(2150, 'own-b')])]);
	$result = ejf()->getOwnerships(\array_merge($numbers, ['2001', 2002]));
	$queries = \array_map(queryOf(...), requestsTo(GRAPHQL_URL));

	check(\count($queries) === 2 && numbersIn($queries[0]) === \range(2001, 2100) && numbersIn($queries[1]) === \range(2101, 2150), '150 distinct numbers go out in batches of 100 and 50, each number once.');
	check(\array_keys($result) === $numbers, 'Entries are keyed by number in the order the numbers first occur.');
	check(\count($result[2001]) === 1 && \count($result[2150]) === 1 && $result[2002] === [], 'Rows land under their own number, and the others are empty.');
	check(effectTime($queries[0]) !== null && effectTime($queries[0]) === effectTime($queries[1]), 'Every request of one call has the same effect time.');
	check(\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)effectTime($queries[0])) === 1, 'The effect time is UTC.');
	check(\str_contains($queries[0], "first: 100\n") && !\str_contains($queries[0], 'after:'), 'A first page asks for 100 rows without a cursor.');
});

test('The next page is requested with the cursor of the page before, and a row repeated on a later page is kept once', function (): void {
	scriptWithToken([
		owners([ownership(2001, 'own-a')], 'cursor-1'),
		owners([ownership(2001, 'own-c'), ownership(2001, 'own-a', ['status' => 'historisk'])]),
	]);
	$result = ejf()->getOwnerships([2001]);
	$queries = \array_map(queryOf(...), requestsTo(GRAPHQL_URL));

	check(\count($queries) === 2 && \str_contains($queries[1], 'after: "cursor-1"'), 'The next page is requested with the cursor of the page before.');
	check(\array_column($result[2001], 'id') === ['own-a', 'own-c'] && $result[2001][0]['current'], 'A row repeated on a later page is kept once, as it came first.');
});

test('A cursor that comes back, a next page without a cursor, a row of another property and an answer without the connection are rejected', function (): void {
	scriptWithToken([owners([], 'cursor-2'), owners([], 'cursor-2')]);
	throws(InvalidResponseException::class, fn() => ejf()->getOwnerships([2001]));

	scriptWithToken([answer(200, '{"data":{"EJFCustom_EjerskabBegraenset":{"nodes":[],"pageInfo":{"hasNextPage":true,"endCursor":null}}}}')]);
	throws(InvalidResponseException::class, fn() => ejf()->getOwnerships([2001]));

	scriptWithToken([owners([ownership(9999, 'own-z')])]);
	throws(InvalidResponseException::class, fn() => ejf()->getOwnerships([2001]));

	scriptWithToken([answer(200, '{"data":{"EJFCustom_EjerskabBegraenset":null}}')]);
	throws(InvalidResponseException::class, fn() => ejf()->getOwnerships([2001]));
});

test('GraphQL errors fail the lookup with a RemoteServiceException', function (): void {
	scriptWithToken([answer(200, '{"errors":[{"message":"refused"}],"data":null}')]);
	$error = throws(RemoteServiceException::class, fn() => ejf()->getOwnerships([2001]));
	check($error::class === RemoteServiceException::class, 'Expected exactly ' . RemoteServiceException::class . ', got ' . $error::class . '.');
});


// ----------------------------------------------------------------
// Ownerships
// ----------------------------------------------------------------

test('Ownerships come oldest first by validFrom, unknown dates first, then by id', function (): void {
	$rows = ownershipsOfEveryKind();

	check(
		\array_keys($rows) === ['own-deceased', 'own-unknown', 'own-protected', 'own-company', 'own-emigrated', 'own-other', 'own-other-id', 'own-person'],
		'Ownerships come oldest first by validFrom, unknown dates first, then by id.'
	);
	check($rows['own-deceased']['validFrom'] === null && $rows['own-unknown']['validFrom'] === null, 'The placeholders of 1800 and 1970 become null.');
});

test('A person owner keeps its CPR data, the address line as CPR writes it, and its protections sorted once each', function (): void {
	check(ownershipsOfEveryKind()['own-person'] === [
		'id' => 'own-person',
		'bfeNumber' => 3001,
		'status' => 'gældende',
		'current' => true,
		'validFrom' => '2019-07-01',
		'ownershipCode' => '10',
		'share' => ['numerator' => 1, 'denominator' => 1],
		'registeredShare' => null,
		'primaryContact' => true,
		'businessEvent' => 'Endeligt skøde',
		'businessProcess' => 'Hændelse fra Digital Tinglysning',
		'ownerType' => 'person',
		'person' => [
			'id' => 'person-1',
			'status' => 'bopael_i_danmark',
			'statusDate' => '2015-03-01',
			'statusDateUncertain' => false,
			'birthDate' => '1950-06-15',
			'birthDateUncertain' => false,
			'name' => 'Example Owner',
			'protections' => ['lokal_vejviser', 'markedsfoering'],
			'address' => [
				'line' => 'Eksempelvej 12, 2. tv',
				'street' => 'Eksempelvej',
				'houseNumber' => '12',
				'floor' => '02',
				'door' => 'tv',
				'building' => null,
				'city' => null,
				'postalCode' => '7400',
				'postalDistrict' => 'Herning',
				'municipalityCode' => '0657',
				'municipalityName' => 'Herning',
				'roadCode' => '1234',
				'formatted' => 'Eksempelvej 12, 2. tv, 7400 Herning',
			],
			'addressingName' => 'Example Owner',
			'foreignAddress' => null,
		],
		'companyCvr' => null,
		'other' => null,
	], 'A person keeps its CPR data, the address line as CPR writes it, and its protections sorted once each.');
});

test('Protection, death and emigration decide the name and addresses of a person owner', function (): void {
	$rows = ownershipsOfEveryKind();
	$protected = $rows['own-protected'];
	$deceased = $rows['own-deceased']['person'];
	$emigrated = $rows['own-emigrated']['person'];

	check(
		$protected['current'] === false && $protected['validFrom'] === '2006-01-01' && $protected['share'] === ['numerator' => 1, 'denominator' => 2] && $protected['primaryContact'] === false,
		'A former ownership has current false, its share unreduced, and a winter instant becomes the next Danish date.'
	);
	check(
		$protected['person']['name'] === null && $protected['person']['address'] === null && $protected['person']['addressingName'] === null && $protected['person']['protections'] === ['navne_og_adresse'],
		'A person with name and address protection has no name, address or addressing name.'
	);
	check(
		$deceased['status'] === 'doed' && $deceased['statusDate'] === '2026-10-20' && $deceased['address'] === null && $deceased['foreignAddress'] === null,
		'A deceased person has the date of death as status date, and an empty address becomes null.'
	);
	check(
		$emigrated['address'] === null && $emigrated['foreignAddress'] === ['lines' => ['1 Example Street', 'London'], 'country' => 'Storbritannien', 'countryCode' => 'GB'],
		'An emigrated person has the address abroad without empty lines.'
	);
});

test('Without a CPR address line, the formatted address starts with street and house number and names the city', function (): void {
	scriptWithToken([owners([ownership(3002, 'own-city', ['ejendePersonBegraenset' => person([
		'standardadresse' => null,
		'cprAdresse' => \array_replace(EMPTY_CPR_ADDRESS, ['vejadresseringsnavn' => 'Eksempelvej', 'husnummer' => '12', 'bynavn' => 'Lind', 'postnummer' => '7400', 'postdistrikt' => 'Herning']),
	])])])]);
	$address = ejf()->getOwnerships([3002])[3002][0]['person']['address'];

	check($address['line'] === null && $address['formatted'] === 'Eksempelvej 12, Lind, 7400 Herning', 'Without a CPR line, the formatted address starts with street and house number and names the city.');
});

test('A company, another owner and an ownership without an owner get their owner type and their own data', function (): void {
	$rows = ownershipsOfEveryKind();

	check(
		$rows['own-company']['ownerType'] === 'company' && $rows['own-company']['companyCvr'] === '12345678' && $rows['own-company']['person'] === null,
		'A company owner has its CVR number as a string.'
	);
	check(
		$rows['own-other']['ownerType'] === 'other'
			&& $rows['own-other']['other'] === ['name' => 'Boet efter Example Owner', 'addressLines' => ['c/o Example Law', 'Example Street 1', 'Copenhagen'], 'countryCode' => '208'],
		'An owner without CPR or CVR number has its name and the address lines that hold text.'
	);
	check($rows['own-other-id']['ownerType'] === 'other' && $rows['own-other-id']['other'] === null, 'Owner information that EJF names but does not join still makes an other owner.');
	check($rows['own-unknown']['ownerType'] === 'unknown' && $rows['own-unknown']['businessEvent'] === null, 'An ownership without an owner is unknown, and a blank text becomes null.');
});

// One case per row: Each row replaces values of a valid ownership, and its key says what makes
// the ownership invalid.
foreach ([
	'a missing id' => ['id_lokalId' => null],
	'a BFE number as a string' => ['bestemtFastEjendomBFENr' => '3001'],
	'a missing status' => ['status' => null],
	'a share that lacks one side' => ['tinglystEjerandel_taeller' => 1, 'tinglystEjerandel_naevner' => null],
	'a share whose denominator is 0' => ['faktiskEjerandel_naevner' => 0],
	'a negative share' => ['faktiskEjerandel_taeller' => -1],
	'an effect start without an offset' => ['virkningFra' => '2019-07-01T00:00:00'],
	'an effect start that is not a time' => ['virkningFra' => 'yesterday'],
	'a missing effect start' => ['virkningFra' => null],
	'a primary contact flag that is not a boolean' => ['primaerKontakt' => 'true'],
	'a person without a status date' => ['ejendePersonBegraenset' => person(['statusdato' => null])],
	'a person whose birth date does not exist' => ['ejendePersonBegraenset' => person(['foedselsdato' => '1950-02-30'])],
	'a person whose protections are not a list' => ['ejendePersonBegraenset' => person(['beskyttelser' => null])],
	'a person whose name is not text' => ['ejendePersonBegraenset' => person(['navn' => ['navn' => 42]])],
	'a person whose address is not an object' => ['ejendePersonBegraenset' => person(['cprAdresse' => 'Eksempelvej 12'])],
	'a CVR number that is not a number' => ['ejendeVirksomhedCVRNr' => '12345678'],
	'owner information that is not an object' => ['oplysningerEjesAfEjerskab' => 'Boet'],
] as $invalid => $values) {
	test('An ownership with ' . $invalid . ' is rejected', function () use ($values): void {
		scriptWithToken([owners([ownership(3001, 'own-bad', $values)])]);
		throws(InvalidResponseException::class, fn() => ejf()->getOwnerships([3001]));
	});
}


// ----------------------------------------------------------------
// Transfers
// ----------------------------------------------------------------

test('Transfers come oldest first by takeover date, unknown dates first, and rolled-back transfers are left out', function (): void {
	check(
		\array_keys(transfersOfEveryKind()) === ['tr-placeholder', 'tr-sale', 'tr-deed'],
		'Transfers come oldest first by takeover date, unknown dates first; rolled-back ones are left out.'
	);
});

test('A transfer keeps its dates as Danish dates, and its trade lists each current property of the trade once', function (): void {
	check(transfersOfEveryKind()['tr-sale'] === [
		'id' => 'tr-sale',
		'bfeNumber' => 4001,
		'takeoverDate' => '2019-07-01',
		'filedDate' => '2019-07-11',
		'method' => 'Almindelig fri handel',
		'businessEvent' => 'Endeligt skøde',
		'businessProcess' => 'Hændelse fra Digital Tinglysning',
		'conditional' => false,
		'deadline' => null,
		'documentId' => 'doc-1',
		'trade' => [
			'id' => 'trade-1',
			'totalPrice' => 1250000.0,
			'cashPrice' => 1250000.5,
			'movablesPrice' => null,
			'currency' => 'DKK',
			'contractDate' => '2019-05-02',
			'disposalDate' => '2019-05-02',
			'paymentDate' => null,
			'buildings' => 'Enfamiliesejendom',
			'bfeNumbers' => [4001, 4002],
		],
	], 'A transfer keeps its dates as Danish dates, and its trade lists each current property of the trade once.');
});

test('A placeholder takeover date becomes null, a takeover after midnight keeps its Danish date, and a transfer without trade information has trade null', function (): void {
	$rows = transfersOfEveryKind();
	$placeholder = $rows['tr-placeholder'];
	$deed = $rows['tr-deed'];

	check(
		$placeholder['takeoverDate'] === null && $placeholder['filedDate'] === null && $placeholder['conditional'] === null
			&& $placeholder['trade']['totalPrice'] === 113000.0 && $placeholder['trade']['disposalDate'] === '1973-08-01',
		'A placeholder takeover date becomes null, and the disposal date of the trade stays.'
	);
	check(
		$deed['takeoverDate'] === '2023-03-15' && $deed['filedDate'] === '2023-05-17' && $deed['deadline'] === '2023-06-01'
			&& $deed['conditional'] === true && $deed['trade'] === null,
		'A takeover time after midnight keeps its Danish date, and a transfer without trade information has trade null.'
	);
});

test('A rolled-back transfer is left out before its values are read', function (): void {
	scriptWithToken([changes([transfer(4001, 'tr-rb', ['status' => 'tilbagerullet', 'overtagelsesdato' => 'not a time'])])]);

	check(ejf()->getTransfers([4001]) === [4001 => []], 'A rolled-back transfer is left out before its values are read.');
});

// One case per row: Each row replaces values of a valid transfer, and its key says what makes the
// transfer invalid.
foreach ([
	'a missing takeover date' => ['overtagelsesdato' => null],
	'a filing date without a time' => ['anmeldelsesdato' => '2019-07-11'],
	'a conditional flag that is not a boolean' => ['betinget' => 'false'],
	'a deadline that does not exist' => ['fristDato' => '2023-06-31'],
	'a trade price that is not a number' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => trade(['samletKoebesum' => '1250000'])],
	'a trade without its transfers' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => trade(['handelsoplysningerBeskriverEjerskifte' => null])],
	'a trade transfer without a BFE number' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => trade(['handelsoplysningerBeskriverEjerskifte' => ['nodes' => [['status' => 'gældende', 'bestemtFastEjendomBFENr' => null]]]])],
	'a missing status' => ['status' => null],
] as $invalid => $values) {
	test('A transfer with ' . $invalid . ' is rejected', function () use ($values): void {
		scriptWithToken([changes([transfer(4001, 'tr-bad', $values)])]);
		throws(InvalidResponseException::class, fn() => ejf()->getTransfers([4001]));
	});
}


// ----------------------------------------------------------------
// Query documents
// ----------------------------------------------------------------

test('Every query document sent fits the bundled flexibleCurrent v3 schema and queries only the ownership and transfer roots', function (): void {
	$schema = GraphqlSchema::fromFile(\dirname(__DIR__, 2) . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
	check($schema->hasField('Query', 'EJFCustom_EjerskabBegraenset') && $schema->hasInputField('EJF_EjerskifteFilterInput', 'bestemtFastEjendomBFENr'), 'The bundled schema parses.');

	$roots = [];
	foreach (\array_unique(transport()->sent) as $document) {
		$stats = $schema->check($document);
		check(\count($stats['roots']) === 1, 'Every request has exactly one root field.');
		$roots[$stats['roots'][0]] = true;
	}
	\ksort($roots);

	check(
		\array_keys($roots) === ['EJFCustom_EjerskabBegraenset', 'EJF_Ejerskifte'],
		'The service queries only the ownership and transfer roots that a private actor may use.'
	);
});

done();
