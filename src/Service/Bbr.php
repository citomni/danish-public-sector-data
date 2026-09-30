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
use CitOmni\DanishPublicSectorData\Util\BbrCodeLists;
use CitOmni\Kernel\Service\BaseService;

/**
 * Bbr: Resolve Danish property addresses and read normalized BBR property data.
 *
 * Behavior:
 * - Resolves auction-style addresses through DAR and BBR without relying on DAWA.
 * - Preserves trailing "m.fl." as an explicit incomplete-scope signal.
 * - Resolves SFE, building-on-foreign-ground, and owner-apartment BFE properties.
 * - Returns a normalized current property graph keyed by the authoritative BFE number.
 * - Exposes technical-installation history separately so normal lookups avoid history overhead.
 * - Uses batched identifier queries and cursor pagination where Datafordeleren supports them.
 *
 * Notes:
 * - Address resolution never silently chooses between ambiguous BFE candidates.
 * - A selected owner-apartment unit takes precedence over the underlying SFE candidate.
 * - Public result shapes use package-owned keys rather than upstream GraphQL field names.
 * - BBR code values remain authoritative while bundled snapshot labels are returned additively.
 *
 * Typical usage:
 *   $resolution = $this->app->bbr->resolveAddress('Nørmarkvej 29, 2 22, 7600 Struer');
 *   $property = $this->app->bbr->getPropertyByBfe('337504');
 *
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When a remote lookup fails.
 */
final class Bbr extends BaseService {

	private const int PAGE_SIZE = 1000;
	private const int IDENTIFIER_BATCH_SIZE = 100;

	private DatafordelerClient $datafordeler;
	private string $currentService;
	private string $currentVersion;
	private string $historyService;
	private string $historyVersion;

	/**
	 * Load BBR endpoint selection and initialize the internal Datafordeler client.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When BBR endpoint configuration is invalid.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->bbr;

		$this->currentService = (string)($cfg->current_service ?? '');
		$this->currentVersion = (string)($cfg->current_version ?? '');
		$this->historyService = (string)($cfg->history_service ?? '');
		$this->historyVersion = (string)($cfg->history_version ?? '');

		if (
			$this->currentService === ''
			|| $this->currentVersion === ''
			|| $this->historyService === ''
			|| $this->historyVersion === ''
		) {
			throw new \UnexpectedValueException('BBR Datafordeler service and version configuration must be complete.');
		}

		$this->datafordeler = new DatafordelerClient($this->app);
	}

	/**
	 * Resolve one human-entered Danish property address to BFE candidates.
	 *
	 * Behavior:
	 * - Parses street, house number, optional floor/door, and postcode.
	 * - Removes trailing "m.fl." only from the lookup value and preserves the scope hint.
	 * - Prefers exact street matches inside the exact postcode.
	 * - Uses deterministic fuzzy street matching only when no exact match exists.
	 * - Resolves a concrete owner-apartment unit when floor/door identifies one exact DAR address.
	 * - Returns ambiguity instead of selecting one BFE when evidence is not decisive.
	 *
	 * @param string $address Human-entered address.
	 * @return array{
	 *     input:string,
	 *     lookupInput:string,
	 *     multiplePropertiesHint:bool,
	 *     status:string,
	 *     parsed:array{
	 *         street:?string,
	 *         houseNumber:?string,
	 *         unit:array{raw:string,floor:?string,door:?string}|null,
	 *         postcode:?string,
	 *         cityInput:?string
	 *     },
	 *     streetMatch:array{
	 *         status:string,
	 *         input:string,
	 *         matched:?string,
	 *         candidates:list<array{name:string,distance:int,similarity:float}>
	 *     }|null,
	 *     matchedAddress:?string,
	 *     bfeCandidates:list<array{
	 *         bfeNumber:int,
	 *         propertyType:string,
	 *         propertyRelationId:string,
	 *         evidence:list<string>
	 *     }>,
	 *     selectedBfeNumber:?int,
	 *     warnings:list<string>
	 * } Resolution result.
	 * @throws \InvalidArgumentException When the address is empty.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 */
	public function resolveAddress(string $address): array {
		$address = \trim($address);
		if ($address === '') {
			throw new \InvalidArgumentException('Address must be a non-empty string.');
		}

		$effectiveAt = self::now();
		$parsed = $this->parseAuctionAddress($address);

		if (
			$parsed['street'] === null
			|| $parsed['houseNumber'] === null
			|| $parsed['postcode'] === null
		) {
			return $this->resolutionResult(
				$address,
				$parsed,
				'unparseable',
				null,
				null,
				[],
				null,
				['address_unparseable']
			);
		}

		$postcodes = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'DAR_Postnummer',
			fn(?string $after): string => $this->buildPostcodeQuery($parsed['postcode'], $effectiveAt, $after)
		);

		if (\count($postcodes) !== 1) {
			return $this->resolutionResult(
				$address,
				$parsed,
				'postcode_not_unique',
				null,
				null,
				[],
				null,
				['postcode_not_unique']
			);
		}

		$postcodeId = self::requiredUuid($postcodes[0]['id_lokalId'] ?? null, 'DAR postcode id');
		$streetMatch = $this->resolveStreet($parsed['street'], $postcodeId, $effectiveAt);

		$street = $streetMatch['selected'];
		if (!\is_array($street)) {
			return $this->resolutionResult(
				$address,
				$parsed,
				'street_ambiguous_or_not_found',
				$streetMatch,
				null,
				[],
				null,
				['street_ambiguous_or_not_found']
			);
		}

