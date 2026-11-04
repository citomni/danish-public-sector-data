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

namespace CitOmni\DanishPublicSectorData\Service;

use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;
use CitOmni\Kernel\Service\BaseService;

/**
 * Cvr: Read normalized Danish company and production-unit data from the official CVR register.
 *
 * Behavior:
 * - Looks up companies by eight-digit CVR number and production units by ten-digit P-number
 *   through Datafordeler flexibleCurrent, one number at a time or many per call.
 * - Returns normalized identity, status, addresses, contact data, and industries for companies
 *   and production units alike, plus the company form for companies.
 * - Returns a company with its production units when the caller asks for them, and a
 *   production unit with the CVR number of its company.
 * - Prefers the registered location address and keeps the explicit postal address separately.
 * - Returns null for a number without a matching company or production unit.
 *
 * Notes:
 * - Datafordeler-specific GraphQL relation names are contained inside this service.
 * - The public result shapes are intentionally independent of the upstream GraphQL schema.
 * - Only non-access-restricted CVR data is queried.
 *
 * Typical usage:
 *   $company = $this->app->cvr->getCompany('12345678');
 *   $units = $this->app->cvr->getProductionUnits(['1234567890', '1234567891']);
 *
 * @phpstan-type CvrAddress array{
 *     type:?string,
 *     formatted:?string,
 *     careOf:?string,
 *     street:?string,
 *     houseNumberFrom:?string,
 *     houseNumberTo:?string,
 *     floor:?string,
 *     door:?string,
 *     postalCode:?string,
 *     city:?string,
 *     supplementaryCity:?string,
 *     countryCode:?string,
 *     freeText:?string
 * }
 * @phpstan-type CvrIndustry array{code:string,name:string,sequence:int}
 * @phpstan-type CvrIndustries array{primary:CvrIndustry|null,secondary:list<CvrIndustry>}
 * @phpstan-type CvrContact array{email:?string,phone:?string,marketingProtected:?bool}
 * @phpstan-type CvrEntity array{
 *     name:?string,
 *     address:CvrAddress|null,
 *     postalAddress:CvrAddress|null,
 *     contact:CvrContact,
 *     industries:CvrIndustries
 * }
 * @phpstan-type CvrProductionUnit array{
 *     pNumber:string,
 *     cvrNumber:string,
 *     name:?string,
 *     status:?string,
 *     startDate:?string,
 *     endDate:?string,
 *     attachmentStartDate:?string,
 *     attachmentEndDate:?string,
 *     address:CvrAddress|null,
 *     postalAddress:CvrAddress|null,
 *     contact:CvrContact,
 *     industries:CvrIndustries
 * }
 * @phpstan-type CvrCompany array{
 *     cvrNumber:string,
 *     name:?string,
 *     status:?string,
 *     startDate:?string,
 *     endDate:?string,
 *     companyType:array{code:?string,name:?string}|null,
 *     address:CvrAddress|null,
 *     postalAddress:CvrAddress|null,
 *     contact:CvrContact,
 *     industries:CvrIndustries,
 *     productionUnits:list<CvrProductionUnit>|null
 * }
 *
 * @throws \InvalidArgumentException When a CVR number or P-number is malformed.
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails.
 */
final class Cvr extends BaseService {

	/** Maximum number of identifiers in one in filter, and the page size of a lookup by identifiers. */
	private const int IDENTIFIER_BATCH_SIZE = 100; // Datafordeler accepts at most 100 values in an in filter.

	/** Page size of the production units of a batch of companies. */
	private const int PAGE_SIZE = 1000; // Largest page Datafordeler serves.

	/** CVR number accepted for lookup: eight digits, the first of them 1-9. */
	private const string CVR_NUMBER_PATTERN = '/^[1-9][0-9]{7}$/D'; // Numbers become GraphQL Long literals, which cannot start with 0.

	/** P-number accepted for lookup: ten digits, the first of them 1-9. */
	private const string P_NUMBER_PATTERN = '/^[1-9][0-9]{9}$/D'; // Numbers become GraphQL Long literals, which cannot start with 0.

