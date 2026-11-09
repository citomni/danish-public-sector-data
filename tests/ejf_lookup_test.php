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
 * EJF lookups: the Shared Secret flow of DatafordelerClient, the requests the Ejf service sends,
 * and the ownerships and transfers it returns, against a scripted transport. No network request
 * is made.
 *
 * This provider package intentionally has no package-local Composer vendor tree. The test
 * therefore supplies minimal doubles of the CitOmni App and BaseService contracts and of the
 * Curl exception, and runs the real Ejf service and DatafordelerClient on them. Every query
 * document that is sent is also checked against the bundled flexibleCurrent v3 schema: field and
 * argument names, filter fields, and one root field per request.
 */
namespace CitOmni\Kernel {
	// The App members that Ejf and DatafordelerClient read.
	class App {
		public function __construct(public object $cfg, public object $curl, public object $secrets) {
		}
	}
}

namespace CitOmni\Kernel\Service {
	use CitOmni\Kernel\App;

	// The BaseService construction contract: store the App and options, then call init().
	abstract class BaseService {
		protected App $app;
		protected array $options;

		public function __construct(App $app, array $options = []) {
			$this->app = $app;
			$this->options = $options;

			if (\method_exists($this, 'init')) {
				$this->init();
			}
		}
	}
}

namespace CitOmni\Infrastructure\Exception {
	// The Curl transport failure that DatafordelerClient translates.
	class CurlException extends \RuntimeException {
	}
}

namespace {
	use CitOmni\DanishPublicSectorData\Exception\AuthenticationException;
	use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
	use CitOmni\DanishPublicSectorData\Exception\RateLimitException;
	use CitOmni\DanishPublicSectorData\Exception\RemoteServiceException;
	use CitOmni\DanishPublicSectorData\Service\Ejf;
	use CitOmni\Infrastructure\Exception\CurlException;
	use CitOmni\Kernel\App;