		$streetId = self::requiredUuid($street['id_lokalId'] ?? null, 'DAR street id');
		$houseNumbers = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'DAR_Husnummer',
			fn(?string $after): string => $this->buildHouseNumberQuery(
				$streetId,
				$postcodeId,
				$parsed['houseNumber'],
				$effectiveAt,
				$after
			)
		);

		$houseNumbers = self::uniqueById($houseNumbers);
		if (\count($houseNumbers) !== 1) {
			return $this->resolutionResult(
				$address,
				$parsed,
				'house_number_not_unique',
				$streetMatch,
				null,
				[],
				null,
				['house_number_not_unique']
			);
		}

		$houseNumberId = self::requiredUuid($houseNumbers[0]['id_lokalId'] ?? null, 'DAR house-number id');
		$addresses = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'DAR_Adresse',
			fn(?string $after): string => $this->buildAddressesByHouseNumberQuery(
				$houseNumberId,
				$effectiveAt,
				$after
			)
		);

		$addresses = self::uniqueById($addresses);
		$addressSelection = $this->selectAddress($addresses, $parsed['unit']);
		$candidates = $this->resolveBfeCandidates(
			$houseNumberId,
			$addressSelection['selected'],
			$effectiveAt
		);

		$status = $this->resolutionStatus(
			$parsed['multiplePropertiesHint'],
			$addressSelection,
			$candidates
		);

		$warnings = [];
		if ($parsed['multiplePropertiesHint']) {
			$warnings[] = 'multiple_properties_scope_incomplete';
		}
		if ($streetMatch['status'] === 'fuzzy_unique') {
			$warnings[] = 'street_fuzzy_match';
		}
		if ($addressSelection['status'] === 'multiple_addresses') {
			$warnings[] = 'multiple_addresses_without_unit';
		}
		if ($candidates['selectedBfeNumber'] === null && \count($candidates['candidates']) > 1) {
			$warnings[] = 'multiple_bfe_candidates';
		}

		return $this->resolutionResult(
			$address,
			$parsed,
			$status,
			$streetMatch,
			\is_array($addressSelection['selected'])
				? self::nullableString($addressSelection['selected']['adressebetegnelse'] ?? null)
				: null,
			$candidates['candidates'],
			$candidates['selectedBfeNumber'],
			$warnings
		);
	}

	/**
	 * Return one normalized current BBR property graph by BFE number.
	 *
	 * @param int|string $bfeNumber Positive BFE number.
	 * @return array{
	 *     bfeNumber:int,
	 *     propertyRelationId:string,
	 *     propertyType:string,
	 *     codeListSnapshotDate:string,
	 *     statusCode:?string,
	 *     statusLabel:?string,
	 *     ownerTypeCode:?string,
	 *     ownerTypeLabel:?string,
	 *     legacyPropertyNumber:?int,
	 *     ownerApartmentNumber:?int,
	 *     registeredArea:?int,
	 *     fetchedAt:string,
	 *     addresses:list<array<string,mixed>>,
	 *     houseNumbers:list<array<string,mixed>>,
	 *     grounds:list<array<string,mixed>>,
	 *     buildings:list<array<string,mixed>>,
	 *     units:list<array<string,mixed>>,
	 *     floors:list<array<string,mixed>>,
	 *     entrances:list<array<string,mixed>>,
	 *     technicalInstallations:list<array<string,mixed>>
	 * }|null Normalized current property graph, or null when the BFE number does not exist.
	 * @throws \InvalidArgumentException When the BFE number is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 */
	public function getPropertyByBfe(int|string $bfeNumber): ?array {
		$bfe = self::normalizeBfeNumber($bfeNumber);
		$effectiveAt = self::now();

		$properties = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'BBR_Ejendomsrelation',
			fn(?string $after): string => $this->buildPropertyByBfeQuery($bfe, $effectiveAt, $after)
		);

		if ($properties === []) {
			return null;
		}
		if (\count($properties) !== 1) {
			throw new InvalidResponseException('BBR returned more than one current property relation for one BFE number.');
		}

		$property = $properties[0];
		$propertyId = self::requiredUuid($property['id_lokalId'] ?? null, 'BBR property relation id');
		$typeCode = self::requiredString($property['ejendomstype'] ?? null, 'BBR property type');
		$kind = self::propertyKind($typeCode);

		$graph = match ($kind) {
			'sfe' => $this->loadSfeGraph($propertyId, $effectiveAt),
			'bpfg' => $this->loadBpfgGraph($propertyId, $effectiveAt),
			'ejerlejlighed' => $this->loadOwnerApartmentGraph($propertyId, $effectiveAt),
			default => throw new InvalidResponseException('BBR returned an unsupported property type.')
		};

		$houseNumbers = $this->loadHouseNumbers($graph, $effectiveAt);
		$addresses = $this->loadUnitAddresses($graph['units'], $effectiveAt);

		return [
			'bfeNumber' => $bfe,
			'propertyRelationId' => $propertyId,
			'propertyType' => $kind,
			'codeListSnapshotDate' => BbrCodeLists::SNAPSHOT_DATE,
			'statusCode' => self::nullableString($property['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $property['status'] ?? null),
			'ownerTypeCode' => self::nullableString($property['ejendommensEjerforholdskode'] ?? null),
			'ownerTypeLabel' => self::codeLabel('Ejerforholdskode', $property['ejendommensEjerforholdskode'] ?? null),
			'legacyPropertyNumber' => self::nullableInt($property['ejendomsnummer'] ?? null),
			'ownerApartmentNumber' => self::nullableInt($property['ejerlejlighedsnummer'] ?? null),
			'registeredArea' => self::nullableInt($property['tinglystAreal'] ?? null),
			'fetchedAt' => $effectiveAt,
			'addresses' => $addresses,
			'houseNumbers' => $houseNumbers,
			'grounds' => \array_values($graph['grounds']),
			'buildings' => \array_values($graph['buildings']),
			'units' => \array_values($graph['units']),
			'floors' => \array_values($graph['floors']),
			'entrances' => \array_values($graph['entrances']),
			'technicalInstallations' => \array_values($graph['technicalInstallations']),
		];
	}

	/**
	 * Return technical-installation effect history for one BFE property.
	 *
	 * Behavior:
	 * - Resolves the current property graph first to identify relevant BBR object IDs.
	 * - Queries BBR/v3 without virkningstid so all effect-time versions known now are returned.
	 * - Deduplicates identical temporal versions without collapsing legitimate history rows.
	 *
	 * @param int|string $bfeNumber Positive BFE number.
	 * @return list<array<string,mixed>> Normalized technical-installation history.
	 * @throws \InvalidArgumentException When the BFE number is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 */
	public function getTechnicalInstallationHistory(int|string $bfeNumber): array {
		$bfe = self::normalizeBfeNumber($bfeNumber);
		$effectiveAt = self::now();

		$properties = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'BBR_Ejendomsrelation',
			fn(?string $after): string => $this->buildPropertyByBfeQuery($bfe, $effectiveAt, $after)
		);

		if ($properties === []) {
			return [];
		}
		if (\count($properties) !== 1) {
			throw new InvalidResponseException('BBR returned more than one current property relation for one BFE number.');
		}

		$property = $properties[0];
		$propertyId = self::requiredUuid($property['id_lokalId'] ?? null, 'BBR property relation id');
		$kind = self::propertyKind(self::requiredString($property['ejendomstype'] ?? null, 'BBR property type'));

		$graph = match ($kind) {
			'sfe' => $this->loadSfeGraph($propertyId, $effectiveAt),
			'bpfg' => $this->loadBpfgGraph($propertyId, $effectiveAt),
			'ejerlejlighed' => $this->loadOwnerApartmentGraph($propertyId, $effectiveAt),
			default => throw new InvalidResponseException('BBR returned an unsupported property type.')
		};

		$history = [];

		$historyIdSets = match ($kind) {
			'sfe' => [
				'grund' => $this->scopeIds($graph['grounds'], 'property'),
				'bygning' => $this->scopeIds($graph['buildings'], 'property'),
				'enhed' => $this->scopeIds($graph['units'], 'property'),
			],
			'bpfg' => [
				'bygning' => $this->scopeIds($graph['buildings'], 'property'),
				'enhed' => $this->scopeIds($graph['units'], 'property'),
			],
			'ejerlejlighed' => [
				'bygning' => $this->scopeIds($graph['buildings'], 'property'),
				'enhed' => $this->scopeIds($graph['units'], 'property'),
			],
			default => [],
		};

		foreach ($historyIdSets as $field => $entityIds) {
			foreach (\array_chunk($entityIds, self::IDENTIFIER_BATCH_SIZE) as $ids) {
				$this->mergeHistory(
					$history,
					$this->fetchAll(
						$this->historyService,
						$this->historyVersion,
						'BBR_TekniskAnlaeg',
						fn(?string $after): string => $this->buildTechnicalHistoryByIdsQuery(
							$field,
							$ids,
							$effectiveAt,
							$after
						)
					)
				);
			}
		}

		if ($kind === 'bpfg') {
			$this->mergeHistory(
				$history,
				$this->fetchAll(
					$this->historyService,
					$this->historyVersion,
					'BBR_TekniskAnlaeg',
					fn(?string $after): string => $this->buildTechnicalHistoryByPropertyQuery(
						'bygningPaaFremmedGrund',
						$propertyId,
						$effectiveAt,
						$after
					)
				)
			);
		}

		if ($kind === 'ejerlejlighed') {
			$this->mergeHistory(
				$history,
				$this->fetchAll(
					$this->historyService,
					$this->historyVersion,
					'BBR_TekniskAnlaeg',
					fn(?string $after): string => $this->buildTechnicalHistoryByPropertyQuery(
						'ejerlejlighed',
						$propertyId,
						$effectiveAt,
						$after
					)
				)
			);
		}

		foreach ($history as &$node) {
			$node = $this->normalizeTechnicalInstallation($node, 'property');
		}
		unset($node);

		\usort(
			$history,
			static fn(array $a, array $b): int =>
				($a['id'] <=> $b['id'])
				?: (($a['validFrom'] ?? '') <=> ($b['validFrom'] ?? ''))
				?: (($a['registeredFrom'] ?? '') <=> ($b['registeredFrom'] ?? ''))
		);

		return \array_values($history);
	}

	/**
	 * Load one SFE property graph.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function loadSfeGraph(string $propertyId, string $effectiveAt): array {
		$grounds = self::keyById(
			$this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'BBR_Grund',
				fn(?string $after): string => $this->buildGroundsByPropertyQuery(
					$propertyId,
					$effectiveAt,
					$after
				)
			)
		);

		$groundIds = \array_keys($grounds);
		$physicalBuildings = $this->fetchByIdentifierBatches(
			'BBR_Bygning',
			'grund',
			$groundIds,
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildBuildingsByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$buildingRelations = $this->fetchByIdentifierBatches(
			'BBR_BygningEjendomsrelation',
			'bygning',
			\array_keys($physicalBuildings),
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildBuildingRelationsByIdsQuery(
				$ids,
				$effectiveAt,
				$after
			)
		);

		$bpfgBuildingIds = [];
		foreach ($buildingRelations as $relation) {
			$buildingId = self::nullableString($relation['bygning'] ?? null);
			$bpfgId = self::nullableString($relation['bygningPaaFremmedGrund'] ?? null);
			if ($buildingId !== null && $bpfgId !== null) {
				$bpfgBuildingIds[$buildingId] = true;
			}
		}

		$buildings = [];
		$directBuildingIds = [];
		foreach ($physicalBuildings as $id => $node) {
			$isContext = isset($bpfgBuildingIds[$id]) || self::nullableString($node['ejerlejlighed'] ?? null) !== null;
			$scope = $isContext ? 'context' : 'property';
			$buildings[$id] = $this->normalizeBuilding($node, $scope);

			if (!$isContext) {
				$directBuildingIds[$id] = true;
			}
		}

		$unitsRaw = $this->fetchByIdentifierBatches(
			'BBR_Enhed',
			'bygning',
			\array_keys($directBuildingIds),
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildUnitsByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$unitRelations = $this->fetchByIdentifierBatches(
			'BBR_EnhedEjendomsrelation',
			'enhed',
			\array_keys($unitsRaw),
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildUnitRelationsByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$ownerApartmentUnitIds = [];
		foreach ($unitRelations as $relation) {
			$unitId = self::nullableString($relation['enhed'] ?? null);
			$ownerApartmentId = self::nullableString($relation['ejerlejlighed'] ?? null);
			if ($unitId !== null && $ownerApartmentId !== null) {
				$ownerApartmentUnitIds[$unitId] = true;
			}
		}

		$units = [];
		$directUnitIds = [];
		foreach ($unitsRaw as $id => $node) {
			$isContext = isset($ownerApartmentUnitIds[$id]);
			$scope = $isContext ? 'context' : 'property';
			$units[$id] = $this->normalizeUnit($node, $scope);

			if (!$isContext) {
				$directUnitIds[$id] = true;
			}
		}

		$floors = $this->loadFloorsForBuildings(\array_keys($directBuildingIds), $effectiveAt);
		$entrances = $this->loadEntrancesForBuildings(\array_keys($directBuildingIds), $effectiveAt);

		$technicalRaw = [];
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('grund', $groundIds, $effectiveAt)
		);
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('bygning', \array_keys($directBuildingIds), $effectiveAt)
		);
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('enhed', \array_keys($directUnitIds), $effectiveAt)
		);

		$technical = [];
		foreach ($technicalRaw as $id => $node) {
			$isContext = self::nullableString($node['bygningPaaFremmedGrund'] ?? null) !== null
				|| self::nullableString($node['ejerlejlighed'] ?? null) !== null;
			$technical[$id] = $this->normalizeTechnicalInstallation($node, $isContext ? 'context' : 'property');
		}

		foreach ($grounds as $id => $node) {
			$grounds[$id] = $this->normalizeGround($node, 'property');
		}

		return [
			'grounds' => $grounds,
			'buildings' => $buildings,
			'units' => $units,
			'floors' => $floors,
			'entrances' => $entrances,
			'technicalInstallations' => $technical,
		];
	}

	/**
	 * Load one building-on-foreign-ground property graph.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function loadBpfgGraph(string $propertyId, string $effectiveAt): array {
		$relations = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'BBR_BygningEjendomsrelation',
			fn(?string $after): string => $this->buildBuildingRelationsByPropertyQuery(
				$propertyId,
				$effectiveAt,
				$after
			)
		);

		$buildingIds = [];
		foreach ($relations as $relation) {
			$id = self::nullableString($relation['bygning'] ?? null);
			if ($id !== null && self::isUuid($id)) {
				$buildingIds[$id] = true;
			}
		}

		$buildingsRaw = $this->fetchBuildingsByIds(\array_keys($buildingIds), $effectiveAt);
		$unitsRaw = $this->fetchByIdentifierBatches(
			'BBR_Enhed',
			'bygning',
			\array_keys($buildingIds),
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildUnitsByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$unitRelations = $this->fetchUnitRelationsByIds(\array_keys($unitsRaw), $effectiveAt);
		$ownerApartmentUnitIds = [];

		foreach ($unitRelations as $relation) {
			$unitId = self::nullableString($relation['enhed'] ?? null);
			$ownerApartmentId = self::nullableString($relation['ejerlejlighed'] ?? null);

			if ($unitId !== null && $ownerApartmentId !== null) {
				$ownerApartmentUnitIds[$unitId] = true;
			}
		}

		$directUnitIds = [];
		foreach (\array_keys($unitsRaw) as $unitId) {
			if (!isset($ownerApartmentUnitIds[$unitId])) {
				$directUnitIds[$unitId] = true;
			}
		}

		$groundIds = [];
		foreach ($buildingsRaw as $node) {
			$id = self::nullableString($node['grund'] ?? null);
			if ($id !== null && self::isUuid($id)) {
				$groundIds[$id] = true;
			}
		}

		$groundsRaw = $this->fetchGroundsByIds(\array_keys($groundIds), $effectiveAt);
		$floors = $this->loadFloorsForBuildings(\array_keys($buildingIds), $effectiveAt);
		$entrances = $this->loadEntrancesForBuildings(\array_keys($buildingIds), $effectiveAt);

		$technicalRaw = self::keyById(
			$this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'BBR_TekniskAnlaeg',
				fn(?string $after): string => $this->buildTechnicalByPropertyQuery(
					'bygningPaaFremmedGrund',
					$propertyId,
					$effectiveAt,
					$after
				)
			)
		);
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('bygning', \array_keys($buildingIds), $effectiveAt)
		);
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('enhed', \array_keys($directUnitIds), $effectiveAt)
		);

		$buildings = [];
		foreach ($buildingsRaw as $id => $node) {
			$buildings[$id] = $this->normalizeBuilding($node, 'property');
		}

		$units = [];
		foreach ($unitsRaw as $id => $node) {
			$units[$id] = $this->normalizeUnit(
				$node,
				isset($ownerApartmentUnitIds[$id]) ? 'context' : 'property'
			);
		}

		$grounds = [];
		foreach ($groundsRaw as $id => $node) {
			$grounds[$id] = $this->normalizeGround($node, 'context');
		}

		$technical = [];
		foreach ($technicalRaw as $id => $node) {
			$technical[$id] = $this->normalizeTechnicalInstallation($node, 'property');
		}

		return [
			'grounds' => $grounds,
			'buildings' => $buildings,
			'units' => $units,
			'floors' => $floors,
			'entrances' => $entrances,
			'technicalInstallations' => $technical,
		];
	}

	/**
	 * Load one owner-apartment property graph.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	private function loadOwnerApartmentGraph(string $propertyId, string $effectiveAt): array {
		$relations = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'BBR_EnhedEjendomsrelation',
			fn(?string $after): string => $this->buildUnitRelationsByPropertyQuery(
				$propertyId,
				$effectiveAt,
				$after
			)
		);

		$unitIds = [];
		foreach ($relations as $relation) {
			$id = self::nullableString($relation['enhed'] ?? null);
			if ($id !== null && self::isUuid($id)) {
				$unitIds[$id] = true;
			}
		}

		$unitsRaw = $this->fetchUnitsByIds(\array_keys($unitIds), $effectiveAt);
		$hostBuildingIds = [];
		$floorIds = [];
		$entranceIds = [];

		foreach ($unitsRaw as $unit) {
			self::addUuid($hostBuildingIds, $unit['bygning'] ?? null);
			self::addUuid($floorIds, $unit['etage'] ?? null);
			self::addUuid($entranceIds, $unit['opgang'] ?? null);
		}

		$hostBuildings = $this->fetchBuildingsByIds(\array_keys($hostBuildingIds), $effectiveAt);
		$directBuildings = self::keyById(
			$this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'BBR_Bygning',
				fn(?string $after): string => $this->buildBuildingsByOwnerApartmentQuery(
					$propertyId,
					$effectiveAt,
					$after
				)
			)
		);

		$buildings = [];
		foreach ($hostBuildings as $id => $node) {
			$buildings[$id] = $this->normalizeBuilding($node, 'context');
		}
		foreach ($directBuildings as $id => $node) {
			$buildings[$id] = $this->normalizeBuilding($node, 'property');
		}

		$groundIds = [];
		foreach (\array_merge($hostBuildings, $directBuildings) as $node) {
			self::addUuid($groundIds, $node['grund'] ?? null);
		}
		$groundsRaw = $this->fetchGroundsByIds(\array_keys($groundIds), $effectiveAt);

		$floorsRaw = $this->fetchFloorsByIds(\array_keys($floorIds), $effectiveAt);
		$entrancesRaw = $this->fetchEntrancesByIds(\array_keys($entranceIds), $effectiveAt);

		$technicalRaw = self::keyById(
			$this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'BBR_TekniskAnlaeg',
				fn(?string $after): string => $this->buildTechnicalByPropertyQuery(
					'ejerlejlighed',
					$propertyId,
					$effectiveAt,
					$after
				)
			)
		);
		$this->mergeById(
			$technicalRaw,
			$this->fetchTechnicalByIds('enhed', \array_keys($unitIds), $effectiveAt)
		);

		$grounds = [];
		foreach ($groundsRaw as $id => $node) {
			$grounds[$id] = $this->normalizeGround($node, 'context');
		}

		$units = [];
		foreach ($unitsRaw as $id => $node) {
			$units[$id] = $this->normalizeUnit($node, 'property');
		}

		$floors = [];
		foreach ($floorsRaw as $id => $node) {
			$floors[$id] = $this->normalizeFloor($node, 'context');
		}

		$entrances = [];
		foreach ($entrancesRaw as $id => $node) {
			$entrances[$id] = $this->normalizeEntrance($node, 'context');
		}

		$technical = [];
		foreach ($technicalRaw as $id => $node) {
			$technical[$id] = $this->normalizeTechnicalInstallation($node, 'property');
		}

		return [
			'grounds' => $grounds,
			'buildings' => $buildings,
			'units' => $units,
			'floors' => $floors,
			'entrances' => $entrances,
			'technicalInstallations' => $technical,
		];
	}

	/**
	 * Resolve BFE candidates behind one DAR house number and optional selected address.
	 *
	 * @return array{
	 *     candidates:list<array{
	 *         bfeNumber:int,
	 *         propertyType:string,
	 *         propertyRelationId:string,
	 *         evidence:list<string>
	 *     }>,
	 *     selectedBfeNumber:?int
	 * }
	 */
	private function resolveBfeCandidates(
		string $houseNumberId,
		?array $selectedAddress,
		string $effectiveAt
	): array {
		$buildings = self::keyById(
			$this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'BBR_Bygning',
				fn(?string $after): string => $this->buildBuildingsByHouseNumberQuery(
					$houseNumberId,
					$effectiveAt,
					$after
				)
			)
		);

		$groundIds = [];
		$propertyIds = [];
		$evidence = [];

		foreach ($buildings as $building) {
			self::addUuid($groundIds, $building['grund'] ?? null);

			$ownerApartmentId = self::nullableString($building['ejerlejlighed'] ?? null);
			if ($ownerApartmentId !== null && self::isUuid($ownerApartmentId)) {
				$propertyIds[$ownerApartmentId] = true;
				$evidence[$ownerApartmentId]['building_ejerlejlighed'] = true;
			}
		}

		$grounds = $this->fetchGroundsByIds(\array_keys($groundIds), $effectiveAt);
		foreach ($grounds as $ground) {
			$propertyId = self::nullableString($ground['bestemtFastEjendom'] ?? null);
			if ($propertyId !== null && self::isUuid($propertyId)) {
				$propertyIds[$propertyId] = true;
				$evidence[$propertyId]['ground_sfe'] = true;
			}
		}

		$buildingRelations = $this->fetchBuildingRelationsByIds(\array_keys($buildings), $effectiveAt);
		foreach ($buildingRelations as $relation) {
			$propertyId = self::nullableString($relation['bygningPaaFremmedGrund'] ?? null);
			if ($propertyId !== null && self::isUuid($propertyId)) {
				$propertyIds[$propertyId] = true;
				$evidence[$propertyId]['building_bpfg'] = true;
			}
		}

		$selectedUnitIds = [];
		$unitIds = [];

		if ($selectedAddress !== null) {
			$addressId = self::requiredUuid($selectedAddress['id_lokalId'] ?? null, 'DAR selected address id');
			$units = self::keyById(
				$this->fetchAll(
					$this->currentService,
					$this->currentVersion,
					'BBR_Enhed',
					fn(?string $after): string => $this->buildUnitsByAddressQuery(
						$addressId,
						$effectiveAt,
						$after
					)
				)
			);

			foreach (\array_keys($units) as $unitId) {
				$unitIds[$unitId] = true;
				$selectedUnitIds[$unitId] = true;
			}
		} else {
			$units = $this->fetchByIdentifierBatches(
				'BBR_Enhed',
				'bygning',
				\array_keys($buildings),
				$effectiveAt,
				fn(string $field, array $ids, ?string $after): string => $this->buildUnitsByIdsQuery(
					$field,
					$ids,
					$effectiveAt,
					$after
				)
			);

			foreach (\array_keys($units) as $unitId) {
				$unitIds[$unitId] = true;
			}
		}

		$unitRelations = $this->fetchUnitRelationsByIds(\array_keys($unitIds), $effectiveAt);
		foreach ($unitRelations as $relation) {
			$propertyId = self::nullableString($relation['ejerlejlighed'] ?? null);
			$unitId = self::nullableString($relation['enhed'] ?? null);

			if ($propertyId === null || !self::isUuid($propertyId)) {
				continue;
			}

			$propertyIds[$propertyId] = true;
			$evidence[$propertyId][
				$unitId !== null && isset($selectedUnitIds[$unitId])
					? 'selected_unit_ejerlejlighed'
					: 'unit_ejerlejlighed'
			] = true;
		}

		$properties = $this->fetchPropertiesByIds(\array_keys($propertyIds), $effectiveAt);
		$candidates = [];

		foreach ($properties as $id => $property) {
			$bfe = self::nullableInt($property['bfeNummer'] ?? null);
			if ($bfe === null) {
				throw new InvalidResponseException('BBR property candidate does not contain a valid BFE number.');
			}

			$typeCode = self::requiredString($property['ejendomstype'] ?? null, 'BBR property candidate type');
			$candidates[] = [
				'bfeNumber' => $bfe,
				'propertyType' => self::propertyKind($typeCode),
				'propertyRelationId' => $id,
				'evidence' => \array_keys($evidence[$id] ?? []),
			];
		}

		\usort(
			$candidates,
			static fn(array $a, array $b): int => $a['bfeNumber'] <=> $b['bfeNumber']
		);

		$selected = null;
		$selectedUnitCandidates = \array_values(
			\array_filter(
				$candidates,
				static fn(array $candidate): bool => \in_array(
					'selected_unit_ejerlejlighed',
					$candidate['evidence'],
					true
				)
			)
		);

		if (\count($selectedUnitCandidates) === 1) {
			$selected = $selectedUnitCandidates[0]['bfeNumber'];
		} elseif (\count($candidates) === 1) {
			$selected = $candidates[0]['bfeNumber'];
		}

		return [
			'candidates' => $candidates,
			'selectedBfeNumber' => $selected,
		];
	}

	/**
	 * Resolve a street inside one exact postcode.
	 *
	 * @return array{
	 *     status:string,
	 *     input:string,
	 *     matched:?string,
	 *     selected:array<string,mixed>|null,
	 *     candidates:list<array{name:string,distance:int,similarity:float}>
	 * }
	 */
	private function resolveStreet(string $input, string $postcodeId, string $effectiveAt): array {
		$exact = $this->fetchAll(
			$this->currentService,
			$this->currentVersion,
			'DAR_NavngivenVej',
			fn(?string $after): string => $this->buildExactStreetsQuery($input, $effectiveAt, $after)
		);

		if ($exact !== []) {
			$exactIds = \array_keys(self::keyById($exact));
			$relations = $this->fetchStreetPostcodeRelations($postcodeId, $exactIds, $effectiveAt);
			$validIds = [];

			foreach ($relations as $relation) {
				$id = self::nullableString($relation['navngivenVej'] ?? null);
				if ($id !== null) {
					$validIds[$id] = true;
				}
			}

			$matches = [];
			foreach ($exact as $street) {
				$id = self::nullableString($street['id_lokalId'] ?? null);
				if ($id !== null && isset($validIds[$id])) {
					$matches[] = $street;
				}
			}

			if (\count($matches) === 1) {
				return [
					'status' => 'exact',
					'input' => $input,
					'matched' => self::nullableString($matches[0]['vejnavn'] ?? null),
					'selected' => $matches[0],
					'candidates' => [[
						'name' => self::requiredString($matches[0]['vejnavn'] ?? null, 'DAR street name'),
						'distance' => 0,
						'similarity' => 1.0,
					]],
				];
			}
		}

		$relations = $this->fetchStreetPostcodeRelations($postcodeId, null, $effectiveAt);
		$streetIds = [];
		foreach ($relations as $relation) {
			self::addUuid($streetIds, $relation['navngivenVej'] ?? null);
		}

		$streets = [];
		foreach (\array_chunk(\array_keys($streetIds), self::IDENTIFIER_BATCH_SIZE) as $ids) {
			$this->mergeById(
				$streets,
				$this->fetchStreetsByIds($ids, $effectiveAt)
			);
		}

		return $this->chooseStreet($input, \array_values($streets));
	}

	/**
	 * Choose a deterministic street candidate.
	 *
	 * @param string $input User street name.
	 * @param list<array<string,mixed>> $streets Candidate DAR streets.
	 * @return array<string,mixed> Street match result.
	 */
	private function chooseStreet(string $input, array $streets): array {
		$inputNormalized = self::normalizeForDistance($input);
		$scored = [];

		foreach ($streets as $street) {
			$name = self::nullableString($street['vejnavn'] ?? null);
			if ($name === null) {
				continue;
			}

			$normalized = self::normalizeForDistance($name);
			$distance = \levenshtein($inputNormalized, $normalized);
			$maximum = \max(\strlen($inputNormalized), \strlen($normalized), 1);
			$similarity = 1 - ($distance / $maximum);

			$scored[] = [
				'name' => $name,
				'distance' => $distance,
				'similarity' => \round($similarity, 4),
				'row' => $street,
			];
		}

		\usort(
			$scored,
			static fn(array $a, array $b): int =>
				$b['similarity'] <=> $a['similarity']
				?: $a['distance'] <=> $b['distance']
				?: \strcmp($a['name'], $b['name'])
		);

		$best = $scored[0] ?? null;
		$second = $scored[1] ?? null;
		$selected = null;
		$status = 'not_found';

		if ($best !== null) {
			$status = 'ambiguous';

			if ($best['distance'] === 0) {
				$selected = $best['row'];
				$status = 'exact';
			} else {
				$secondSimilarity = $second !== null ? (float)$second['similarity'] : 0.0;
				$margin = (float)$best['similarity'] - $secondSimilarity;

				if ((float)$best['similarity'] >= 0.85 && $margin >= 0.08) {
					$selected = $best['row'];
					$status = 'fuzzy_unique';
				}
			}
		}

		return [
			'status' => $status,
			'input' => $input,
			'matched' => \is_array($selected)
				? self::nullableString($selected['vejnavn'] ?? null)
				: null,
			'selected' => $selected,
			'candidates' => \array_map(
				static fn(array $row): array => [
					'name' => $row['name'],
					'distance' => $row['distance'],
					'similarity' => $row['similarity'],
				],
				\array_slice($scored, 0, 5)
			),
		];
	}

	/**
	 * Parse auction-style address input.
	 *
	 * @return array{
	 *     lookupInput:string,
	 *     multiplePropertiesHint:bool,
	 *     street:?string,
	 *     houseNumber:?string,
	 *     unit:array{raw:string,floor:?string,door:?string}|null,
	 *     postcode:?string,
	 *     cityInput:?string
	 * }
	 */
	private function parseAuctionAddress(string $rawInput): array {
		$value = self::normalizeSpaces($rawInput);
		$multiple = (bool)\preg_match('~(?:,?\s*)m\.?\s*fl\.?\s*$~iu', $value);

		if ($multiple) {
			$value = (string)\preg_replace('~(?:,?\s*)m\.?\s*fl\.?\s*$~iu', '', $value);
			$value = \rtrim($value, " \t\n\r\0\x0B,");
		}

		$postcode = null;
		$city = null;
		$prefix = $value;

		if (\preg_match('~^(.*?),?\s+([0-9]{4})\s+(.+)$~u', $value, $matches)) {
			$prefix = \rtrim((string)$matches[1], " \t\n\r\0\x0B,");
			$postcode = (string)$matches[2];
			$city = \trim((string)$matches[3]);
		}

		$street = null;
		$houseNumber = null;
		$unit = null;

		if (\preg_match('~^(.+?)\s+([0-9]+[A-Za-z]?)\s*(?:,\s*(.+))?$~u', $prefix, $matches)) {
			$street = \trim((string)$matches[1]);
			$houseNumber = \strtoupper((string)$matches[2]);
			$unitText = isset($matches[3]) ? \trim((string)$matches[3]) : '';

			if ($unitText !== '') {
				$unit = $this->parseUnit($unitText);
			}
		}

		return [
			'lookupInput' => $value,
			'multiplePropertiesHint' => $multiple,
			'street' => $street,
			'houseNumber' => $houseNumber,
			'unit' => $unit,
			'postcode' => $postcode,
			'cityInput' => $city,
		];
	}

	/**
	 * Parse one floor/door fragment.
	 *
	 * @return array{raw:string,floor:?string,door:?string}
	 */
	private function parseUnit(string $value): array {
		$normalized = self::normalizeSpaces(
			(string)\preg_replace('/[,.]+/u', ' ', $value)
		);
		$parts = \preg_split('/\s+/u', $normalized) ?: [];

		return [
			'raw' => $value,
			'floor' => isset($parts[0]) ? self::normalizeUnitPart((string)$parts[0]) : null,
			'door' => \count($parts) > 1
				? self::normalizeUnitPart(\implode(' ', \array_slice($parts, 1)))
				: null,
		];
	}

	/**
	 * Select one DAR address under a house number.
	 *
	 * @param list<array<string,mixed>> $addresses Address nodes.
	 * @param array{raw:string,floor:?string,door:?string}|null $unit Parsed unit.
	 * @return array{status:string,selected:array<string,mixed>|null}
	 */
	private function selectAddress(array $addresses, ?array $unit): array {
		if ($unit !== null) {
			$matches = [];

			foreach ($addresses as $address) {
				if (
					self::normalizeUnitPart((string)($address['etagebetegnelse'] ?? '')) === $unit['floor']
					&& self::normalizeUnitPart((string)($address['doerbetegnelse'] ?? '')) === $unit['door']
				) {
					$matches[] = $address;
				}
			}

			return [
				'status' => \count($matches) === 1 ? 'unit_exact' : 'unit_not_unique',
				'selected' => \count($matches) === 1 ? $matches[0] : null,
			];
		}

		if (\count($addresses) === 1) {
			return [
				'status' => 'single_address',
				'selected' => $addresses[0],
			];
		}

		return [
			'status' => $addresses === [] ? 'no_address' : 'multiple_addresses',
			'selected' => null,
		];
	}

	/**
	 * Determine final address-resolution status.
	 */
	private function resolutionStatus(
		bool $multiplePropertiesHint,
		array $addressSelection,
		array $candidateData
	): string {
		if ($candidateData['selectedBfeNumber'] === null) {
			if ($addressSelection['status'] === 'unit_not_unique') {
				return 'ambiguous_unit';
			}

			return $candidateData['candidates'] === []
				? 'no_bfe'
				: 'ambiguous_bfe';
		}

		return $multiplePropertiesHint
			? 'primary_resolved_scope_incomplete'
			: 'resolved';
	}

	/**
	 * Build one public resolution result.
	 */
	private function resolutionResult(
		string $input,
		array $parsed,
		string $status,
		?array $streetMatch,
		?string $matchedAddress,
		array $candidates,
		?int $selectedBfeNumber,
		array $warnings
	): array {
		if ($streetMatch !== null) {
			unset($streetMatch['selected']);
		}

		return [
			'input' => $input,
			'lookupInput' => $parsed['lookupInput'],
			'multiplePropertiesHint' => $parsed['multiplePropertiesHint'],
			'status' => $status,
			'parsed' => [
				'street' => $parsed['street'],
				'houseNumber' => $parsed['houseNumber'],
				'unit' => $parsed['unit'],
				'postcode' => $parsed['postcode'],
				'cityInput' => $parsed['cityInput'],
			],
			'streetMatch' => $streetMatch,
			'matchedAddress' => $matchedAddress,
			'bfeCandidates' => $candidates,
			'selectedBfeNumber' => $selectedBfeNumber,
			'warnings' => $warnings,
		];
	}

	/**
	 * Fetch all pages from one Datafordeler connection.
	 *
	 * @param callable(?string):string $queryBuilder Cursor-aware query builder.
	 * @return list<array<string,mixed>> Connection nodes.
	 */
	private function fetchAll(
		string $service,
		string $version,
		string $root,
		callable $queryBuilder
	): array {
		$nodes = [];
		$after = null;

		do {
			$data = $this->datafordeler->query(
				$service,
				$version,
				$queryBuilder($after)
			);

			$connection = $data[$root] ?? null;
			if (!\is_array($connection)) {
				throw new InvalidResponseException('Datafordeler response does not contain a valid ' . $root . ' connection.');
			}

			$pageNodes = $connection['nodes'] ?? null;
			$pageInfo = $connection['pageInfo'] ?? null;

			if (!\is_array($pageNodes) || !\is_array($pageInfo)) {
				throw new InvalidResponseException($root . ' connection does not contain valid nodes and pageInfo.');
			}

			foreach ($pageNodes as $node) {
				if (!\is_array($node)) {
					throw new InvalidResponseException($root . ' connection contains an invalid node.');
				}
				$nodes[] = $node;
			}

			$hasNext = $pageInfo['hasNextPage'] ?? null;
			if (!\is_bool($hasNext)) {
				throw new InvalidResponseException($root . ' pageInfo does not contain a valid hasNextPage value.');
			}

			if ($hasNext) {
				$after = self::nullableString($pageInfo['endCursor'] ?? null);
				if ($after === null) {
					throw new InvalidResponseException($root . ' pageInfo is missing endCursor for the next page.');
				}
			} else {
				$after = null;
			}
		} while ($after !== null);

		return $nodes;
	}

	/**
	 * Fetch current nodes in batches of at most 100 identifiers.
	 *
	 * @param callable(string,list<string>,?string):string $builder Query builder.
	 * @return array<string,array<string,mixed>> Nodes keyed by id_lokalId.
	 */
	private function fetchByIdentifierBatches(
		string $root,
		string $field,
		array $ids,
		string $effectiveAt,
		callable $builder
	): array {
		$result = [];

		foreach (\array_chunk($ids, self::IDENTIFIER_BATCH_SIZE) as $batch) {
			$nodes = $this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				$root,
				fn(?string $after): string => $builder($field, $batch, $after)
			);
			$this->mergeById($result, self::keyById($nodes));
		}

		return $result;
	}

	/**
	 * Load current buildings by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchBuildingsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Bygning',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildBuildingsByIdsQuery(
				$field,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current grounds by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchGroundsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Grund',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildGroundsByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current units by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchUnitsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Enhed',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildUnitsByIdsQuery(
				$field,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current building-property relations by building UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchBuildingRelationsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_BygningEjendomsrelation',
			'bygning',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildBuildingRelationsByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current unit-property relations by unit UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchUnitRelationsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_EnhedEjendomsrelation',
			'enhed',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildUnitRelationsByIdsQuery(
				$field,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current property relations by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchPropertiesByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Ejendomsrelation',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildPropertiesByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current floors for buildings.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function loadFloorsForBuildings(array $buildingIds, string $effectiveAt): array {
		$raw = $this->fetchByIdentifierBatches(
			'BBR_Etage',
			'bygning',
			$buildingIds,
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildFloorsByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$result = [];
		foreach ($raw as $id => $node) {
			$result[$id] = $this->normalizeFloor($node, 'property');
		}

		return $result;
	}

	/**
	 * Load current entrances for buildings.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function loadEntrancesForBuildings(array $buildingIds, string $effectiveAt): array {
		$raw = $this->fetchByIdentifierBatches(
			'BBR_Opgang',
			'bygning',
			$buildingIds,
			$effectiveAt,
			fn(string $field, array $ids, ?string $after): string => $this->buildEntrancesByIdsQuery(
				$field,
				$ids,
				$effectiveAt,
				$after
			)
		);

		$result = [];
		foreach ($raw as $id => $node) {
			$result[$id] = $this->normalizeEntrance($node, 'property');
		}

		return $result;
	}

	/**
	 * Load current floors by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchFloorsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Etage',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildFloorsByIdsQuery(
				$field,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current entrances by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchEntrancesByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_Opgang',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildEntrancesByIdsQuery(
				$field,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load current technical installations by one identifier field.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchTechnicalByIds(string $field, array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'BBR_TekniskAnlaeg',
			$field,
			$ids,
			$effectiveAt,
			fn(string $identifierField, array $batch, ?string $after): string => $this->buildTechnicalByIdsQuery(
				$identifierField,
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Load normalized DAR house numbers referenced by the graph.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function loadHouseNumbers(array $graph, string $effectiveAt): array {
		$ids = [];

		foreach ($graph['grounds'] as $node) {
			self::addUuid($ids, $node['houseNumberId'] ?? null);
		}
		foreach ($graph['buildings'] as $node) {
			self::addUuid($ids, $node['houseNumberId'] ?? null);
		}
		foreach ($graph['entrances'] as $node) {
			self::addUuid($ids, $node['houseNumberId'] ?? null);
		}
		foreach ($graph['technicalInstallations'] as $node) {
			self::addUuid($ids, $node['houseNumberId'] ?? null);
		}

		$raw = $this->fetchByIdentifierBatches(
			'DAR_Husnummer',
			'id_lokalId',
			\array_keys($ids),
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildHouseNumbersByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);

		$result = [];
		foreach ($raw as $node) {
			$result[] = [
				'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'DAR house-number id'),
				'formatted' => self::nullableString($node['adgangsadressebetegnelse'] ?? null),
				'houseNumber' => self::nullableString($node['husnummertekst'] ?? null),
				'landParcelId' => self::nullableString($node['jordstykke'] ?? null),
				'buildingId' => self::nullableString($node['adgangTilBygning'] ?? null),
				'technicalInstallationId' => self::nullableString($node['adgangTilTekniskAnlaeg'] ?? null),
			];
		}

		return $result;
	}

	/**
	 * Load normalized DAR addresses referenced by BBR units.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function loadUnitAddresses(array $units, string $effectiveAt): array {
		$ids = [];

		foreach ($units as $unit) {
			self::addUuid($ids, $unit['addressId'] ?? null);
		}

		$raw = $this->fetchByIdentifierBatches(
			'DAR_Adresse',
			'id_lokalId',
			\array_keys($ids),
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildAddressesByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);

		$result = [];
		foreach ($raw as $node) {
			$result[] = [
				'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'DAR address id'),
				'formatted' => self::nullableString($node['adressebetegnelse'] ?? null),
				'houseNumberId' => self::nullableString($node['husnummer'] ?? null),
				'floor' => self::nullableString($node['etagebetegnelse'] ?? null),
				'door' => self::nullableString($node['doerbetegnelse'] ?? null),
			];
		}

		return $result;
	}

	/**
	 * Normalize one ground.
	 */
	private function normalizeGround(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR ground id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'propertyRelationId' => self::nullableString($node['bestemtFastEjendom'] ?? null),
			'houseNumberId' => self::nullableString($node['husnummer'] ?? null),
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
			'waterSupplyCode' => self::nullableString($node['gru009Vandforsyning'] ?? null),
			'waterSupplyLabel' => self::codeLabel('Vandforsyning', $node['gru009Vandforsyning'] ?? null),
			'drainageCode' => self::nullableString($node['gru010Afloebsforhold'] ?? null),
			'drainageLabel' => self::codeLabel('Afloebsforhold', $node['gru010Afloebsforhold'] ?? null),
			'dischargePermitCode' => self::nullableString($node['gru021Udledningstilladelse'] ?? null),
			'dischargePermitLabel' => self::codeLabel('Udledningstilladelse', $node['gru021Udledningstilladelse'] ?? null),
			'wastewaterMembershipCode' => self::nullableString($node['gru022MedlemskabAfSpildevandsforsyning'] ?? null),
			'wastewaterMembershipLabel' => self::codeLabel('MedlemsskabAfSplidevandforsyning', $node['gru022MedlemskabAfSpildevandsforsyning'] ?? null),
			'wastewaterOrderCode' => self::nullableString($node['gru023PaabudVedrSpildevandsafledning'] ?? null),
			'wastewaterOrderLabel' => self::codeLabel('Rensningspaabud', $node['gru023PaabudVedrSpildevandsafledning'] ?? null),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one building.
	 */
	private function normalizeBuilding(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR building id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'houseNumberId' => self::nullableString($node['husnummer'] ?? null),
			'groundId' => self::nullableString($node['grund'] ?? null),
			'landParcelId' => self::nullableString($node['jordstykke'] ?? null),
			'ownerApartmentPropertyRelationId' => self::nullableString($node['ejerlejlighed'] ?? null),
			'buildingNumber' => self::nullableInt($node['byg007Bygningsnummer'] ?? null),
			'applicationCode' => self::nullableString($node['byg021BygningensAnvendelse'] ?? null),
			'applicationLabel' => self::codeLabel('BygAnvendelse', $node['byg021BygningensAnvendelse'] ?? null),
			'apartmentsWithKitchen' => self::nullableInt($node['byg024AntalLejlighederMedKoekken'] ?? null),
			'apartmentsWithoutKitchen' => self::nullableInt($node['byg025AntalLejlighederUdenKoekken'] ?? null),
			'constructionYear' => self::nullableInt($node['byg026Opfoerelsesaar'] ?? null),
			'remodelYear' => self::nullableInt($node['byg027OmTilbygningsaar'] ?? null),
			'waterSupplyCode' => self::nullableString($node['byg030Vandforsyning'] ?? null),
			'waterSupplyLabel' => self::codeLabel('Vandforsyning', $node['byg030Vandforsyning'] ?? null),
			'drainageCode' => self::nullableString($node['byg031Afloebsforhold'] ?? null),
			'drainageLabel' => self::codeLabel('Afloebsforhold', $node['byg031Afloebsforhold'] ?? null),
			'outerWallMaterialCode' => self::nullableString($node['byg032YdervaeggensMateriale'] ?? null),
			'outerWallMaterialLabel' => self::codeLabel('YdervaeggenesMateriale', $node['byg032YdervaeggensMateriale'] ?? null),
			'roofMaterialCode' => self::nullableString($node['byg033Tagdaekningsmateriale'] ?? null),
			'roofMaterialLabel' => self::codeLabel('Tagdaekningsmateriale', $node['byg033Tagdaekningsmateriale'] ?? null),
			'supplementaryOuterWallMaterialCode' => self::nullableString($node['byg034SupplerendeYdervaeggensMateriale'] ?? null),
			'supplementaryOuterWallMaterialLabel' => self::codeLabel('YdervaeggenesMateriale', $node['byg034SupplerendeYdervaeggensMateriale'] ?? null),
			'supplementaryRoofMaterialCode' => self::nullableString($node['byg035SupplerendeTagdaekningsMateriale'] ?? null),
			'supplementaryRoofMaterialLabel' => self::codeLabel('Tagdaekningsmateriale', $node['byg035SupplerendeTagdaekningsMateriale'] ?? null),
			'asbestosCode' => self::nullableString($node['byg036AsbestholdigtMateriale'] ?? null),
			'asbestosLabel' => self::codeLabel('AsbestholdigtMateriale', $node['byg036AsbestholdigtMateriale'] ?? null),
			'materialSourceCode' => self::nullableString($node['byg037KildeTilBygningensMaterialer'] ?? null),
			'materialSourceLabel' => self::codeLabel('KildeTilOplysninger', $node['byg037KildeTilBygningensMaterialer'] ?? null),
			'totalArea' => self::nullableInt($node['byg038SamletBygningsareal'] ?? null),
			'residentialArea' => self::nullableInt($node['byg039BygningensSamledeBoligAreal'] ?? null),
			'businessArea' => self::nullableInt($node['byg040BygningensSamledeErhvervsAreal'] ?? null),
			'builtArea' => self::nullableInt($node['byg041BebyggetAreal'] ?? null),
			'integratedGarageArea' => self::nullableInt($node['byg042ArealIndbyggetGarage'] ?? null),
			'integratedCarportArea' => self::nullableInt($node['byg043ArealIndbyggetCarport'] ?? null),
			'integratedShedArea' => self::nullableInt($node['byg044ArealIndbyggetUdhus'] ?? null),
			'integratedConservatoryArea' => self::nullableInt($node['byg045ArealIndbyggetUdestueEllerLign'] ?? null),
			'coveredArea' => self::nullableInt($node['byg049ArealAfOverdaekketAreal'] ?? null),
			'areaSourceCode' => self::nullableString($node['byg053BygningsarealerKilde'] ?? null),
			'areaSourceLabel' => self::codeLabel('KildeTilOplysninger', $node['byg053BygningsarealerKilde'] ?? null),
			'floorCount' => self::nullableInt($node['byg054AntalEtager'] ?? null),
			'heatingInstallationCode' => self::nullableString($node['byg056Varmeinstallation'] ?? null),
			'heatingInstallationLabel' => self::codeLabel('Varmeinstallation', $node['byg056Varmeinstallation'] ?? null),
			'heatingMediumCode' => self::nullableString($node['byg057Opvarmningsmiddel'] ?? null),
			'heatingMediumLabel' => self::codeLabel('Opvarmningsmiddel', $node['byg057Opvarmningsmiddel'] ?? null),
			'supplementaryHeatingCode' => self::nullableString($node['byg058SupplerendeVarme'] ?? null),
			'supplementaryHeatingLabel' => self::codeLabel('SupplerendeVarme', $node['byg058SupplerendeVarme'] ?? null),
			'preservationCode' => self::nullableString($node['byg070Fredning'] ?? null),
			'preservationLabel' => self::codeLabel('Fredning', $node['byg070Fredning'] ?? null),
			'preservationReference' => self::nullableString($node['byg071BevaringsvaerdighedReference'] ?? null),
			'notes' => self::nullableString($node['byg500Notatlinjer'] ?? null),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR unit.
	 */
	private function normalizeUnit(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR unit id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'addressId' => self::nullableString($node['adresseIdentificerer'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'floorId' => self::nullableString($node['etage'] ?? null),
			'entranceId' => self::nullableString($node['opgang'] ?? null),
			'applicationCode' => self::nullableString($node['enh020EnhedensAnvendelse'] ?? null),
			'applicationLabel' => self::codeLabel('EnhAnvendelse', $node['enh020EnhedensAnvendelse'] ?? null),
			'housingTypeCode' => self::nullableString($node['enh023Boligtype'] ?? null),
			'housingTypeLabel' => self::codeLabel('Boligtype', $node['enh023Boligtype'] ?? null),
			'totalArea' => self::nullableInt($node['enh026EnhedensSamledeAreal'] ?? null),
			'residentialArea' => self::nullableInt($node['enh027ArealTilBeboelse'] ?? null),
			'businessArea' => self::nullableInt($node['enh028ArealTilErhverv'] ?? null),
			'roomCount' => self::nullableInt($node['enh031AntalVaerelser'] ?? null),
			'toiletCode' => self::nullableString($node['enh032Toiletforhold'] ?? null),
			'toiletLabel' => self::codeLabel('Toiletforhold', $node['enh032Toiletforhold'] ?? null),
			'bathCode' => self::nullableString($node['enh033Badeforhold'] ?? null),
			'bathLabel' => self::codeLabel('Badeforhold', $node['enh033Badeforhold'] ?? null),
			'kitchenCode' => self::nullableString($node['enh034Koekkenforhold'] ?? null),
			'kitchenLabel' => self::codeLabel('Koekkenforhold', $node['enh034Koekkenforhold'] ?? null),
			'energySupplyCode' => self::nullableString($node['enh035Energiforsyning'] ?? null),
			'energySupplyLabel' => self::codeLabel('Energiforsyning', $node['enh035Energiforsyning'] ?? null),
			'rentalCode' => self::nullableString($node['enh045Udlejningsforhold'] ?? null),
			'rentalLabel' => self::codeLabel('Udlejningsforhold', $node['enh045Udlejningsforhold'] ?? null),
			'heatingInstallationCode' => self::nullableString($node['enh051Varmeinstallation'] ?? null),
			'heatingInstallationLabel' => self::codeLabel('Varmeinstallation', $node['enh051Varmeinstallation'] ?? null),
			'heatingMediumCode' => self::nullableString($node['enh052Opvarmningsmiddel'] ?? null),
			'heatingMediumLabel' => self::codeLabel('Opvarmningsmiddel', $node['enh052Opvarmningsmiddel'] ?? null),
			'supplementaryHeatingCode' => self::nullableString($node['enh053SupplerendeVarme'] ?? null),
			'supplementaryHeatingLabel' => self::codeLabel('SupplerendeVarme', $node['enh053SupplerendeVarme'] ?? null),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one floor.
	 */
	private function normalizeFloor(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR floor id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'label' => self::nullableString($node['eta006BygningensEtagebetegnelse'] ?? null),
			'totalArea' => self::nullableInt($node['eta020SamletArealAfEtage'] ?? null),
			'usedAtticArea' => self::nullableInt($node['eta021ArealAfUdnyttetDelAfTagetage'] ?? null),
			'basementArea' => self::nullableInt($node['eta022Kaelderareal'] ?? null),
			'legalBasementResidentialArea' => self::nullableInt($node['eta023ArealAfLovligBeboelseIKaelder'] ?? null),
			'typeCode' => self::nullableString($node['eta025Etagetype'] ?? null),
			'typeLabel' => self::codeLabel('EtageType', $node['eta025Etagetype'] ?? null),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one entrance.
	 */
	private function normalizeEntrance(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR entrance id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'houseNumberId' => self::nullableString($node['adgangFraHusnummer'] ?? null),
			'elevatorCode' => self::nullableString($node['opg020Elevator'] ?? null),
			'elevatorLabel' => self::codeLabel('Elevator', $node['opg020Elevator'] ?? null),
			'houseNumberFunctionCode' => self::nullableString($node['opg021HusnummerFunktion'] ?? null),
			'houseNumberFunctionLabel' => self::codeLabel('HusnummerRolle', $node['opg021HusnummerFunktion'] ?? null),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one technical installation.
	 */
	private function normalizeTechnicalInstallation(array $node, string $scope): array {
		$coordinate = $node['tek109Koordinat'] ?? null;

		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR technical-installation id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'houseNumberId' => self::nullableString($node['husnummer'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'groundId' => self::nullableString($node['grund'] ?? null),
			'unitId' => self::nullableString($node['enhed'] ?? null),
			'landParcelId' => self::nullableString($node['jordstykke'] ?? null),
			'buildingOnForeignGroundPropertyRelationId' => self::nullableString($node['bygningPaaFremmedGrund'] ?? null),
			'ownerApartmentPropertyRelationId' => self::nullableString($node['ejerlejlighed'] ?? null),
			'installationNumber' => self::nullableInt($node['tek007Anlaegsnummer'] ?? null),
			'classificationCode' => self::nullableString($node['tek020Klassifikation'] ?? null),
			'classificationLabel' => self::codeLabel('Klassifikation', $node['tek020Klassifikation'] ?? null),
			'makeType' => self::nullableString($node['tek021FabrikatType'] ?? null),
			'establishmentYear' => self::nullableInt($node['tek024Etableringsaar'] ?? null),
			'remodelYear' => self::nullableInt($node['tek025TilOmbygningsaar'] ?? null),
			'oilTankSizeClassCode' => self::nullableString($node['tek026StoerrelsesklasseOlietank'] ?? null),
			'oilTankSizeClassLabel' => self::codeLabel('Stoerrelsesklasse', $node['tek026StoerrelsesklasseOlietank'] ?? null),
			'placementCode' => self::nullableString($node['tek027Placering'] ?? null),
			'placementLabel' => self::codeLabel('Placering', $node['tek027Placering'] ?? null),
			'oilTankDecommissionCode' => self::nullableString($node['tek028SloejfningOlietank'] ?? null),
			'oilTankDecommissionLabel' => self::codeLabel('Sloejfning', $node['tek028SloejfningOlietank'] ?? null),
			'serialNumber' => self::nullableString($node['tek030Fabrikationsnummer'] ?? null),
			'typeApprovalNumber' => self::nullableString($node['tek031Typegodkendelsesnummer'] ?? null),
			'size' => self::nullableInt($node['tek032Stoerrelse'] ?? null),
			'typeCode' => self::nullableString($node['tek033Type'] ?? null),
			'typeLabel' => self::codeLabel('TypeAfVaegge', $node['tek033Type'] ?? null),
			'oilTankContentCode' => self::nullableString($node['tek034IndholdOlietank'] ?? null),
			'oilTankContentLabel' => self::codeLabel('Indhold', $node['tek034IndholdOlietank'] ?? null),
			'oilTankDecommissionDeadline' => self::nullableString($node['tek035SloejfningsfristOlietank'] ?? null),
			'volume' => self::nullableInt($node['tek036Rumfang'] ?? null),
			'productionYear' => self::nullableInt($node['tek067Fabrikationsaar'] ?? null),
			'materialCode' => self::nullableString($node['tek068Materiale'] ?? null),
			'materialLabel' => self::codeLabel('Materiale', $node['tek068Materiale'] ?? null),
			'decommissionYear' => self::nullableInt($node['tek072Sloejfningsaar'] ?? null),
			'tankCovering' => self::nullableString($node['tek105OverdaekningTank'] ?? null),
			'tankInspectionDate' => self::nullableString($node['tek106InspektionsdatoTank'] ?? null),
			'operatingStatusCode' => self::nullableString($node['tek110Driftstatus'] ?? null),
			'operatingStatusLabel' => self::codeLabel('Driftstatus', $node['tek110Driftstatus'] ?? null),
			'lastInspectionDate' => self::nullableString($node['tek111DatoForSenesteInspektion'] ?? null),
			'coordinate' => \is_array($coordinate)
				? [
					'crs' => self::nullableInt($coordinate['crs'] ?? null),
					'wkt' => self::nullableString($coordinate['wkt'] ?? null),
				]
				: null,
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Return entity IDs having one normalized property scope.
	 *
	 * @param array<string,array<string,mixed>> $nodes Normalized entity map.
	 * @return list<string> Matching entity IDs.
	 */
	private function scopeIds(array $nodes, string $scope): array {
		$ids = [];

		foreach ($nodes as $id => $node) {
			if (($node['scope'] ?? null) === $scope) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Merge nodes into a map keyed by id_lokalId.
	 */
	private function mergeById(array &$target, array $nodes): void {
		foreach ($nodes as $key => $node) {
			if (\is_string($key) && self::isUuid($key)) {
				$target[$key] = $node;
				continue;
			}

			if (!\is_array($node)) {
				continue;
			}

			$id = self::requiredUuid($node['id_lokalId'] ?? null, 'Datafordeler node id');
			$target[$id] = $node;
		}
	}

	/**
	 * Merge technical history without collapsing temporal versions.
	 */
	private function mergeHistory(array &$target, array $nodes): void {
		foreach ($nodes as $node) {
			$id = self::requiredUuid($node['id_lokalId'] ?? null, 'BBR technical-installation id');
			$key = $id
				. '|' . (string)($node['virkningFra'] ?? '')
				. '|' . (string)($node['virkningTil'] ?? '')
				. '|' . (string)($node['registreringFra'] ?? '');

			$target[$key] = $node;
		}
	}

	/**
	 * Fetch street/postcode relation rows.
	 *
	 * @param list<string>|null $streetIds Optional exact-street filter.
	 * @return list<array<string,mixed>>
	 */
	private function fetchStreetPostcodeRelations(
		string $postcodeId,
		?array $streetIds,
		string $effectiveAt
	): array {
		$result = [];

		if ($streetIds === null) {
			return $this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'DAR_NavngivenVejPostnummer',
				fn(?string $after): string => $this->buildStreetPostcodeRelationsQuery(
					$postcodeId,
					null,
					$effectiveAt,
					$after
				)
			);
		}

		foreach (\array_chunk($streetIds, self::IDENTIFIER_BATCH_SIZE) as $batch) {
			$rows = $this->fetchAll(
				$this->currentService,
				$this->currentVersion,
				'DAR_NavngivenVejPostnummer',
				fn(?string $after): string => $this->buildStreetPostcodeRelationsQuery(
					$postcodeId,
					$batch,
					$effectiveAt,
					$after
				)
			);
			$result = \array_merge($result, $rows);
		}

		return $result;
	}

	/**
	 * Fetch DAR streets by UUID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function fetchStreetsByIds(array $ids, string $effectiveAt): array {
		return $this->fetchByIdentifierBatches(
			'DAR_NavngivenVej',
			'id_lokalId',
			$ids,
			$effectiveAt,
			fn(string $field, array $batch, ?string $after): string => $this->buildStreetsByIdsQuery(
				$batch,
				$effectiveAt,
				$after
			)
		);
	}

	/**
	 * Return a map keyed by id_lokalId.
	 *
	 * @param list<array<string,mixed>> $nodes Nodes.
	 * @return array<string,array<string,mixed>> Node map.
	 */
	private static function keyById(array $nodes): array {
		$result = [];

		foreach ($nodes as $node) {
			$id = self::requiredUuid($node['id_lokalId'] ?? null, 'Datafordeler node id');
			$result[$id] = $node;
		}

		return $result;
	}

	/**
	 * Remove duplicate nodes by id_lokalId.
	 *
	 * @param list<array<string,mixed>> $nodes Nodes.
	 * @return list<array<string,mixed>> Unique nodes.
	 */
	private static function uniqueById(array $nodes): array {
		return \array_values(self::keyById($nodes));
	}

	/**
	 * Add one UUID to a set when valid.
	 *
	 * @param array<string,bool> $set UUID set.
	 */
	private static function addUuid(array &$set, mixed $value): void {
		if (\is_string($value) && self::isUuid($value)) {
			$set[$value] = true;
		}
	}

	/**
	 * Resolve an optional BBR code through the bundled code-list snapshot.
	 *
	 * @param string $list Official BBR code-list name.
	 * @param mixed $value Raw upstream code value.
	 * @return string|null Snapshot label, or null when the value or mapping is unavailable.
	 */
	private static function codeLabel(string $list, mixed $value): ?string {
		$code = self::nullableString($value);

		return BbrCodeLists::label($list, $code);
	}

	/**
	 * Normalize a BFE number.
	 */
	private static function normalizeBfeNumber(int|string $value): int {
		$string = \is_int($value) ? (string)$value : \trim($value);

		if (!\preg_match('/^[1-9][0-9]*$/D', $string)) {
			throw new \InvalidArgumentException('BFE number must be a positive integer without leading zeroes.');
		}

		$number = \filter_var($string, \FILTER_VALIDATE_INT);
		if ($number === false || $number < 1) {
			throw new \InvalidArgumentException('BFE number is outside the supported integer range.');
		}

		return $number;
	}

	/**
	 * Map one BBR property type code to a stable package key.
	 */
	private static function propertyKind(string $typeCode): string {
		return match ($typeCode) {
			'1' => 'sfe',
			'2' => 'bpfg',
			'3' => 'ejerlejlighed',
			default => throw new InvalidResponseException('BBR returned an unknown property type code.'),
		};
	}

	/**
	 * Normalize a scalar to a nullable string.
	 */
	private static function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value) && !\is_int($value)) {
			throw new InvalidResponseException('Datafordeler response contains an invalid scalar string value.');
		}

		$value = (string)$value;
		return $value !== '' ? $value : null;
	}

	/**
	 * Require one non-empty string.
	 */
	private static function requiredString(mixed $value, string $label): string {
		$value = self::nullableString($value);
		if ($value === null) {
			throw new InvalidResponseException($label . ' is missing or empty.');
		}

		return $value;
	}

	/**
	 * Normalize a scalar to a nullable integer.
	 */
	private static function nullableInt(mixed $value): ?int {
		if ($value === null) {
			return null;
		}
		if (\is_int($value)) {
			return $value;
		}
		if (\is_string($value) && \preg_match('/^-?[0-9]+$/D', $value)) {
			$int = \filter_var($value, \FILTER_VALIDATE_INT);
			if ($int !== false) {
				return $int;
			}
		}

		throw new InvalidResponseException('Datafordeler response contains an invalid integer value.');
	}

	/**
	 * Require one UUID.
	 */
	private static function requiredUuid(mixed $value, string $label): string {
		$value = self::nullableString($value);
		if ($value === null || !self::isUuid($value)) {
			throw new InvalidResponseException($label . ' is missing or invalid.');
		}

		return $value;
	}

	/**
	 * Test UUID format.
	 */
	private static function isUuid(string $value): bool {
		return (bool)\preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
			$value
		);
	}

	/**
	 * Return one UTC GraphQL point-in-time value.
	 */
	private static function now(): string {
		return \gmdate('Y-m-d\TH:i:s\Z');
	}

	/**
	 * Normalize whitespace.
	 */
	private static function normalizeSpaces(string $value): string {
		return \trim((string)\preg_replace('/\s+/u', ' ', \trim($value)));
	}

	/**
	 * Normalize one floor/door token.
	 */
	private static function normalizeUnitPart(string $value): ?string {
		$value = \trim($value);
		if ($value === '') {
			return null;
		}

		$value = \strtr($value, [
			'Æ' => 'ae',
			'Ø' => 'oe',
			'Å' => 'aa',
			'æ' => 'ae',
			'ø' => 'oe',
			'å' => 'aa',
		]);
		$value = \strtolower($value);
		$value = (string)\preg_replace('/[.\s]+/', '', $value);

		return $value !== '' ? $value : null;
	}

	/**
	 * Normalize text for deterministic edit-distance comparison.
	 */
	private static function normalizeForDistance(string $value): string {
		$value = \strtr($value, [
			'Æ' => 'ae',
			'Ø' => 'oe',
			'Å' => 'aa',
			'æ' => 'ae',
			'ø' => 'oe',
			'å' => 'aa',
		]);
		$value = \strtolower($value);

		if (\function_exists('iconv')) {
			$ascii = @\iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
			if (\is_string($ascii) && $ascii !== '') {
				$value = $ascii;
			}
		}

		return (string)\preg_replace('/[^a-z0-9]+/', '', $value);
	}

	/**
	 * Build a GraphQL JSON string literal.
	 */
	private static function gqlString(string $value): string {
		try {
			return \json_encode(
				$value,
				\JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
			);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('GraphQL string value cannot be JSON encoded.');
		}
	}

	/**
	 * Build a GraphQL string-list literal.
	 *
	 * @param list<string> $values Values.
	 */
	private static function gqlStringList(array $values): string {
		return '[' . \implode(
			',',
			\array_map(static fn(string $value): string => self::gqlString($value), $values)
		) . ']';
	}

	/**
	 * Build optional cursor argument.
	 */
	private static function afterArgument(?string $after): string {
		return $after !== null
			? "\n\t\tafter: " . self::gqlString($after)
			: '';
	}

	/**
	 * Common pageInfo selection.
	 */
	private static function pageInfoSelection(): string {
		return <<<'GRAPHQL'
		pageInfo {
			hasNextPage
			endCursor
		}
GRAPHQL;
	}

	// ----------------------------------------------------------------
	// GraphQL current-state query builders
	// ----------------------------------------------------------------

	private function buildPostcodeQuery(string $postcode, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$postcode = self::gqlString($postcode);

		return <<<GRAPHQL
query BbrResolvePostcode {
	DAR_Postnummer(
		first: 10{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { postnr: { eq: {$postcode} } }
	) {
		nodes {
			id_lokalId
			postnr
			navn
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildExactStreetsQuery(string $street, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$street = self::gqlString($street);

		return <<<GRAPHQL
query BbrResolveExactStreet {
	DAR_NavngivenVej(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { vejnavn: { eq: {$street} } }
	) {
		nodes {
			id_lokalId
			vejnavn
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildStreetPostcodeRelationsQuery(
		string $postcodeId,
		?array $streetIds,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$where = $streetIds === null
			? '{ postnummer: { eq: ' . self::gqlString($postcodeId) . ' } }'
			: '{ and: ['
				. '{ postnummer: { eq: ' . self::gqlString($postcodeId) . ' } }'
				. '{ navngivenVej: { in: ' . self::gqlStringList($streetIds) . ' } }'
				. '] }';

		return <<<GRAPHQL
query BbrResolveStreetPostcode {
	DAR_NavngivenVejPostnummer(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: {$where}
	) {
		nodes {
			id_lokalId
			navngivenVej
			postnummer
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildStreetsByIdsQuery(array $ids, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrResolveStreetsById {
	DAR_NavngivenVej(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			vejnavn
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildHouseNumberQuery(
		string $streetId,
		string $postcodeId,
		string $houseNumber,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$streetId = self::gqlString($streetId);
		$postcodeId = self::gqlString($postcodeId);
		$houseNumber = self::gqlString($houseNumber);

		return <<<GRAPHQL
query BbrResolveHouseNumber {
	DAR_Husnummer(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: {
			and: [
				{ navngivenVej: { eq: {$streetId} } }
				{ postnummer: { eq: {$postcodeId} } }
				{ husnummertekst: { eq: {$houseNumber} } }
			]
		}
	) {
		nodes {
			id_lokalId
			adgangsadressebetegnelse
			husnummertekst
			jordstykke
			adgangTilBygning
			adgangTilTekniskAnlaeg
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildAddressesByHouseNumberQuery(
		string $houseNumberId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($houseNumberId);

		return <<<GRAPHQL
query BbrResolveAddresses {
	DAR_Adresse(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { husnummer: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			adressebetegnelse
			husnummer
			etagebetegnelse
			doerbetegnelse
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildPropertyByBfeQuery(int $bfe, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);

		return <<<GRAPHQL
query BbrPropertyByBfe {
	BBR_Ejendomsrelation(
		first: 10{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { bfeNummer: { eq: {$bfe} } }
	) {
		nodes {
			id_lokalId
			status
			bfeNummer
			ejendomstype
			ejendomsnummer
			ejerlejlighedsnummer
			ejendommensEjerforholdskode
			samletFastEjendom
			bygningPaaFremmedGrund
			ejerlejlighed
			tinglystAreal
			registreringFra
			registreringTil
			virkningFra
			virkningTil
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildPropertiesByIdsQuery(array $ids, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrPropertiesById {
	BBR_Ejendomsrelation(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			status
			bfeNummer
			ejendomstype
			ejendomsnummer
			ejerlejlighedsnummer
			ejendommensEjerforholdskode
			samletFastEjendom
			bygningPaaFremmedGrund
			ejerlejlighed
			tinglystAreal
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildGroundsByPropertyQuery(
		string $propertyId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrGroundsByProperty {
	BBR_Grund(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { bestemtFastEjendom: { eq: {$id} } }
	) {
		nodes {
			{$this->groundFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildGroundsByIdsQuery(array $ids, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrGroundsById {
	BBR_Grund(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$list} } }
	) {
		nodes {
			{$this->groundFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBuildingsByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['id_lokalId', 'grund']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrBuildingsByIds {
	BBR_Bygning(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->buildingFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBuildingsByOwnerApartmentQuery(
		string $propertyId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrBuildingsByOwnerApartment {
	BBR_Bygning(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { ejerlejlighed: { eq: {$id} } }
	) {
		nodes {
			{$this->buildingFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBuildingsByHouseNumberQuery(
		string $houseNumberId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($houseNumberId);

		return <<<GRAPHQL
query BbrBuildingsByHouseNumber {
	BBR_Bygning(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { husnummer: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			grund
			ejerlejlighed
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBuildingRelationsByIdsQuery(
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrBuildingRelationsByBuilding {
	BBR_BygningEjendomsrelation(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { bygning: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			bygning
			bygningPaaFremmedGrund
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBuildingRelationsByPropertyQuery(
		string $propertyId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrBuildingRelationsByProperty {
	BBR_BygningEjendomsrelation(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { bygningPaaFremmedGrund: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			bygning
			bygningPaaFremmedGrund
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildUnitsByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['id_lokalId', 'bygning']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrUnitsByIds {
	BBR_Enhed(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->unitFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildUnitsByAddressQuery(
		string $addressId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($addressId);

		return <<<GRAPHQL
query BbrUnitsByAddress {
	BBR_Enhed(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { adresseIdentificerer: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			adresseIdentificerer
			bygning
			etage
			opgang
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildUnitRelationsByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['enhed']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrUnitRelationsByUnit {
	BBR_EnhedEjendomsrelation(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { enhed: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			enhed
			ejerlejlighed
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildUnitRelationsByPropertyQuery(
		string $propertyId,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrUnitRelationsByProperty {
	BBR_EnhedEjendomsrelation(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { ejerlejlighed: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			enhed
			ejerlejlighed
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildFloorsByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['id_lokalId', 'bygning']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrFloorsByIds {
	BBR_Etage(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->floorFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildEntrancesByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['id_lokalId', 'bygning']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrEntrancesByIds {
	BBR_Opgang(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->entranceFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildTechnicalByIdsQuery(
		string $field,
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['grund', 'bygning', 'enhed']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrTechnicalByIds {
	BBR_TekniskAnlaeg(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->technicalFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildTechnicalByPropertyQuery(
		string $field,
		string $propertyId,
		string $effectiveAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['bygningPaaFremmedGrund', 'ejerlejlighed']);
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrTechnicalByProperty {
	BBR_TekniskAnlaeg(
		first: 1000{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { {$field}: { eq: {$id} } }
	) {
		nodes {
			{$this->technicalFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildHouseNumbersByIdsQuery(
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrHouseNumbersById {
	DAR_Husnummer(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			adgangsadressebetegnelse
			husnummertekst
			jordstykke
			adgangTilBygning
			adgangTilTekniskAnlaeg
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildAddressesByIdsQuery(
		array $ids,
		string $effectiveAt,
		?string $after
	): string {
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrAddressesById {
	DAR_Adresse(
		first: 100{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$list} } }
	) {
		nodes {
			id_lokalId
			adressebetegnelse
			husnummer
			etagebetegnelse
			doerbetegnelse
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	// ----------------------------------------------------------------
	// GraphQL history query builders
	// ----------------------------------------------------------------

	private function buildTechnicalHistoryByIdsQuery(
		string $field,
		array $ids,
		string $registeredAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['grund', 'bygning', 'enhed']);
		$afterArg = self::afterArgument($after);
		$list = self::gqlStringList($ids);

		return <<<GRAPHQL
query BbrTechnicalHistoryByIds {
	BBR_TekniskAnlaeg(
		first: 1000{$afterArg}
		registreringstid: "{$registeredAt}"
		where: { {$field}: { in: {$list} } }
	) {
		nodes {
			{$this->technicalFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildTechnicalHistoryByPropertyQuery(
		string $field,
		string $propertyId,
		string $registeredAt,
		?string $after
	): string {
		self::assertIdentifierField($field, ['bygningPaaFremmedGrund', 'ejerlejlighed']);
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($propertyId);

		return <<<GRAPHQL
query BbrTechnicalHistoryByProperty {
	BBR_TekniskAnlaeg(
		first: 1000{$afterArg}
		registreringstid: "{$registeredAt}"
		where: { {$field}: { eq: {$id} } }
	) {
		nodes {
			{$this->technicalFields()}
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	/**
	 * Validate a dynamically inserted GraphQL identifier field.
	 *
	 * @param list<string> $allowed Allowed field names.
	 */
	private static function assertIdentifierField(string $field, array $allowed): void {
		if (!\in_array($field, $allowed, true)) {
			throw new \LogicException('Unsupported internal GraphQL identifier field.');
		}
	}

	// ----------------------------------------------------------------
	// GraphQL field selections
	// ----------------------------------------------------------------

	private function pageInfo(): string {
		return self::pageInfoSelection();
	}

	private function groundFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
bestemtFastEjendom
husnummer
kommunekode
gru009Vandforsyning
gru010Afloebsforhold
gru021Udledningstilladelse
gru022MedlemskabAfSpildevandsforsyning
gru023PaabudVedrSpildevandsafledning
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}

	private function buildingFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
husnummer
grund
jordstykke
ejerlejlighed
byg007Bygningsnummer
byg021BygningensAnvendelse
byg024AntalLejlighederMedKoekken
byg025AntalLejlighederUdenKoekken
byg026Opfoerelsesaar
byg027OmTilbygningsaar
byg030Vandforsyning
byg031Afloebsforhold
byg032YdervaeggensMateriale
byg033Tagdaekningsmateriale
byg034SupplerendeYdervaeggensMateriale
byg035SupplerendeTagdaekningsMateriale
byg036AsbestholdigtMateriale
byg037KildeTilBygningensMaterialer
byg038SamletBygningsareal
byg039BygningensSamledeBoligAreal
byg040BygningensSamledeErhvervsAreal
byg041BebyggetAreal
byg042ArealIndbyggetGarage
byg043ArealIndbyggetCarport
byg044ArealIndbyggetUdhus
byg045ArealIndbyggetUdestueEllerLign
byg049ArealAfOverdaekketAreal
byg053BygningsarealerKilde
byg054AntalEtager
byg056Varmeinstallation
byg057Opvarmningsmiddel
byg058SupplerendeVarme
byg070Fredning
byg071BevaringsvaerdighedReference
byg500Notatlinjer
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}

	private function unitFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
adresseIdentificerer
bygning
etage
opgang
enh020EnhedensAnvendelse
enh023Boligtype
enh026EnhedensSamledeAreal
enh027ArealTilBeboelse
enh028ArealTilErhverv
enh031AntalVaerelser
enh032Toiletforhold
enh033Badeforhold
enh034Koekkenforhold
enh035Energiforsyning
enh045Udlejningsforhold
enh051Varmeinstallation
enh052Opvarmningsmiddel
enh053SupplerendeVarme
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}

	private function floorFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
bygning
eta006BygningensEtagebetegnelse
eta020SamletArealAfEtage
eta021ArealAfUdnyttetDelAfTagetage
eta022Kaelderareal
eta023ArealAfLovligBeboelseIKaelder
eta025Etagetype
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}

	private function entranceFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
bygning
adgangFraHusnummer
opg020Elevator
opg021HusnummerFunktion
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}

	private function technicalFields(): string {
		return <<<'GRAPHQL'
id_lokalId
status
husnummer
bygning
grund
enhed
jordstykke
bygningPaaFremmedGrund
ejerlejlighed
tek007Anlaegsnummer
tek020Klassifikation
tek021FabrikatType
tek024Etableringsaar
tek025TilOmbygningsaar
tek026StoerrelsesklasseOlietank
tek027Placering
tek028SloejfningOlietank
tek030Fabrikationsnummer
tek031Typegodkendelsesnummer
tek032Stoerrelse
tek033Type
tek034IndholdOlietank
tek035SloejfningsfristOlietank
tek036Rumfang
tek067Fabrikationsaar
tek068Materiale
tek072Sloejfningsaar
tek105OverdaekningTank
tek106InspektionsdatoTank
tek109Koordinat {
	crs
	wkt
}
tek110Driftstatus
tek111DatoForSenesteInspektion
registreringFra
registreringTil
virkningFra
virkningTil
GRAPHQL;
	}
}