	/** GraphQL selection of the name, address, industry, and contact relations of one CVR entity. */
	private const string ENTITY_SELECTION = <<<'GRAPHQL'
					id_CVR_Navn_CVREnhedsId_ref {
						vaerdi
					}
					id_CVR_Adressering_CVREnhedsId_ref(
						first: 10
						where: {
							AdresseringAnvendelse: {
								in: ["beliggenhedsadresse", "postadresse"]
							}
						}
					) {
						nodes {
							AdresseringAnvendelse
							coNavn
							CVRAdresse_vejnavn
							CVRAdresse_husnummerFra
							CVRAdresse_husnummerTil
							CVRAdresse_etagebetegnelse
							CVRAdresse_doerbetegnelse
							CVRAdresse_postnummer
							CVRAdresse_postdistrikt
							CVRAdresse_supplerendeBynavn
							CVRAdresse_landekode
							CVRAdresse_adresseFritekst
						}
					}
					id_CVR_Branche_CVREnhedsId_ref(
						first: 10
						where: { sekvens: { in: [0, 1, 2, 3] } }
					) {
						nodes {
							sekvens
							vaerdi
							vaerdiTekst
						}
					}
					id_CVR_e_mailadresse_CVREnhedsId_ref {
						vaerdi
					}
					id_CVR_Telefonnummer_CVREnhedsId_ref {
						vaerdi
					}
					id_CVR_Reklamebeskyttelse_CVREnhedsId_ref {
						vaerdi
					}
GRAPHQL;

	private DatafordelerClient $datafordeler;
	private string $service;
	private string $version;

	/**
	 * Load the package-owned CVR endpoint selection.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When endpoint configuration is invalid.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->cvr;

		$this->service = (string)$cfg->service;
		$this->version = (string)$cfg->version;

		if ($this->service === '' || $this->version === '') {
			throw new \UnexpectedValueException('CVR Datafordeler service and version must be configured.');
		}

		$this->datafordeler = new DatafordelerClient($this->app);
	}

	// ----------------------------------------------------------------
	// Lookups
	// ----------------------------------------------------------------

	/**
	 * Return one normalized CVR company record.
	 *
	 * Typical usage:
	 *   $company = $this->app->cvr->getCompany('12345678');
	 *   $company = $this->app->cvr->getCompany('12345678', withProductionUnits: true);
	 *
	 * @param int|string $cvrNumber Eight digits, the first of them 1-9.
	 * @param bool $withProductionUnits True also loads the company's production units into productionUnits.
	 * @return CvrCompany|null Normalized company data, or null when Datafordeler has no company with the number.
	 * @throws \InvalidArgumentException When the CVR number does not have eight digits or starts with 0.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\Cvr::getCompanies() The requests behind the lookup and what productionUnits holds.
	 */
	public function getCompany(int|string $cvrNumber, bool $withProductionUnits = false): ?array {
		return $this->getCompanies([$cvrNumber], $withProductionUnits)[(int)$cvrNumber];
	}

	/**
	 * Return normalized CVR company records for many CVR numbers.
	 *
	 * Behavior:
	 * - Returns one entry per distinct CVR number, keyed by that number in the order the numbers
	 *   first occur. An entry is null when Datafordeler has no company with the number.
	 * - Sends the numbers in batches of up to 100. A batch takes one request per page of up to
	 *   100 companies and, with production units and at least one company found, one request per
	 *   page of up to 1000 production units of the companies found.
	 * - productionUnits holds the production units that Datafordeler attaches to the company,
	 *   unfiltered by status or end date and ordered by P-number. It is null when
	 *   $withProductionUnits is false.
	 * - Every request of one call uses the same effect time: the time of the call.
	 *
	 * Typical usage:
	 *   $companies = $this->app->cvr->getCompanies(['12345678', '87654321'], withProductionUnits: true);
	 *
	 * @param array<array-key,int|string> $cvrNumbers CVR numbers of eight digits, the first of them 1-9. A repeated number is looked up once.
	 * @param bool $withProductionUnits True also loads the production units of the companies into productionUnits.
	 * @return array<int,CvrCompany|null> Normalized company data keyed by CVR number.
	 * @throws \InvalidArgumentException When a CVR number is not an int or string of eight digits starting with 1-9.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When a remote lookup fails or is malformed.
	 */
	public function getCompanies(array $cvrNumbers, bool $withProductionUnits = false): array {
		$requested = self::lookupNumbers(
			$cvrNumbers,
			self::CVR_NUMBER_PATTERN,
			'CVR number must be an int or a string of exactly eight digits, the first of them 1-9.'
		);
		if ($requested === []) {
			return [];
		}

		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$companies = \array_fill_keys($requested, null);

		foreach (\array_chunk($requested, self::IDENTIFIER_BATCH_SIZE) as $batch) {
			$expected = \array_flip($batch);
			$found = [];

			$nodes = $this->connectionNodes(
				'CVR_Virksomhed',
				static fn(?string $after): string => self::companiesQuery($batch, $effectiveAt, $after)
			);
			foreach ($nodes as $node) {
				$company = $this->normalizeCompany($node);
				$cvrNumber = (int)$company['cvrNumber'];
				if (!isset($expected[$cvrNumber])) {
					throw new InvalidResponseException('CVR response contains an unexpected CVR number.');
				}

				// One row per company is expected at one effect time; the first row wins over a repeat.
				$found[$cvrNumber] ??= $company;
			}

			if ($withProductionUnits && $found !== []) {
				$foundNumbers = \array_keys($found);
				$units = [];

				$nodes = $this->connectionNodes(
					'CVR_Produktionsenhed',
					static fn(?string $after): string => self::productionUnitsQuery(
						'tilknyttetVirksomhedsCVRNummer',
						$foundNumbers,
						self::PAGE_SIZE,
						$effectiveAt,
						$after
					)
				);
				foreach ($nodes as $node) {
					$unit = $this->normalizeProductionUnit($node);
					$cvrNumber = (int)$unit['cvrNumber'];
					if (!isset($found[$cvrNumber])) {
						throw new InvalidResponseException('CVR response contains a production unit of an unexpected company.');
					}

					$units[$cvrNumber][(int)$unit['pNumber']] ??= $unit;
				}

				foreach ($foundNumbers as $cvrNumber) {
					$companyUnits = $units[$cvrNumber] ?? [];
					\ksort($companyUnits);
					$found[$cvrNumber]['productionUnits'] = \array_values($companyUnits);
				}
			}

			foreach ($found as $cvrNumber => $company) {
				$companies[$cvrNumber] = $company;
			}
		}

		return $companies;
	}

