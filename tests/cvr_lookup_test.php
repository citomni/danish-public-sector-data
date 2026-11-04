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
 * CVR lookups: the requests the Cvr service sends and the records it returns, against a
 * scripted transport. No network request is made.
 *
 * This provider package intentionally has no package-local Composer vendor tree. The test
 * therefore supplies minimal doubles of the CitOmni App and BaseService contracts and runs the
 * real Cvr service and DatafordelerClient on them. Every query document that is sent is also
 * checked against the bundled flexibleCurrent v3 schema: field and argument names, filter
 * fields, one root field per request, and at most 17 joins in total and 7 in depth.
 */
namespace CitOmni\Kernel {
	// The App members that Cvr and DatafordelerClient read.
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

namespace {
	use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
	use CitOmni\DanishPublicSectorData\Service\Cvr;
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
	require_once $root . '/src/Support/DatafordelerClient.php';
	require_once $root . '/src/Service/Cvr.php';

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

	// Answers every request with the next scripted GraphQL data object.
	$curl = new class {
		/** @var list<array{url:string,query:string}> Requests of the current case. */
		public array $requests = [];

		/** @var list<string> Query documents of every case. */
		public array $sent = [];

		/** @var list<array<string,mixed>> GraphQL data objects for the coming requests. */
		public array $responses = [];

		/** Number of coming requests after which the answer waits for the next wall-clock second. */
		public int $secondBoundaries = 0;

		public function execute(array $request): array {
			$payload = \json_decode((string)$request['body'], true, 512, \JSON_THROW_ON_ERROR);
			$this->requests[] = ['url' => (string)$request['url'], 'query' => (string)$payload['query']];
			$this->sent[] = (string)$payload['query'];

			if ($this->responses === []) {
				throw new \RuntimeException('FAIL: Unexpected request: ' . $payload['query']);
			}

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

	$secrets = new class {
		public function get(string $key): string {
			return $key === 'datafordeler.api_key' ? 'test-key' : throw new \OutOfBoundsException($key);
		}
	};

	$cfg = (object)[
		'danish_public_sector_data' => (object)[
			'datafordeler' => (object)[
				'base_url' => 'https://graphql.datafordeler.dk',
				'timeout' => 15,
				'connect_timeout' => 5,
			],
			'cvr' => (object)[
				'service' => 'flexibleCurrent',
				'version' => 'v3',
			],
		],
	];

	$cvr = new Cvr(new App($cfg, $curl, $secrets));

	$script = static function (array $responses, int $secondBoundaries = 0) use ($curl): void {
		$curl->requests = [];
		$curl->responses = $responses;
		$curl->secondBoundaries = $secondBoundaries;
	};
	$queries = static fn(): array => \array_column($curl->requests, 'query');
	$pageSize = static fn(string $query): ?int => \preg_match('/^\t\tfirst: ([0-9]+)$/m', $query, $match) ? (int)$match[1] : null;
	$numbersIn = static function (string $query): array {
		if (!\preg_match('/where: \{ [A-Za-z]+: \{ in: \[([0-9, ]*)\] \} \}/', $query, $match)) {
			return [];
		}

		return \array_map('intval', \explode(', ', $match[1]));
	};
	$effectTime = static fn(string $query): ?string => \preg_match('/virkningstid: "([^"]+)"/', $query, $match) ? $match[1] : null;

	$entity = static fn(string $name, array $relations = []): array => [
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
	$address = static fn(string $use, string $street, string $houseNumber, string $postalCode, string $city): array => [
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
	$company = static fn(int $cvrNumber, string $name, array $relations = []): array => [
		'status' => 'aktiv',
		'CVRNummer' => $cvrNumber,
		'virksomhedStartdato' => '2019-03-28',
		'virksomhedOphoersdato' => null,
		'id_CVR_CVREnhed_id_ref' => $entity($name, $relations),
		'id_CVR_Virksomhedsform_CVREnhedsId_ref' => ['vaerdi' => '80', 'vaerdiTekst' => 'Anpartsselskab'],
	];
	$unit = static fn(int $pNumber, int $cvrNumber, string $name, array $relations = []): array => [
		'pNummer' => $pNumber,
		'tilknyttetVirksomhedsCVRNummer' => $cvrNumber,
		'status' => 'aktiv',
		'produktionsenhedStartdato' => '2019-03-28',
		'produktionsenhedOphoersdato' => null,
		'tilknyttetTilVirksomhedStartdato' => '2019-04-01',
		'tilknyttetTilVirksomhedOphoersdato' => null,
		'id_CVR_CVREnhed_id_ref' => $entity($name, $relations),
	];
	$page = static fn(string $root, array $nodes, ?string $endCursor = null): array => [
		$root => [
			'nodes' => $nodes,
			'pageInfo' => ['hasNextPage' => $endCursor !== null, 'endCursor' => $endCursor],
		],
	];

	$companyKeys = ['cvrNumber', 'name', 'status', 'startDate', 'endDate', 'companyType', 'address', 'postalAddress', 'contact', 'industries', 'productionUnits'];
	$unitKeys = ['pNumber', 'cvrNumber', 'name', 'status', 'startDate', 'endDate', 'attachmentStartDate', 'attachmentEndDate', 'address', 'postalAddress', 'contact', 'industries'];

	// ----------------------------------------------------------------
	// Cases
	// ----------------------------------------------------------------

	// -- 1. One company with its production units ------------------------
	$script([
		$page('CVR_Virksomhed', [
			$company(40386270, 'Example ApS', [
				'id_CVR_Adressering_CVREnhedsId_ref' => ['nodes' => [
					$address('postadresse', 'Postboksvej', '1', '7400', 'Herning'),
					$address('beliggenhedsadresse', 'Eksempelvej', '12', '7400', 'Herning'),
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
		$page('CVR_Produktionsenhed', [
			$unit(1024552249, 40386270, 'Example ApS, lager'),
			$unit(1024552248, 40386270, 'Example ApS', [
				'id_CVR_Adressering_CVREnhedsId_ref' => ['nodes' => [
					$address('beliggenhedsadresse', 'Butiksgade', '3', '8000', 'Aarhus C'),
				]],
				'id_CVR_Branche_CVREnhedsId_ref' => ['nodes' => [
					['sekvens' => 0, 'vaerdi' => '471100', 'vaerdiTekst' => 'Supermarkeder'],
				]],
				'id_CVR_e_mailadresse_CVREnhedsId_ref' => ['vaerdi' => 'butik@example.test'],
				'id_CVR_Telefonnummer_CVREnhedsId_ref' => ['vaerdi' => '87654321'],
				'id_CVR_Reklamebeskyttelse_CVREnhedsId_ref' => ['vaerdi' => false],
			]),
		]),
	]);
	$result = $cvr->getCompany('40386270', withProductionUnits: true);
	$sent = $queries();

	$check(\count($sent) === 2, 'A company with production units takes one company request and one unit request.');
	$check($curl->requests[0]['url'] === 'https://graphql.datafordeler.dk/flexibleCurrent/v3', 'Requests go to the configured flexibleCurrent endpoint.');
	$check(\str_contains($sent[0], 'CVR_Virksomhed(') && $numbersIn($sent[0]) === [40386270], 'The company request filters on the CVR number.');
	$check($pageSize($sent[0]) === 100, 'The company request pages by the identifier batch size.');
	$check(\str_contains($sent[1], 'tilknyttetVirksomhedsCVRNummer: { in: [40386270] }'), 'Production units are selected by the CVR number of their company.');
	$check($pageSize($sent[1]) === 1000 && !\str_contains($sent[1], 'after:'), 'The first unit page has the largest page size and no cursor.');
	$check(
		\preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)$effectTime($sent[0])) === 1 && $effectTime($sent[0]) === $effectTime($sent[1]),
		'Both requests of one lookup use the same UTC effect time.'
	);
	$check(\is_array($result) && \array_keys($result) === $companyKeys, 'The company record keeps its key order and ends with productionUnits.');
	$check($result['cvrNumber'] === '40386270' && $result['name'] === 'Example ApS', 'Company identity is normalized.');
	$check($result['companyType'] === ['code' => '80', 'name' => 'Anpartsselskab'], 'Company form is normalized.');
	$check($result['address']['formatted'] === 'Eksempelvej 12, 7400 Herning', 'The location address wins over the postal address.');
	$check($result['postalAddress']['formatted'] === 'Postboksvej 1, 7400 Herning', 'The postal address is kept separately.');
	$check($result['contact'] === ['email' => 'info@example.test', 'phone' => '12345678', 'marketingProtected' => true], 'Company contact data is normalized.');
	$check($result['industries']['primary']['code'] === '620100' && \count($result['industries']['secondary']) === 1, 'Company industries are split by sequence.');
	$check(\array_column($result['productionUnits'], 'pNumber') === ['1024552248', '1024552249'], 'Production units are ordered by P-number.');

	$store = $result['productionUnits'][0];
	$check(\array_keys($store) === $unitKeys, 'The production-unit record has the documented keys in order.');
	$check($store['cvrNumber'] === '40386270' && $store['name'] === 'Example ApS', 'A production unit carries its name and the CVR number of its company.');
	$check($store['startDate'] === '2019-03-28' && $store['attachmentStartDate'] === '2019-04-01' && $store['endDate'] === null, 'Production-unit dates are normalized.');
	$check($store['address']['formatted'] === 'Butiksgade 3, 8000 Aarhus C' && $store['postalAddress'] === null, 'A production unit has its own address.');
	$check($store['contact'] === ['email' => 'butik@example.test', 'phone' => '87654321', 'marketingProtected' => false], 'A production unit has its own contact data.');
	$check($store['industries']['primary']['code'] === '471100', 'A production unit has its own industries.');
	$check(
		$result['productionUnits'][1]['contact'] === ['email' => null, 'phone' => null, 'marketingProtected' => null] && $result['productionUnits'][1]['address'] === null,
		'Missing relations of a production unit normalize to null.'
	);

	// -- 2. Without production units, the default ---------------------------
	$script([$page('CVR_Virksomhed', [$company(40386270, 'Example ApS')])]);
	$result = $cvr->getCompany('40386270');
	$check(\count($queries()) === 1, 'By default a company lookup takes one request, as before production units.');
	$check(\is_array($result) && \array_key_exists('productionUnits', $result) && $result['productionUnits'] === null, 'productionUnits is null when it was not requested.');

	// -- 3. Unknown company -------------------------------------------------
	$script([$page('CVR_Virksomhed', [])]);
	$check($cvr->getCompany('12345678', withProductionUnits: true) === null, 'An unknown CVR number returns null.');
	$check(\count($queries()) === 1, 'No unit request is sent when no company was found.');

	// -- 4. Companies and production units over several pages ------------------
	// The first answer waits for the next second, so a request that read the clock again would differ.
	$script([
		$page('CVR_Virksomhed', [], 'company-cursor-1'),
		$page('CVR_Virksomhed', [$company(40386270, 'Example ApS')]),
		$page('CVR_Produktionsenhed', [$unit(1000000003, 40386270, 'C'), $unit(1000000001, 40386270, 'A')], 'unit-cursor-1'),
		$page('CVR_Produktionsenhed', [$unit(1000000002, 40386270, 'B')]),
	], 1);
	$result = $cvr->getCompany('40386270', withProductionUnits: true);
	$sent = $queries();
	$check(\count($sent) === 4, 'Every further page takes one more request.');
	$check(\str_contains($sent[1], 'after: "company-cursor-1"') && \str_contains($sent[1], 'CVR_Virksomhed('), 'The next company page continues from the returned cursor.');
	$check(!\str_contains($sent[2], 'after:') && \str_contains($sent[3], 'after: "unit-cursor-1"') && \str_contains($sent[3], 'CVR_Produktionsenhed('), 'The unit pages start without a cursor and continue from the returned one.');
	$check(
		$effectTime($sent[0]) !== null && \count(\array_unique(\array_map($effectTime, $sent))) === 1,
		'Every request of one call uses the effect time of the call, across a second boundary.'
	);
	$check($result['name'] === 'Example ApS', 'A company on a later page is found.');
	$check(\array_column($result['productionUnits'], 'name') === ['A', 'B', 'C'], 'Units of every page are merged and ordered by P-number.');

	// -- 5. Repeated rows on the company path ---------------------------------
	$script([
		$page('CVR_Virksomhed', [$company(40386270, 'First row'), $company(40386270, 'Repeated row')]),
		$page('CVR_Produktionsenhed', [$unit(1000000001, 40386270, 'First unit row'), $unit(1000000001, 40386270, 'Repeated unit row')]),
	]);
	$result = $cvr->getCompany('40386270', withProductionUnits: true);
	$check($result['name'] === 'First row', 'The first row of a company wins over a repeated row.');
	$check(\array_column($result['productionUnits'], 'name') === ['First unit row'], 'The first row of a production unit wins over a repeated row.');

	// -- 6. Many companies in batches of 100 ---------------------------------
	$numbers = \array_map('strval', \range(10000000, 10000149));
	$script([
		$page('CVR_Virksomhed', [$company(10000099, 'Second'), $company(10000000, 'First')]),
		$page('CVR_Produktionsenhed', [$unit(1100000000, 10000000, 'First unit')]),
		$page('CVR_Virksomhed', [$company(10000149, 'Last')]),
		$page('CVR_Produktionsenhed', []),
	]);
	$result = $cvr->getCompanies([...$numbers, '10000000', '10000149'], withProductionUnits: true);
	$sent = $queries();
	$check(\count($sent) === 4, '150 numbers take two company requests and one unit request per batch with a found company.');
	$check($numbersIn($sent[0]) === \range(10000000, 10000099), 'The first batch holds the first 100 distinct numbers in input order.');
	$check($numbersIn($sent[1]) === [10000099, 10000000], 'Units are requested only for the companies found in the batch.');
	$check($numbersIn($sent[2]) === \range(10000100, 10000149), 'The second batch holds the remaining 50 numbers.');
	$check(\array_keys($result) === \range(10000000, 10000149), 'Results are keyed by CVR number in input order, and repeats collapse.');
	$check($result[10000000]['name'] === 'First' && $result[10000099]['name'] === 'Second' && $result[10000001] === null, 'Found and unknown numbers are told apart.');
	$check(\array_column($result[10000000]['productionUnits'], 'pNumber') === ['1100000000'] && $result[10000099]['productionUnits'] === [], 'Units are attached to their own company.');
	$check($result[10000149]['productionUnits'] === [], 'A found company without units has an empty list.');

	// The int keys of a result are valid input again.
	$script([$page('CVR_Virksomhed', [$company(10000000, 'First')])]);
	$again = $cvr->getCompanies(\array_slice(\array_keys($result), 0, 2));
	$check($numbersIn($queries()[0]) === [10000000, 10000001] && \array_keys($again) === [10000000, 10000001], 'CVR numbers are accepted as ints.');

	$script([$page('CVR_Virksomhed', [$company(40386270, 'Example ApS')])]);
	$check($cvr->getCompany(40386270)['name'] === 'Example ApS', 'getCompany() accepts an int.');

	// -- 7. Production units by P-number --------------------------------------
	$script([
		$page('CVR_Produktionsenhed', [
			$unit(1024552248, 40386270, 'First row'),
			$unit(1024552248, 40386270, 'Repeated row'),
		]),
	]);
	$result = $cvr->getProductionUnits(['1024552248', '1999999999', '1024552248']);
	$sent = $queries();
	$check(\count($sent) === 1, 'Up to 100 P-numbers take one request.');
	$check(\str_contains($sent[0], 'pNummer: { in: [1024552248, 1999999999] }') && $pageSize($sent[0]) === 100, 'The request filters on the distinct P-numbers and pages by the batch size.');
	$check(\array_keys($result) === [1024552248, 1999999999] && $result[1999999999] === null, 'Results are keyed by P-number, null when unknown.');
	$check($result[1024552248]['name'] === 'First row' && $result[1024552248]['cvrNumber'] === '40386270', 'The first row of a P-number wins, and the record names its company.');

	$script([$page('CVR_Produktionsenhed', [$unit(1024552248, 40386270, 'Example ApS')])]);
	$single = $cvr->getProductionUnit('1024552248');
	$check(\is_array($single) && \array_keys($single) === $unitKeys && $single['pNumber'] === '1024552248', 'getProductionUnit() returns the same record shape.');

	$script([$page('CVR_Produktionsenhed', [$unit(1024552248, 40386270, 'Example ApS')])]);
	$check($cvr->getProductionUnit(1024552248)['pNumber'] === '1024552248', 'getProductionUnit() accepts an int.');

	$numbers = \array_map('strval', \range(1000000000, 1000000100));
	$script([$page('CVR_Produktionsenhed', []), $page('CVR_Produktionsenhed', [])]);
	$cvr->getProductionUnits($numbers);
	$sent = $queries();
	$check(\count($sent) === 2 && \count($numbersIn($sent[0])) === 100 && $numbersIn($sent[1]) === [1000000100], '101 P-numbers take two requests.');

	// -- 8. Input validation ----------------------------------------------------
	$script([]);
	$check($cvr->getCompanies([]) === [] && $cvr->getProductionUnits([]) === [], 'An empty list returns an empty result.');
	$throws(static fn() => $cvr->getCompany('1234567'), \InvalidArgumentException::class, 'Seven digits are rejected.');
	$throws(static fn() => $cvr->getCompany('123456789'), \InvalidArgumentException::class, 'Nine digits are rejected.');
	$throws(static fn() => $cvr->getCompany('01234567'), \InvalidArgumentException::class, 'A leading zero is rejected.');
	$throws(static fn() => $cvr->getCompany('1234567a'), \InvalidArgumentException::class, 'A non-digit is rejected.');
	$throws(static fn() => $cvr->getCompany("12345678\n"), \InvalidArgumentException::class, 'A trailing line break is rejected.');
	$throws(static fn() => $cvr->getCompany(1234567), \InvalidArgumentException::class, 'An int with seven digits is rejected.');
	$throws(static fn() => $cvr->getCompany(-12345678), \InvalidArgumentException::class, 'A negative int is rejected.');
	$throws(static fn() => $cvr->getCompanies(['12345678', 12345678.0]), \InvalidArgumentException::class, 'A CVR number that is neither an int nor a string is rejected.');
	$throws(static fn() => $cvr->getProductionUnit('123456789'), \InvalidArgumentException::class, 'A P-number with nine digits is rejected.');
	$throws(static fn() => $cvr->getProductionUnit('0123456789'), \InvalidArgumentException::class, 'A P-number with a leading zero is rejected.');
	$throws(static fn() => $cvr->getProductionUnits(['1234567890', '12345678901']), \InvalidArgumentException::class, 'A P-number with eleven digits is rejected.');
	$throws(static fn() => $cvr->getProductionUnits([12345678901]), \InvalidArgumentException::class, 'An int P-number with eleven digits is rejected.');
	$check($curl->requests === [], 'Rejected input sends no request.');

	// -- 9. Responses that do not fit the request -------------------------------
	// The next page is announced but not scripted, so it must not be requested.
	$script([$page('CVR_Virksomhed', [$company(12345678, 'Other')], 'more')]);
	$throws(static fn() => $cvr->getCompany('40386270'), InvalidResponseException::class, 'A company that was not requested is rejected before a further page is requested.');

	$script([
		$page('CVR_Virksomhed', [$company(40386270, 'Example ApS')]),
		$page('CVR_Produktionsenhed', [$unit(1024552248, 12345678, 'Other')]),
	]);
	$throws(static fn() => $cvr->getCompany('40386270', withProductionUnits: true), InvalidResponseException::class, 'A unit of another company is rejected.');

	$script([$page('CVR_Produktionsenhed', [$unit(1999999999, 40386270, 'Other')])]);
	$throws(static fn() => $cvr->getProductionUnit('1024552248'), InvalidResponseException::class, 'A P-number that was not requested is rejected.');

	$script([
		$page('CVR_Virksomhed', [$company(40386270, 'Example ApS')]),
		$page('CVR_Produktionsenhed', [$unit(1000000001, 40386270, 'A')], 'same-cursor'),
		$page('CVR_Produktionsenhed', [$unit(1000000002, 40386270, 'B')], 'same-cursor'),
	]);
	$throws(static fn() => $cvr->getCompany('40386270', withProductionUnits: true), InvalidResponseException::class, 'A cursor that repeats at once is rejected.');

	$script([
		$page('CVR_Produktionsenhed', [], 'cursor-a'),
		$page('CVR_Produktionsenhed', [], 'cursor-b'),
		$page('CVR_Produktionsenhed', [], 'cursor-a'),
	]);
	$throws(static fn() => $cvr->getProductionUnit('1024552248'), InvalidResponseException::class, 'A cursor that comes back later is rejected.');
	$check(\count($queries()) === 3, 'A cursor cycle stops at the first repeated cursor.');

	$script([['CVR_Produktionsenhed' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]]);
	$throws(static fn() => $cvr->getProductionUnit('1024552248'), InvalidResponseException::class, 'A next page without a cursor is rejected.');

	$script([['CVR_Produktionsenhed' => ['nodes' => [$unit(1024552248, 40386270, 'A')]]]]);
	$throws(static fn() => $cvr->getProductionUnit('1024552248'), InvalidResponseException::class, 'A connection without pageInfo is rejected.');

	// -- 10. Query documents against the bundled schema --------------------------
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
	$check(isset($schema['type']['Query']['CVR_Produktionsenhed'], $schema['input']['CVR_ProduktionsenhedFilterInput']['pNummer']), 'The bundled schema parses.');

	$joinCounts = [];
	foreach (\array_unique($curl->sent) as $document) {
		$tree = parseQueryDocument($document);
		$check(\count($tree) === 1, 'Every request has exactly one root field.');

		$stats = checkFields($tree, 'Query', $schema);
		$check($stats['joins'] <= 17 && $stats['depth'] <= 7, 'Every request stays within 17 joins in total and 7 in depth.');
		$joinCounts[$tree[0]['name']] = $stats['joins'];
	}
	$check($joinCounts === ['CVR_Virksomhed' => 8, 'CVR_Produktionsenhed' => 7], 'The company query has 8 joins and the unit query 7.');

	\fwrite(
		STDOUT,
		'PASS ' . $checks . ' checks. CVR company and production-unit lookups against the flexibleCurrent v3 schema. PHP '
		. \PHP_VERSION . '.' . \PHP_EOL
	);
}