	\set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
		throw new \ErrorException($message, 0, $severity, $file, $line);
	});

	$root = \dirname(__DIR__);

	require_once $root . '/src/Exception/PublicDataException.php';
	require_once $root . '/src/Exception/RemoteServiceException.php';
	require_once $root . '/src/Exception/AuthenticationException.php';
	require_once $root . '/src/Exception/RateLimitException.php';
	require_once $root . '/src/Exception/InvalidResponseException.php';
	require_once $root . '/src/Enum/DatafordelerAuthentication.php';
	require_once $root . '/src/Enum/EjfOwnerType.php';
	require_once $root . '/src/Support/DatafordelerClient.php';
	require_once $root . '/src/Service/Ejf.php';


	/**
	 * Parse the selection sets of one query document into a field tree.
	 *
	 * Notes:
	 * - Covers the documents Cvr builds: no fragments, aliases or variables.
	 *
	 * @param  string  $document  GraphQL query document.
	 * @return list<array{name:string,arguments:list<string>,children:list<array>}>  Root fields.
	 */
	function parseQueryDocument(string $document): array {
		\preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_]*|\S/', $document, $matches);
		$tokens = $matches[0];
		$position = \array_search('{', $tokens, true);
		if (!\is_int($position)) {
			throw new \RuntimeException('FAIL: Query document has no selection set.');
		}

		$fields = parseSelectionSet($tokens, $position);
		if ($position !== \count($tokens)) {
			throw new \RuntimeException('FAIL: Query document continues after its selection set.');
		}

		return $fields;
	}

	/**
	 * Parse one selection set, starting at its opening brace.
	 *
	 * @param  list<string>  $tokens  Tokens of the document.
	 * @param  int  $position  Index of the opening brace; set to the index after the closing brace.
	 * @return list<array{name:string,arguments:list<string>,children:list<array>}>  Fields of the set.
	 */
	function parseSelectionSet(array $tokens, int &$position): array {
		if (($tokens[$position] ?? null) !== '{') {
			throw new \RuntimeException('FAIL: Selection set expected at token ' . $position . '.');
		}
		$position++;

		$fields = [];
		while (($tokens[$position] ?? null) !== '}') {
			$name = $tokens[$position++] ?? '';
			if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
				throw new \RuntimeException('FAIL: Field name expected, got "' . $name . '".');
			}

			$arguments = [];
			if (($tokens[$position] ?? null) === '(') {
				$depth = 0;
				do {
					$token = $tokens[$position++] ?? throw new \RuntimeException('FAIL: Unclosed arguments of ' . $name . '.');
					$depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
					$arguments[] = $token;
				} while ($depth > 0);
			}

			$children = ($tokens[$position] ?? null) === '{' ? parseSelectionSet($tokens, $position) : [];
			$fields[] = ['name' => $name, 'arguments' => $arguments, 'children' => $children];
		}
		$position++;

		return $fields;
	}

	/**
	 * Check a field tree against the schema and count its joins.
	 *
	 * @param  list<array{name:string,arguments:list<string>,children:list<array>}>  $fields  Field tree.
	 * @param  string  $type  Schema type the fields belong to.
	 * @param  array{type:array<string,array<string,array{type:string,arguments:string}>>,input:array<string,array<string,array{type:string,arguments:string}>>}  $schema  Parsed schema.
	 * @return array{joins:int,depth:int}  Join fields in the tree, and the longest chain of nested joins.
	 */
	function checkFields(array $fields, string $type, array $schema): array {
		$joins = 0;
		$depth = 0;

		foreach ($fields as $field) {
			$path = $type . '.' . $field['name'];
			$definition = $schema['type'][$type][$field['name']] ?? null;
			if ($definition === null) {
				throw new \RuntimeException('FAIL: The schema has no field ' . $path . '.');
			}

			// Top-level argument names, the filter fields inside where, and their operators.
			$braces = 0;
			$inWhere = false;
			$filterInput = '';
			$filterType = '';
			foreach ($field['arguments'] as $index => $token) {
				if ($token === '{' || $token === '}') {
					$braces += $token === '{' ? 1 : -1;
					$inWhere = $inWhere && $braces > 0;
					continue;
				}
				if (($field['arguments'][$index + 1] ?? null) !== ':' || !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token)) {
					continue;
				}

				if ($braces === 0) {
					if (!\preg_match('/[(\s]' . $token . ': /', $definition['arguments'])) {
						throw new \RuntimeException('FAIL: ' . $path . ' has no argument ' . $token . '.');
					}
					$inWhere = $token === 'where';
					$filterInput = \preg_match('/[(\s]where: ([A-Za-z0-9_]+)/', $definition['arguments'], $match) === 1 ? $match[1] : '';
				} elseif ($inWhere && $braces === 1) {
					$filterType = $schema['input'][$filterInput][$token]['type']
						?? throw new \RuntimeException('FAIL: The where filter of ' . $path . ' has no field ' . $token . '.');
				} elseif ($inWhere && $braces === 2 && !isset($schema['input'][$filterType][$token])) {
					throw new \RuntimeException('FAIL: The where filter of ' . $path . ' has no operator ' . $token . ' in ' . $filterType . '.');
				}
			}

			$isObject = isset($schema['type'][$definition['type']]);
			if ($isObject === ($field['children'] === [])) {
				throw new \RuntimeException('FAIL: ' . $path . ' has a selection set that does not fit its type.');
			}

			$nested = $isObject ? checkFields($field['children'], $definition['type'], $schema) : ['joins' => 0, 'depth' => 0];
			$isJoin = \str_ends_with($field['name'], '_ref') ? 1 : 0;
			$joins += $nested['joins'] + $isJoin;
			$depth = \max($depth, $nested['depth'] + $isJoin);
		}

		return ['joins' => $joins, 'depth' => $depth];
	}

	$checks = 0;
	$check = static function (bool $condition, string $message) use (&$checks): void {
		if (!$condition) {
			throw new \RuntimeException('FAIL: ' . $message);
		}

		++$checks;
	};
	$throws = static function (callable $callback, string $class, string $message) use (&$checks): void {
		try {
			$callback();
		} catch (\Throwable $exception) {
			if (!$exception instanceof $class) {
				throw new \RuntimeException('FAIL: ' . $message . ' Got ' . $exception::class . ': ' . $exception->getMessage());
			}

			++$checks;
			return;
		}

		throw new \RuntimeException('FAIL: ' . $message . ' Nothing was thrown.');
	};

	// ----------------------------------------------------------------
	// Doubles and fixtures
	// ----------------------------------------------------------------

	$tokenUrl = 'https://auth.datafordeler.dk/realms/distribution/protocol/openid-connect/token';
	$graphqlUrl = 'https://graphql.datafordeler.dk/flexibleCurrent/v3';

	// Answers every request with the next scripted answer, or throws a scripted exception.
	$curl = new class {
		/** @var list<array<string,mixed>> Requests of the current case. */
		public array $requests = [];

		/** @var list<string> Query documents of every case. */
		public array $sent = [];

		/** @var list<array{status:int,body:string}|\Throwable> Answers for the coming requests. */
		public array $responses = [];

		public function execute(array $request): array {
			$this->requests[] = $request;

			if (\str_ends_with((string)$request['url'], '/flexibleCurrent/v3')) {
				$this->sent[] = (string)\json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR)['query'];
			}

			if ($this->responses === []) {
				throw new \RuntimeException('FAIL: Unexpected request to ' . $request['url']);
			}

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

	// Holds the secrets of the current case; a missing key throws like the secrets service.
	$secrets = new class {
		/** @var array<string,string> Secrets by key. */
		public array $values = [];

		public function get(string $key): string {
			return \array_key_exists($key, $this->values) ? $this->values[$key] : throw new \OutOfBoundsException($key);
		}
	};

	$makeCfg = static fn(string $tokenUrl, string $service = 'flexibleCurrent'): object => (object)[
		'danish_public_sector_data' => (object)[
			'datafordeler' => (object)[
				'base_url' => 'https://graphql.datafordeler.dk',
				'token_url' => $tokenUrl,
				'timeout' => 15,
				'connect_timeout' => 5,
			],
			'ejf' => (object)[
				'service' => $service,
				'version' => 'v3',
			],
		],
	];

	$app = new App($makeCfg($tokenUrl), $curl, $secrets);

	// Each case that needs a fresh token uses a client id of its own: Access tokens live for the process.
	$clientNumber = 0;
	$newClient = static function () use ($secrets, &$clientNumber): void {
		$clientNumber++;
		$secrets->values = [
			'datafordeler.oauth_client_id' => 'client-' . $clientNumber,
			'datafordeler.oauth_client_secret' => 'secret&' . $clientNumber,
		];
	};

	$script = static function (array $responses) use ($curl): void {
		$curl->requests = [];
		$curl->responses = $responses;
	};
	$token = static fn(string $token, ?int $expiresIn = 3600): array => [
		'status' => 200,
		'body' => \json_encode(
			$expiresIn === null ? ['access_token' => $token, 'token_type' => 'Bearer'] : ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiresIn],
			\JSON_THROW_ON_ERROR
		),
	];
	$answer = static fn(int $status, string $body = '{}'): array => ['status' => $status, 'body' => $body];
	$page = static fn(string $root, array $nodes, ?string $cursor = null): array => $answer(200, \json_encode(
		['data' => [$root => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $cursor !== null, 'endCursor' => $cursor]]]],
		\JSON_THROW_ON_ERROR
	));
	$owners = static fn(array $nodes, ?string $cursor = null): array => $page('EJFCustom_EjerskabBegraenset', $nodes, $cursor);
	$changes = static fn(array $nodes, ?string $cursor = null): array => $page('EJF_Ejerskifte', $nodes, $cursor);

	$requestsTo = static fn(string $url): array => \array_values(\array_filter($curl->requests, static fn(array $request): bool => $request['url'] === $url));
	$queryOf = static fn(array $request): string => (string)\json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR)['query'];
	$numbersIn = static function (string $query): array {
		if (!\preg_match('/where: \{ bestemtFastEjendomBFENr: \{ in: \[([0-9, ]*)\] \} \}/', $query, $match)) {
			return [];
		}

		return \array_map('intval', \explode(', ', $match[1]));
	};
	$effectTime = static fn(string $query): ?string => \preg_match('/virkningstid: "([^"]+)"/', $query, $match) ? $match[1] : null;

	$emptyCprAddress = [
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
	$person = static fn(array $values = []): array => \array_replace([
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
	$ownership = static fn(int $bfeNumber, string $id, array $values = []): array => \array_replace([
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
	$trade = static fn(array $values = []): array => \array_replace([
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
	$transfer = static fn(int $bfeNumber, string $id, array $values = []): array => \array_replace([
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

	$newClient();
	$ejf = new Ejf($app);

	// -- 1. Shared Secret: token request and bearer token -------------------
	$script([$token('token-a'), $owners([])]);
	$check($ejf->getOwnerships([1001]) === [1001 => []], 'A property without ownerships has an empty list.');
	[$tokenRequest, $queryRequest] = $curl->requests + [null, null];
	$check(\count($curl->requests) === 2 && $tokenRequest['url'] === $tokenUrl, 'The first lookup asks the token endpoint first.');
	\parse_str((string)$tokenRequest['body'], $form);
	$check($form === ['grant_type' => 'client_credentials', 'client_id' => 'client-1', 'client_secret' => 'secret&1'], 'The token request sends the client credentials grant with the client id and secret.');
	$check($tokenRequest['method'] === 'POST' && \in_array('Content-Type: application/x-www-form-urlencoded', $tokenRequest['headers'], true), 'The token request is a form POST.');
	$check(!isset($tokenRequest['query']) && !\str_contains((string)$tokenRequest['url'], 'secret'), 'The client secret stays out of the token URL.');
	$check($queryRequest['url'] === $graphqlUrl && ($queryRequest['bearer_token'] ?? null) === 'token-a', 'The query carries the access token as a bearer token.');
	$check(!isset($queryRequest['query']) && !isset($queryRequest['sensitive_query_keys']), 'A Shared Secret query sends no API key.');
	$check(\in_array('Accept: application/graphql-response+json', $queryRequest['headers'], true), 'The query asks for a GraphQL response, so a refusal does not answer 200.');

	// -- 2. Token reuse and renewal --------------------------------------------
	$script([$owners([])]);
	$ejf->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 0 && \count($requestsTo($graphqlUrl)) === 1, 'A valid token is reused.');

	$script([$owners([])]);
	(new Ejf($app))->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 0, 'Another client of the process with the same client id reuses the token.');

	$newClient();
	$script([$token('token-b', 30), $owners([]), $token('token-c'), $owners([])]);
	$ejf->getOwnerships([1001]);
	$ejf->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 2 && ($requestsTo($graphqlUrl)[1]['bearer_token'] ?? null) === 'token-c', 'A token that expires within the renewal margin is replaced before the next request.');

	$newClient();
	$script([$token('token-d', null), $owners([]), $owners([])]);
	$ejf->getOwnerships([1001]);
	$ejf->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 1, 'A token answer without expires_in gives the token the documented lifetime.');

	// -- 3. Credential and token failures ------------------------------------
	$newClient();
	unset($secrets->values['datafordeler.oauth_client_id']);
	$script([]);
	$throws(static fn() => $ejf->getOwnerships([1001]), AuthenticationException::class, 'A missing client id fails the lookup.');
	$check($curl->requests === [], 'Nothing is sent without a client id.');

	$newClient();
	$secrets->values['datafordeler.oauth_client_secret'] = '';
	$script([]);
	$throws(static fn() => $ejf->getOwnerships([1001]), AuthenticationException::class, 'An empty client secret fails the lookup.');
	$check($curl->requests === [], 'Nothing is sent with an empty client secret.');

	foreach ([
		'A 401 from the token endpoint' => [$answer(401, '{"error":"invalid_client"}'), AuthenticationException::class],
		'A 400 from the token endpoint' => [$answer(400, '{"error":"unauthorized_client"}'), AuthenticationException::class],
		'A 429 from the token endpoint' => [$answer(429), RateLimitException::class],
		'A 500 from the token endpoint' => [$answer(500), RemoteServiceException::class],
		'A token answer that is not JSON' => [$answer(200, 'not json'), InvalidResponseException::class],
		'A token answer without access_token' => [$answer(200, '{"token_type":"Bearer"}'), InvalidResponseException::class],
		'A token with a line break' => [$token("token\r\nX-Injected: 1"), InvalidResponseException::class],
		'A transport failure of the token request' => [new CurlException('Timeout.'), RemoteServiceException::class],
	] as $label => [$failure, $class]) {
		$newClient();
		$script([$failure]);
		$throws(static fn() => $ejf->getOwnerships([1001]), $class, $label . ' fails the lookup.');
		$check(\count($curl->requests) === 1, $label . ' sends no query.');
	}

	$newClient();
	$script([$token('token-e'), $answer(401), $token('token-f'), $owners([])]);
	$throws(static fn() => $ejf->getOwnerships([1001]), AuthenticationException::class, 'A 401 on the query fails the lookup.');
	$ejf->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 2 && ($requestsTo($graphqlUrl)[1]['bearer_token'] ?? null) === 'token-f', 'A rejected query discards its token, and the next lookup asks for a new one.');

	$script([$answer(429), $owners([])]);
	$throws(static fn() => $ejf->getOwnerships([1001]), RateLimitException::class, 'A 429 on the query fails the lookup.');
	$ejf->getOwnerships([1001]);
	$check(\count($requestsTo($tokenUrl)) === 0, 'A rate-limited query keeps its token.');

	$throws(static fn() => new Ejf(new App($makeCfg('http://auth.datafordeler.dk/token'), $curl, $secrets)), \UnexpectedValueException::class, 'A token URL that is not HTTPS is rejected when the service is created.');
	$throws(static fn() => new Ejf(new App($makeCfg($tokenUrl, ''), $curl, $secrets)), \UnexpectedValueException::class, 'An empty EJF service is rejected when the service is created.');

	// -- 4. Input ---------------------------------------------------------------
	$script([]);
	$check($ejf->getOwnerships([]) === [] && $ejf->getTransfers([]) === [] && $curl->requests === [], 'No numbers give no entries and no request.');
	foreach (['0', '012', -1, 0, '1.5', 1.5, 'abc', ' 12', '12 ', '99999999999999999999', null, true] as $invalid) {
		$throws(static fn() => $ejf->getOwnerships([1001, $invalid]), \InvalidArgumentException::class, 'BFE number ' . \var_export($invalid, true) . ' is rejected.');
		$throws(static fn() => $ejf->getTransfers([$invalid]), \InvalidArgumentException::class, 'BFE number ' . \var_export($invalid, true) . ' is rejected for transfers.');
	}
	$check($curl->requests === [], 'Invalid numbers are rejected before any request.');

	// -- 5. Batches, pages and effect time ----------------------------------------
	$numbers = \range(2001, 2150);
	$script([$owners([$ownership(2001, 'own-a')]), $owners([$ownership(2150, 'own-b')])]);
	$result = $ejf->getOwnerships(\array_merge($numbers, ['2001', 2002]));
	$queries = \array_map($queryOf, $requestsTo($graphqlUrl));
	$check(\count($queries) === 2 && $numbersIn($queries[0]) === \range(2001, 2100) && $numbersIn($queries[1]) === \range(2101, 2150), '150 distinct numbers go out in batches of 100 and 50, each number once.');
	$check(\array_keys($result) === $numbers, 'Entries are keyed by number in the order the numbers first occur.');
	$check(\count($result[2001]) === 1 && \count($result[2150]) === 1 && $result[2002] === [], 'Rows land under their own number, and the others are empty.');
	$check($effectTime($queries[0]) !== null && $effectTime($queries[0]) === $effectTime($queries[1]), 'Every request of one call has the same effect time.');
	$check(\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)$effectTime($queries[0])) === 1, 'The effect time is UTC.');
	$check(\str_contains($queries[0], "first: 100\n") && !\str_contains($queries[0], 'after:'), 'A first page asks for 100 rows without a cursor.');

	$script([$owners([$ownership(2001, 'own-a')], 'cursor-1'), $owners([$ownership(2001, 'own-c'), $ownership(2001, 'own-a', ['status' => 'historisk'])])]);
	$result = $ejf->getOwnerships([2001]);
	$queries = \array_map($queryOf, $requestsTo($graphqlUrl));
	$check(\count($queries) === 2 && \str_contains($queries[1], 'after: "cursor-1"'), 'The next page is requested with the cursor of the page before.');
	$check(\array_column($result[2001], 'id') === ['own-a', 'own-c'] && $result[2001][0]['current'], 'A row repeated on a later page is kept once, as it came first.');

	$script([$owners([], 'cursor-2'), $owners([], 'cursor-2')]);
	$throws(static fn() => $ejf->getOwnerships([2001]), InvalidResponseException::class, 'A cursor that comes back is rejected.');
	$script([$answer(200, '{"data":{"EJFCustom_EjerskabBegraenset":{"nodes":[],"pageInfo":{"hasNextPage":true,"endCursor":null}}}}')]);
	$throws(static fn() => $ejf->getOwnerships([2001]), InvalidResponseException::class, 'A next page without a cursor is rejected.');
	$script([$owners([$ownership(9999, 'own-z')])]);
	$throws(static fn() => $ejf->getOwnerships([2001]), InvalidResponseException::class, 'A row of a property that was not requested is rejected.');
	$script([$answer(200, '{"data":{"EJFCustom_EjerskabBegraenset":null}}')]);
	$throws(static fn() => $ejf->getOwnerships([2001]), InvalidResponseException::class, 'An answer without the connection is rejected.');
	$script([$answer(200, '{"errors":[{"message":"refused"}],"data":null}')]);
	$throws(static fn() => $ejf->getOwnerships([2001]), RemoteServiceException::class, 'GraphQL errors fail the lookup.');

	// -- 6. Ownerships ------------------------------------------------------------
	$script([$owners([
		$ownership(3001, 'own-person', ['ejendePersonBegraenset' => $person(), 'tinglystEjerandel_taeller' => null, 'tinglystEjerandel_naevner' => null]),
		$ownership(3001, 'own-protected', [
			'status' => 'historisk',
			'virkningFra' => '2005-12-31T23:00:00.000000Z',
			'faktiskEjerandel_naevner' => 2,
			'primaerKontakt' => false,
			'ejendePersonBegraenset' => $person([
				'id' => 'person-2',
				'navn' => null,
				'standardadresse' => null,
				'cprAdresse' => null,
				'adresseoplysninger' => null,
				'beskyttelser' => [['beskyttelsestype' => 'navne_og_adresse']],
			]),
		]),
		$ownership(3001, 'own-deceased', [
			'virkningFra' => '1799-12-31T23:00:00.000000Z',
			'ejendePersonBegraenset' => $person([
				'id' => 'person-3',
				'status' => 'doed',
				'statusdato' => '2026-10-20',
				'standardadresse' => '',
				'cprAdresse' => $emptyCprAddress,
				'udrejseIndrejse' => ['udlandsadresselinje1' => null, 'udlandsadresselinje2' => null, 'udlandsadresselinje3' => null, 'udlandsadresselinje4' => null, 'udlandsadresselinje5' => null, 'cprLandUdrejse' => null],
			]),
		]),
		$ownership(3001, 'own-emigrated', [
			'ejendePersonBegraenset' => $person([
				'id' => 'person-4',
				'status' => 'udrejst',
				'standardadresse' => '',
				'cprAdresse' => $emptyCprAddress,
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
		$ownership(3001, 'own-company', ['ejerforholdskode' => '30', 'ejendeVirksomhedCVRNr' => 12345678]),
		$ownership(3001, 'own-other', [
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
		$ownership(3001, 'own-other-id', ['ejeroplysningerLokalId' => 'pv-1']),
		$ownership(3001, 'own-unknown', ['ejerforholdskode' => '99', 'virkningFra' => '1969-12-31T23:00:00Z', 'forretningshaendelse' => ' ']),
	])]);
	$rows = $ejf->getOwnerships([3001])[3001];
	$byId = \array_column($rows, null, 'id');

	$check(
		\array_column($rows, 'id') === ['own-deceased', 'own-unknown', 'own-protected', 'own-company', 'own-emigrated', 'own-other', 'own-other-id', 'own-person'],
		'Ownerships come oldest first by validFrom, unknown dates first, then by id.'
	);
	$check($byId['own-person'] === [
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

	$protected = $byId['own-protected'];
	$check(
		$protected['current'] === false && $protected['validFrom'] === '2006-01-01' && $protected['share'] === ['numerator' => 1, 'denominator' => 2] && $protected['primaryContact'] === false,
		'A former ownership has current false, its share unreduced, and a winter instant becomes the next Danish date.'
	);
	$check(
		$protected['person']['name'] === null && $protected['person']['address'] === null && $protected['person']['addressingName'] === null && $protected['person']['protections'] === ['navne_og_adresse'],
		'A person with name and address protection has no name, address or addressing name.'
	);
	$check($byId['own-deceased']['validFrom'] === null && $byId['own-unknown']['validFrom'] === null, 'The placeholders of 1800 and 1970 become null.');
	$check(
		$byId['own-deceased']['person']['status'] === 'doed' && $byId['own-deceased']['person']['statusDate'] === '2026-10-20'
			&& $byId['own-deceased']['person']['address'] === null && $byId['own-deceased']['person']['foreignAddress'] === null,
		'A deceased person has the date of death as status date, and an empty address becomes null.'
	);
	$check(
		$byId['own-emigrated']['person']['address'] === null
			&& $byId['own-emigrated']['person']['foreignAddress'] === ['lines' => ['1 Example Street', 'London'], 'country' => 'Storbritannien', 'countryCode' => 'GB'],
		'An emigrated person has the address abroad without empty lines.'
	);
	$check(
		$byId['own-company']['ownerType'] === 'company' && $byId['own-company']['companyCvr'] === '12345678' && $byId['own-company']['person'] === null,
		'A company owner has its CVR number as a string.'
	);
	$check(
		$byId['own-other']['ownerType'] === 'other'
			&& $byId['own-other']['other'] === ['name' => 'Boet efter Example Owner', 'addressLines' => ['c/o Example Law', 'Example Street 1', 'Copenhagen'], 'countryCode' => '208'],
		'An owner without CPR or CVR number has its name and the address lines that hold text.'
	);
	$check($byId['own-other-id']['ownerType'] === 'other' && $byId['own-other-id']['other'] === null, 'Owner information that EJF names but does not join still makes an other owner.');
	$check($byId['own-unknown']['ownerType'] === 'unknown' && $byId['own-unknown']['businessEvent'] === null, 'An ownership without an owner is unknown, and a blank text becomes null.');

	$script([$owners([$ownership(3002, 'own-city', ['ejendePersonBegraenset' => $person([
		'standardadresse' => null,
		'cprAdresse' => \array_replace($emptyCprAddress, ['vejadresseringsnavn' => 'Eksempelvej', 'husnummer' => '12', 'bynavn' => 'Lind', 'postnummer' => '7400', 'postdistrikt' => 'Herning']),
	])])])]);
	$address = $ejf->getOwnerships([3002])[3002][0]['person']['address'];
	$check($address['line'] === null && $address['formatted'] === 'Eksempelvej 12, Lind, 7400 Herning', 'Without a CPR line, the formatted address starts with street and house number and names the city.');

	foreach ([
		'A missing id' => ['id_lokalId' => null],
		'A BFE number as a string' => ['bestemtFastEjendomBFENr' => '3001'],
		'A missing status' => ['status' => null],
		'A share with one side missing' => ['tinglystEjerandel_taeller' => 1, 'tinglystEjerandel_naevner' => null],
		'A share with denominator 0' => ['faktiskEjerandel_naevner' => 0],
		'A negative share' => ['faktiskEjerandel_taeller' => -1],
		'An effect start without an offset' => ['virkningFra' => '2019-07-01T00:00:00'],
		'An effect start that is not a time' => ['virkningFra' => 'yesterday'],
		'A missing effect start' => ['virkningFra' => null],
		'A primary contact flag that is not a boolean' => ['primaerKontakt' => 'true'],
		'A person without a status date' => ['ejendePersonBegraenset' => $person(['statusdato' => null])],
		'A birth date that does not exist' => ['ejendePersonBegraenset' => $person(['foedselsdato' => '1950-02-30'])],
		'Protections that are not a list' => ['ejendePersonBegraenset' => $person(['beskyttelser' => null])],
		'A name that is not text' => ['ejendePersonBegraenset' => $person(['navn' => ['navn' => 42]])],
		'An address that is not an object' => ['ejendePersonBegraenset' => $person(['cprAdresse' => 'Eksempelvej 12'])],
		'A CVR number that is not a number' => ['ejendeVirksomhedCVRNr' => '12345678'],
		'Owner information that is not an object' => ['oplysningerEjesAfEjerskab' => 'Boet'],
	] as $label => $values) {
		$script([$owners([$ownership(3001, 'own-bad', $values)])]);
		$throws(static fn() => $ejf->getOwnerships([3001]), InvalidResponseException::class, $label . ' is rejected.');
	}

	// -- 7. Transfers -------------------------------------------------------------------
	$script([$changes([
		$transfer(4001, 'tr-sale', ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => $trade([
			'handelsoplysningerBeskriverEjerskifte' => ['nodes' => [
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4002],
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4001],
				['status' => 'tilbagerullet', 'bestemtFastEjendomBFENr' => 4003],
				['status' => 'gældende', 'bestemtFastEjendomBFENr' => 4001],
			]],
		])]),
		$transfer(4001, 'tr-rolled-back', ['status' => 'tilbagerullet', 'overtagelsesdato' => '2020-01-01T23:00:00Z']),
		$transfer(4001, 'tr-placeholder', [
			'overtagelsesdato' => '1799-12-31T23:00:00.000000Z',
			'anmeldelsesdato' => null,
			'anmeldelsesidentifikator' => null,
			'overdragelsesmaade' => 'Anden overdragelse',
			'betinget' => null,
			'handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => $trade([
				'id_lokalId' => 'trade-2',
				'samletKoebesum' => 113000,
				'kontantKoebesum' => null,
				'valutakode' => null,
				'koebsaftaleDato' => null,
				'afstaaelsesdato' => '1973-08-01',
				'bygningerOmfattet' => null,
			]),
		]),
		$transfer(4001, 'tr-deed', [
			'overtagelsesdato' => '2023-03-14T23:10:00.000000Z',
			'anmeldelsesdato' => '2023-05-16T22:00:00.000000Z',
			'overdragelsesmaade' => 'Anden overdragelse',
			'forretningshaendelse' => 'Auktionsskøde',
			'fristDato' => '2023-06-01',
			'betinget' => true,
		]),
	])]);
	$rows = $ejf->getTransfers([4001])[4001];
	$byId = \array_column($rows, null, 'id');

	$check(\array_column($rows, 'id') === ['tr-placeholder', 'tr-sale', 'tr-deed'], 'Transfers come oldest first by takeover date, unknown dates first; rolled-back ones are left out.');
	$check($byId['tr-sale'] === [
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
	$check(
		$byId['tr-placeholder']['takeoverDate'] === null && $byId['tr-placeholder']['filedDate'] === null && $byId['tr-placeholder']['conditional'] === null
			&& $byId['tr-placeholder']['trade']['totalPrice'] === 113000.0 && $byId['tr-placeholder']['trade']['disposalDate'] === '1973-08-01',
		'A placeholder takeover date becomes null, and the disposal date of the trade stays.'
	);
	$check(
		$byId['tr-deed']['takeoverDate'] === '2023-03-15' && $byId['tr-deed']['filedDate'] === '2023-05-17' && $byId['tr-deed']['deadline'] === '2023-06-01'
			&& $byId['tr-deed']['conditional'] === true && $byId['tr-deed']['trade'] === null,
		'A takeover time after midnight keeps its Danish date, and a transfer without trade information has trade null.'
	);

	$script([$changes([$transfer(4001, 'tr-rb', ['status' => 'tilbagerullet', 'overtagelsesdato' => 'not a time'])])]);
	$check($ejf->getTransfers([4001]) === [4001 => []], 'A rolled-back transfer is left out before its values are read.');

	foreach ([
		'A missing takeover date' => ['overtagelsesdato' => null],
		'A filing date without a time' => ['anmeldelsesdato' => '2019-07-11'],
		'A conditional flag that is not a boolean' => ['betinget' => 'false'],
		'A deadline that does not exist' => ['fristDato' => '2023-06-31'],
		'A price that is not a number' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => $trade(['samletKoebesum' => '1250000'])],
		'A trade without its transfers' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => $trade(['handelsoplysningerBeskriverEjerskifte' => null])],
		'A trade transfer without a BFE number' => ['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref' => $trade(['handelsoplysningerBeskriverEjerskifte' => ['nodes' => [['status' => 'gældende', 'bestemtFastEjendomBFENr' => null]]]])],
		'A missing status' => ['status' => null],
	] as $label => $values) {
		$script([$changes([$transfer(4001, 'tr-bad', $values)])]);
		$throws(static fn() => $ejf->getTransfers([4001]), InvalidResponseException::class, $label . ' is rejected.');
	}

	// -- 8. Query documents against the bundled schema ------------------------------------
	$schemaSource = (string)\file_get_contents($root . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
	$schemaSource = (string)\preg_replace('/""".*?"""\s*/s', '', $schemaSource);
	\preg_match_all('/^(type|input) ([A-Za-z0-9_]+)(?:\s+implements\s+[^\{]+)?\s*\{\R(.*?)^\}/ms', $schemaSource, $blocks, \PREG_SET_ORDER);
	$schema = ['type' => [], 'input' => []];
	foreach ($blocks as $block) {
		foreach (\preg_split('/\R/', $block[3]) ?: [] as $line) {
			if (\preg_match('/^\s{2}([A-Za-z_][A-Za-z0-9_]*)(\(.*\))?:\s*([^ @]+)/', $line, $field)) {
				$schema[$block[1]][$block[2]][$field[1]] = ['type' => \trim($field[3], '[]!'), 'arguments' => $field[2]];
			}
		}
	}
	$check(isset($schema['type']['Query']['EJFCustom_EjerskabBegraenset'], $schema['input']['EJF_EjerskifteFilterInput']['bestemtFastEjendomBFENr']), 'The bundled schema parses.');

	$roots = [];
	foreach (\array_unique($curl->sent) as $document) {
		$tree = parseQueryDocument($document);
		$check(\count($tree) === 1, 'Every request has exactly one root field.');

		checkFields($tree, 'Query', $schema);
		$roots[$tree[0]['name']] = true;
	}
	$check(
		\array_keys($roots) === ['EJFCustom_EjerskabBegraenset', 'EJF_Ejerskifte'],
		'The service queries only the ownership and transfer roots that a private actor may use.'
	);

	\fwrite(
		STDOUT,
		'PASS ' . $checks . ' checks. EJF ownerships, transfers and the Shared Secret flow against the flexibleCurrent v3 schema. PHP '
		. \PHP_VERSION . '.' . \PHP_EOL
	);
}