	/**
	 * Return one normalized CVR production-unit record.
	 *
	 * Typical usage:
	 *   $unit = $this->app->cvr->getProductionUnit('1234567890');
	 *
	 * @param int|string $pNumber Ten digits, the first of them 1-9.
	 * @return CvrProductionUnit|null Normalized production-unit data, or null when Datafordeler has no production unit with the number.
	 * @throws \InvalidArgumentException When the P-number does not have ten digits or starts with 0.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\Cvr::getProductionUnits() The requests behind the lookup.
	 */
	public function getProductionUnit(int|string $pNumber): ?array {
		return $this->getProductionUnits([$pNumber])[(int)$pNumber];
	}

	/**
	 * Return normalized CVR production-unit records for many P-numbers.
	 *
	 * Behavior:
	 * - Returns one entry per distinct P-number, keyed by that number in the order the numbers
	 *   first occur. An entry is null when Datafordeler has no production unit with the number.
	 * - Sends the numbers in batches of up to 100. A batch takes one request per page of up to
	 *   100 production units.
	 * - cvrNumber of a record is the company that the production unit is attached to.
	 * - Every request of one call uses the same effect time: the time of the call.
	 *
	 * Typical usage:
	 *   $units = $this->app->cvr->getProductionUnits(['1234567890', '1234567891']);
	 *
	 * @param array<array-key,int|string> $pNumbers P-numbers of ten digits, the first of them 1-9. A repeated number is looked up once.
	 * @return array<int,CvrProductionUnit|null> Normalized production-unit data keyed by P-number.
	 * @throws \InvalidArgumentException When a P-number is not an int or string of ten digits starting with 1-9.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When a remote lookup fails or is malformed.
	 */
	public function getProductionUnits(array $pNumbers): array {
		$requested = self::lookupNumbers(
			$pNumbers,
			self::P_NUMBER_PATTERN,
			'P-number must be an int or a string of exactly ten digits, the first of them 1-9.'
		);
		if ($requested === []) {
			return [];
		}

		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$units = \array_fill_keys($requested, null);

		foreach (\array_chunk($requested, self::IDENTIFIER_BATCH_SIZE) as $batch) {
			$expected = \array_flip($batch);

			$nodes = $this->connectionNodes(
				'CVR_Produktionsenhed',
				static fn(?string $after): string => self::productionUnitsQuery(
					'pNummer',
					$batch,
					self::IDENTIFIER_BATCH_SIZE,
					$effectiveAt,
					$after
				)
			);
			foreach ($nodes as $node) {
				$unit = $this->normalizeProductionUnit($node);
				$pNumber = (int)$unit['pNumber'];
				if (!isset($expected[$pNumber])) {
					throw new InvalidResponseException('CVR response contains an unexpected P-number.');
				}

				$units[$pNumber] ??= $unit;
			}
		}

		return $units;
	}

	// ----------------------------------------------------------------
	// Requests
	// ----------------------------------------------------------------

	/**
	 * Yield the nodes of every page of one root connection.
	 *
	 * Behavior:
	 * - Yields the nodes in response order, one page at a time.
	 * - Requests the next page only when the nodes of the current page have been consumed.
	 *
	 * @param string $root Root field of the query documents.
	 * @param callable(?string):string $query Builder of the query document for one page; its argument is the cursor of the previous page, null for the first.
	 * @return \Generator<int,array<string,mixed>,mixed,void> Nodes of every page.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When a page is malformed or announces a cursor that was already used.
	 */
	private function connectionNodes(string $root, callable $query): \Generator {
		$cursor = null;
		$usedCursors = [];

		do {
			$data = $this->datafordeler->query($this->service, $this->version, $query($cursor));

			$connection = $data[$root] ?? null;
			if (!\is_array($connection) || !\is_array($connection['nodes'] ?? null) || !\is_array($connection['pageInfo'] ?? null)) {
				throw new InvalidResponseException('CVR response does not contain a valid ' . $root . ' connection.');
			}

			$hasNextPage = $connection['pageInfo']['hasNextPage'] ?? null;
			if (!\is_bool($hasNextPage)) {
				throw new InvalidResponseException('CVR response contains invalid ' . $root . ' page information.');
			}

			$cursor = null;
			if ($hasNextPage) {
				$cursor = $connection['pageInfo']['endCursor'] ?? null;

				// A cursor that comes back would request the same pages forever.
				if (!\is_string($cursor) || $cursor === '' || isset($usedCursors[$cursor])) {
					throw new InvalidResponseException('CVR response does not advance the ' . $root . ' pagination.');
				}
				$usedCursors[$cursor] = true;
			}

			foreach ($connection['nodes'] as $node) {
				if (!\is_array($node)) {
					throw new InvalidResponseException('CVR response contains an invalid ' . $root . ' node.');
				}

				yield $node;
			}

			// Release the raw page before the next one is requested.
			unset($data, $connection);
		} while ($cursor !== null);
	}

	/**
	 * Build the query document for one page of companies.
	 *
	 * @param list<int> $cvrNumbers At most 100 CVR numbers.
	 * @param string $effectiveAt UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param string|null $after Cursor of the previous page, or null for the first page.
	 * @return string GraphQL query document.
	 */
	private static function companiesQuery(array $cvrNumbers, string $effectiveAt, ?string $after): string {
		$first = self::IDENTIFIER_BATCH_SIZE;
		$afterArgument = self::afterArgument($after);
		$numberList = \implode(', ', $cvrNumbers);
		$entity = self::ENTITY_SELECTION;

		return <<<GRAPHQL
query CvrCompanies {
	CVR_Virksomhed(
		first: {$first}{$afterArgument}
		virkningstid: "{$effectiveAt}"
		where: { CVRNummer: { in: [{$numberList}] } }
	) {
		nodes {
			status
			CVRNummer
			virksomhedStartdato
			virksomhedOphoersdato
			id_CVR_CVREnhed_id_ref(first: 1) {
				nodes {
{$entity}
				}
			}
			id_CVR_Virksomhedsform_CVREnhedsId_ref {
				vaerdi
				vaerdiTekst
			}
		}
		pageInfo {
			hasNextPage
			endCursor
		}
	}
}
GRAPHQL;
	}

	/**
	 * Build the query document for one page of production units.
	 *
	 * @param string $filterField pNummer to select units by P-number, or tilknyttetVirksomhedsCVRNummer to select them by company.
	 * @param list<int> $numbers At most 100 values of the filter field.
	 * @param int $first Page size, 1 to 1000.
	 * @param string $effectiveAt UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param string|null $after Cursor of the previous page, or null for the first page.
	 * @return string GraphQL query document.
	 */
	private static function productionUnitsQuery(string $filterField, array $numbers, int $first, string $effectiveAt, ?string $after): string {
		$afterArgument = self::afterArgument($after);
		$numberList = \implode(', ', $numbers);
		$entity = self::ENTITY_SELECTION;

		return <<<GRAPHQL
query CvrProductionUnits {
	CVR_Produktionsenhed(
		first: {$first}{$afterArgument}
		virkningstid: "{$effectiveAt}"
		where: { {$filterField}: { in: [{$numberList}] } }
	) {
		nodes {
			pNummer
			tilknyttetVirksomhedsCVRNummer
			status
			produktionsenhedStartdato
			produktionsenhedOphoersdato
			tilknyttetTilVirksomhedStartdato
			tilknyttetTilVirksomhedOphoersdato
			id_CVR_CVREnhed_id_ref(first: 1) {
				nodes {
{$entity}
				}
			}
		}
		pageInfo {
			hasNextPage
			endCursor
		}
	}
}
GRAPHQL;
	}

	/**
	 * Build the after argument of a page request.
	 *
	 * @param string|null $after Cursor of the previous page, or null for the first page.
	 * @return string Argument line with its leading line break, or an empty string for the first page.
	 */
	private static function afterArgument(?string $after): string {
		if ($after === null) {
			return '';
		}

		// A JSON string literal is also a valid GraphQL string literal.
		return "\n\t\tafter: " . \json_encode($after, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Validate lookup numbers and drop repeats.
	 *
	 * @param array<array-key,mixed> $numbers Requested numbers.
	 * @param string $pattern Pattern that the decimal form of every number must match.
	 * @param string $message Exception message for a number that does not match.
	 * @return list<int> Distinct numbers in the order they first occur.
	 * @throws \InvalidArgumentException When a number is neither an int nor a string, or its decimal form does not match the pattern.
	 */
	private static function lookupNumbers(array $numbers, string $pattern, string $message): array {
		$unique = [];
		foreach ($numbers as $number) {
			if (\is_int($number)) {
				$number = (string)$number;
			}
			if (!\is_string($number) || !\preg_match($pattern, $number)) {
				throw new \InvalidArgumentException($message);
			}

			$unique[(int)$number] = true;
		}

		return \array_keys($unique);
	}

	// ----------------------------------------------------------------
	// Normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize one CVR_Virksomhed node.
	 *
	 * @param array<string,mixed> $node Raw company node.
	 * @return CvrCompany Normalized company with productionUnits null.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the node shape or a value is invalid.
	 */
	private function normalizeCompany(array $node): array {
		$cvrNumber = self::requiredInt($node['CVRNummer'] ?? null, 'company CVR number');
		$entity = $this->normalizeEntity($node['id_CVR_CVREnhed_id_ref'] ?? null);

		return [
			'cvrNumber' => (string)$cvrNumber,
			'name' => $entity['name'],
			'status' => self::nullableString($node['status'] ?? null),
			'startDate' => self::nullableString($node['virksomhedStartdato'] ?? null),
			'endDate' => self::nullableString($node['virksomhedOphoersdato'] ?? null),
			'companyType' => $this->normalizeCompanyType($node['id_CVR_Virksomhedsform_CVREnhedsId_ref'] ?? null),
			'address' => $entity['address'],
			'postalAddress' => $entity['postalAddress'],
			'contact' => $entity['contact'],
			'industries' => $entity['industries'],
			'productionUnits' => null,
		];
	}

	/**
	 * Normalize one CVR_Produktionsenhed node.
	 *
	 * @param array<string,mixed> $node Raw production-unit node.
	 * @return CvrProductionUnit Normalized production unit.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the node shape or a value is invalid.
	 */
	private function normalizeProductionUnit(array $node): array {
		$pNumber = self::requiredInt($node['pNummer'] ?? null, 'production unit P-number');
		$cvrNumber = self::requiredInt($node['tilknyttetVirksomhedsCVRNummer'] ?? null, 'production unit CVR number');
		$entity = $this->normalizeEntity($node['id_CVR_CVREnhed_id_ref'] ?? null);

		return [
			'pNumber' => (string)$pNumber,
			'cvrNumber' => (string)$cvrNumber,
			'name' => $entity['name'],
			'status' => self::nullableString($node['status'] ?? null),
			'startDate' => self::nullableString($node['produktionsenhedStartdato'] ?? null),
			'endDate' => self::nullableString($node['produktionsenhedOphoersdato'] ?? null),
			'attachmentStartDate' => self::nullableString($node['tilknyttetTilVirksomhedStartdato'] ?? null),
			'attachmentEndDate' => self::nullableString($node['tilknyttetTilVirksomhedOphoersdato'] ?? null),
			'address' => $entity['address'],
			'postalAddress' => $entity['postalAddress'],
			'contact' => $entity['contact'],
			'industries' => $entity['industries'],
		];
	}

	/**
	 * Normalize the name, addresses, contact data, and industries of one CVR entity relation.
	 *
	 * @param mixed $relation Raw CVR_CVREnhed connection.
	 * @return CvrEntity Normalized entity data; every value is empty when the relation has no node.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape or a value is invalid.
	 */
	private function normalizeEntity(mixed $relation): array {
		$entity = $this->firstConnectionNode($relation, 'CVR entity relation');
		if ($entity === null) {
			return [
				'name' => null,
				'address' => null,
				'postalAddress' => null,
				'contact' => [
					'email' => null,
					'phone' => null,
					'marketingProtected' => null,
				],
				'industries' => [
					'primary' => null,
					'secondary' => [],
				],
			];
		}

		$nameRelation = $entity['id_CVR_Navn_CVREnhedsId_ref'] ?? null;
		if ($nameRelation !== null && !\is_array($nameRelation)) {
			throw new InvalidResponseException('CVR response contains an invalid name relation.');
		}

		$addresses = $this->normalizeAddresses($entity['id_CVR_Adressering_CVREnhedsId_ref'] ?? null);

		return [
			'name' => \is_array($nameRelation) ? self::nullableString($nameRelation['vaerdi'] ?? null) : null,
			'address' => $addresses['address'],
			'postalAddress' => $addresses['postalAddress'],
			'contact' => [
				'email' => $this->normalizeValueRelation($entity['id_CVR_e_mailadresse_CVREnhedsId_ref'] ?? null, 'email relation'),
				'phone' => $this->normalizeValueRelation($entity['id_CVR_Telefonnummer_CVREnhedsId_ref'] ?? null, 'phone relation'),
				'marketingProtected' => $this->normalizeBooleanValueRelation($entity['id_CVR_Reklamebeskyttelse_CVREnhedsId_ref'] ?? null),
			],
			'industries' => $this->normalizeIndustries($entity['id_CVR_Branche_CVREnhedsId_ref'] ?? null),
		];
	}

	/**
	 * Return the first node from a GraphQL connection relation.
	 *
	 * @param mixed $relation Raw relation value.
	 * @param string $label Safe relation label for diagnostics.
	 * @return array<string,mixed>|null First node or null when the connection is empty or absent.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape is invalid.
	 */
	private function firstConnectionNode(mixed $relation, string $label): ?array {
		if ($relation === null) {
			return null;
		}
		if (!\is_array($relation)) {
			throw new InvalidResponseException($label . ' is invalid.');
		}

		$nodes = $relation['nodes'] ?? null;
		if (!\is_array($nodes)) {
			throw new InvalidResponseException($label . ' does not contain a valid node list.');
		}
		if ($nodes === []) {
			return null;
		}

		$node = $nodes[0] ?? null;
		if (!\is_array($node)) {
			throw new InvalidResponseException($label . ' contains an invalid node.');
		}

		return $node;
	}

	/**
	 * Normalize the registered location and postal addresses from one CVR relation.
	 *
	 * Behavior:
	 * - Keeps the explicit postal address separately when CVR supplies one.
	 * - Preserves the historical public address behavior by falling back to the postal address
	 *   when no registered location address is available.
	 *
	 * @param mixed $relation Raw address relation.
	 * @return array{address:CvrAddress|null,postalAddress:CvrAddress|null} Normalized address set.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape is invalid.
	 */
	private function normalizeAddresses(mixed $relation): array {
		if ($relation === null) {
			return [
				'address' => null,
				'postalAddress' => null,
			];
		}
		if (!\is_array($relation) || !\is_array($relation['nodes'] ?? null)) {
			throw new InvalidResponseException('CVR response contains an invalid address relation.');
		}

		$location = null;
		$postal = null;

		foreach ($relation['nodes'] as $node) {
			if (!\is_array($node)) {
				throw new InvalidResponseException('CVR response contains an invalid address node.');
			}

			$type = self::nullableString($node['AdresseringAnvendelse'] ?? null);
			if ($location === null && $type === 'beliggenhedsadresse') {
				$location = $this->normalizeAddressNode($node);
			}
			if ($postal === null && $type === 'postadresse') {
				$postal = $this->normalizeAddressNode($node);
			}
		}

		return [
			'address' => $location ?? $postal,
			'postalAddress' => $postal,
		];
	}

	/**
	 * Normalize one CVR address node.
	 *
	 * @param array<string,mixed> $node Raw address node.
	 * @return CvrAddress Normalized address.
	 */
	private function normalizeAddressNode(array $node): array {
		$address = [
			'type' => self::nullableString($node['AdresseringAnvendelse'] ?? null),
			'formatted' => null,
			'careOf' => self::nullableString($node['coNavn'] ?? null),
			'street' => self::nullableString($node['CVRAdresse_vejnavn'] ?? null),
			'houseNumberFrom' => self::nullableString($node['CVRAdresse_husnummerFra'] ?? null),
			'houseNumberTo' => self::nullableString($node['CVRAdresse_husnummerTil'] ?? null),
			'floor' => self::nullableString($node['CVRAdresse_etagebetegnelse'] ?? null),
			'door' => self::nullableString($node['CVRAdresse_doerbetegnelse'] ?? null),
			'postalCode' => self::nullableString($node['CVRAdresse_postnummer'] ?? null),
			'city' => self::nullableString($node['CVRAdresse_postdistrikt'] ?? null),
			'supplementaryCity' => self::nullableString($node['CVRAdresse_supplerendeBynavn'] ?? null),
			'countryCode' => self::nullableString($node['CVRAdresse_landekode'] ?? null),
			'freeText' => self::nullableString($node['CVRAdresse_adresseFritekst'] ?? null),
		];

		$address['formatted'] = self::formatAddress($address);

		return $address;
	}

	/**
	 * Build a human-readable single-line address from normalized CVR address fields.
	 *
	 * Behavior:
	 * - Uses addressFritekst when CVR supplies an unstructured address.
	 * - Otherwise combines the structured street, unit, locality and postal fields.
	 * - Omits the Danish country code but appends non-Danish country codes when present.
	 *
	 * Notes:
	 * - The upstream Adresse field is a DAR address reference, not formatted address text.
	 * - No external address lookup is required.
	 *
	 * @param CvrAddress $address Normalized CVR address fields.
	 * @return string|null Single-line display address, or null when no address text can be built.
	 */
	private static function formatAddress(array $address): ?string {
		$countryCode = $address['countryCode'];

		if ($address['freeText'] !== null && $address['freeText'] !== '') {
			if ($countryCode !== null && $countryCode !== '' && \strcasecmp($countryCode, 'DK') !== 0) {
				return $address['freeText'] . ', ' . $countryCode;
			}

			return $address['freeText'];
		}

		$parts = [];

		$street = $address['street'];
		$houseNumber = $address['houseNumberFrom'];
		if ($address['houseNumberTo'] !== null && $address['houseNumberTo'] !== '' && $address['houseNumberTo'] !== $houseNumber) {
			$houseNumber = $houseNumber !== null && $houseNumber !== ''
				? $houseNumber . '-' . $address['houseNumberTo']
				: $address['houseNumberTo'];
		}

		$streetLine = $street ?? '';
		if ($houseNumber !== null && $houseNumber !== '') {
			$streetLine .= ($streetLine !== '' ? ' ' : '') . $houseNumber;
		}
		if ($streetLine !== '') {
			$parts[] = $streetLine;
		}

		$unitParts = [];
		if ($address['floor'] !== null && $address['floor'] !== '') {
			$unitParts[] = $address['floor'];
		}
		if ($address['door'] !== null && $address['door'] !== '') {
			$unitParts[] = $address['door'];
		}
		if ($unitParts !== []) {
			$parts[] = \implode(' ', $unitParts);
		}

		if ($address['supplementaryCity'] !== null && $address['supplementaryCity'] !== '') {
			$parts[] = $address['supplementaryCity'];
		}

		$postalLine = '';
		if ($address['postalCode'] !== null && $address['postalCode'] !== '') {
			$postalLine = $address['postalCode'];
		}
		if ($address['city'] !== null && $address['city'] !== '') {
			$postalLine .= ($postalLine !== '' ? ' ' : '') . $address['city'];
		}
		if ($postalLine !== '') {
			$parts[] = $postalLine;
		}

		if ($countryCode !== null && $countryCode !== '' && \strcasecmp($countryCode, 'DK') !== 0) {
			$parts[] = $countryCode;
		}

		return $parts !== [] ? \implode(', ', $parts) : null;
	}

	/**
	 * Normalize a to-one CVR relation containing a string value.
	 *
	 * @param mixed $relation Raw relation value.
	 * @param string $label Safe relation label for diagnostics.
	 * @return string|null Normalized relation value.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape is invalid.
	 */
	private function normalizeValueRelation(mixed $relation, string $label): ?string {
		if ($relation === null) {
			return null;
		}
		if (!\is_array($relation)) {
			throw new InvalidResponseException('CVR response contains an invalid ' . $label . '.');
		}

		return self::requiredString($relation['vaerdi'] ?? null, $label . ' value');
	}

	/**
	 * Normalize the CVR marketing-protection relation.
	 *
	 * @param mixed $relation Raw relation value.
	 * @return bool|null Protection flag, or null when the relation is unavailable.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape or value is invalid.
	 */
	private function normalizeBooleanValueRelation(mixed $relation): ?bool {
		if ($relation === null) {
			return null;
		}
		if (!\is_array($relation)) {
			throw new InvalidResponseException('CVR response contains an invalid marketing protection relation.');
		}

		if (!\array_key_exists('vaerdi', $relation)) {
			throw new InvalidResponseException('CVR response contains a missing marketing protection value.');
		}

		$value = $relation['vaerdi'];
		if (!\is_bool($value)) {
			throw new InvalidResponseException('CVR response contains an invalid marketing protection value.');
		}

		return $value;
	}

	/**
	 * Normalize current CVR branch data into primary and secondary industries.
	 *
	 * Notes:
	 * - CVR sequence 0 is the primary industry.
	 * - Sequences 1 through 3 are secondary industries.
	 * - Results are sorted by sequence so the public output is deterministic.
	 *
	 * @param mixed $relation Raw branch connection.
	 * @return CvrIndustries Normalized industries.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape or node values are invalid.
	 */
	private function normalizeIndustries(mixed $relation): array {
		if ($relation === null) {
			return [
				'primary' => null,
				'secondary' => [],
			];
		}
		if (!\is_array($relation) || !\is_array($relation['nodes'] ?? null)) {
			throw new InvalidResponseException('CVR response contains an invalid industry relation.');
		}

		$industries = [];
		$seenSequences = [];
		foreach ($relation['nodes'] as $node) {
			if (!\is_array($node)) {
				throw new InvalidResponseException('CVR response contains an invalid industry node.');
			}

			$sequence = self::requiredInt($node['sekvens'] ?? null, 'industry sequence');
			if ($sequence < 0 || $sequence > 3) {
				throw new InvalidResponseException('CVR response contains an unexpected industry sequence.');
			}
			if (isset($seenSequences[$sequence])) {
				throw new InvalidResponseException('CVR response contains a duplicate industry sequence.');
			}
			$seenSequences[$sequence] = true;

			$industries[] = [
				'code' => self::requiredString($node['vaerdi'] ?? null, 'industry code'),
				'name' => self::requiredString($node['vaerdiTekst'] ?? null, 'industry name'),
				'sequence' => $sequence,
			];
		}

		\usort($industries, static function(array $left, array $right): int {
			$sequenceOrder = $left['sequence'] <=> $right['sequence'];

			return $sequenceOrder !== 0 ? $sequenceOrder : $left['code'] <=> $right['code'];
		});

		$primary = null;
		$secondary = [];
		foreach ($industries as $industry) {
			if ($industry['sequence'] === 0) {
				if ($primary !== null) {
					throw new InvalidResponseException('CVR response contains multiple primary industries.');
				}
				$primary = $industry;
				continue;
			}

			$secondary[] = $industry;
		}

		return [
			'primary' => $primary,
			'secondary' => $secondary,
		];
	}

	/**
	 * Normalize the CVR company-form relation.
	 *
	 * @param mixed $relation Raw company-form relation.
	 * @return array{code:?string,name:?string}|null Normalized company form or null when unavailable.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape is invalid.
	 */
	private function normalizeCompanyType(mixed $relation): ?array {
		if ($relation === null) {
			return null;
		}
		if (!\is_array($relation)) {
			throw new InvalidResponseException('CVR response contains an invalid company type relation.');
		}

		return [
			'code' => self::nullableString($relation['vaerdi'] ?? null),
			'name' => self::nullableString($relation['vaerdiTekst'] ?? null),
		];
	}

	// ----------------------------------------------------------------
	// Scalar values
	// ----------------------------------------------------------------

	/**
	 * Normalize a required upstream scalar to a non-empty string.
	 *
	 * @param mixed $value Raw upstream value.
	 * @param string $label Safe value label for diagnostics.
	 * @return string Non-empty string value.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the value is missing or invalid.
	 */
	private static function requiredString(mixed $value, string $label): string {
		$normalized = self::nullableString($value);
		if ($normalized === null || $normalized === '') {
			throw new InvalidResponseException('CVR response contains a missing ' . $label . '.');
		}

		return $normalized;
	}

	/**
	 * Normalize a required upstream integer.
	 *
	 * @param mixed $value Raw upstream value.
	 * @param string $label Safe value label for diagnostics.
	 * @return int Integer value.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the value is missing or invalid.
	 */
	private static function requiredInt(mixed $value, string $label): int {
		if (\is_int($value)) {
			return $value;
		}
		if (\is_string($value) && \preg_match('/^-?[0-9]+$/D', $value) && (string)(int)$value === $value) {
			return (int)$value;
		}

		throw new InvalidResponseException('CVR response contains an invalid ' . $label . '.');
	}

	/**
	 * Normalize an upstream scalar to a nullable string.
	 *
	 * @param mixed $value Raw upstream value.
	 * @return string|null String value or null.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the value is not scalar or null.
	 */
	private static function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
			throw new InvalidResponseException('CVR response contains an unexpected scalar value.');
		}

		return (string)$value;
	}
}
