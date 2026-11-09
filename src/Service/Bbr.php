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
use CitOmni\DanishPublicSectorData\Util\BbrFieldCatalog;
use CitOmni\Kernel\Service\BaseService;

/**
 * Bbr: Service that resolves Danish property addresses and reads current BBR property data.
 *
 * Gives host apps the BFE number behind a typed address and the property behind a BFE number, as
 * package-owned arrays built from BBR and DAR through Datafordeler, without DAWA. It covers samlet
 * fast ejendom (SFE), buildings on foreign ground (BPFG) and owner apartments. Authentication and
 * transport belong to DatafordelerClient, the labels of BBR codes to BbrCodeLists, and the BBR
 * fields without a key of their own to BbrFieldCatalog.
 *
 * Rules:
 * - Never choose between BFE candidates when the evidence is not decisive: Report the ambiguity.
 * - Select every BBR v3 field of an entity apart from Datafordeler's internal ones, and return
 *   each domain field under a key of its own or through BbrFieldCatalog, so no value is lost.
 * - Return BBR codes as they are; a label only adds to its code.
 * - Give every physical object the scope it has for the requested property, so an object that
 *   belongs to another property, such as the building that holds an owner apartment, is not
 *   presented as part of it.
 *
 * @phpstan-type BbrGraph array{
 *     grounds:array<string,array<string,mixed>>,
 *     buildings:array<string,array<string,mixed>>,
 *     units:array<string,array<string,mixed>>,
 *     floors:array<string,array<string,mixed>>,
 *     entrances:array<string,array<string,mixed>>,
 *     technicalInstallations:array<string,array<string,mixed>>
 * }
 */
class Bbr extends BaseService {

	/** Largest page that Datafordeler serves. */
	private const int PAGE_SIZE = 1000;

	/** Maximum number of ids in one in filter. */
	private const int IDENTIFIER_BATCH_SIZE = 100;

	/** Datafordeler client that authenticates with the API key. */
	private DatafordelerClient $datafordeler;

	/** Datafordeler service for current BBR and DAR data. */
	private string $currentService;

	/** Version of the current-data service. */
	private string $currentVersion;

	/** Datafordeler service for BBR history. */
	private string $historyService;

	/** Version of the history service. */
	private string $historyVersion;

	/**
	 * Read the BBR endpoints from cfg and create the Datafordeler client.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When a service or version in cfg danish_public_sector_data.bbr is empty, or the Datafordeler cfg is invalid.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->bbr;

		$this->currentService = (string)$cfg->current_service;
		$this->currentVersion = (string)$cfg->current_version;
		$this->historyService = (string)$cfg->history_service;
		$this->historyVersion = (string)$cfg->history_version;

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
	 * Resolve one typed Danish property address to BFE candidates.
	 *
	 * Reads the postcode, the street, the house number and the DAR addresses of the house number, and
	 * collects the properties that BBR relates to them. Meant for addresses as auction lists write
	 * them, with an optional floor and door and an optional trailing m.fl.
	 *
	 * Behavior:
	 * - selectedBfeNumber is set only when the evidence is decisive: One candidate in total, or one
	 *   owner apartment whose unit has the selected DAR address, which is the address of the typed
	 *   floor and door, or the only address of the house number. Otherwise it is null.
	 * - bfeCandidates lists every candidate, ordered by BFE number. evidence names how a candidate
	 *   was found: ground_sfe, building_bpfg, building_ejerlejlighed, unit_ejerlejlighed or
	 *   selected_unit_ejerlejlighed.
	 * - status tells how far the resolution got: unparseable, postcode_not_unique,
	 *   street_ambiguous_or_not_found, house_number_not_unique, ambiguous_unit, no_bfe,
	 *   ambiguous_bfe, primary_resolved_scope_incomplete or resolved.
	 * - A trailing m.fl. or mfl. is removed from lookupInput and sets multiplePropertiesHint. With a
	 *   selected BFE number, status is then primary_resolved_scope_incomplete: The other properties
	 *   of the auction are not resolved, and their BFE numbers are never guessed.
	 * - The street matches exactly within the postcode. Without an exact match, a misspelt name
	 *   resolves only when one street is clearly the closest, with status fuzzy_unique in
	 *   streetMatch.
	 * - warnings lists what limits the result: address_unparseable, postcode_not_unique,
	 *   street_ambiguous_or_not_found, house_number_not_unique, multiple_properties_scope_incomplete,
	 *   street_fuzzy_match, multiple_addresses_without_unit or multiple_bfe_candidates.
	 *
	 * Typical usage:
	 *   $resolution = $this->app->bbr->resolveAddress('Eksempelvej 29, 2 22, 7600 Struer');
	 *
	 * @param  string  $address  Typed address; surrounding whitespace is ignored.
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
	 * }
	 * @throws \InvalidArgumentException  When the address is empty.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
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
	 * Return the current BBR data of one property by BFE number.
	 *
	 * Behavior:
	 * - propertyType is sfe, bpfg or ejerlejlighed.
	 * - The lists hold the objects of the property and the objects it sits in or on, such as the
	 *   ground under a building on foreign ground or the building that holds an owner apartment.
	 *   scope is property for an object of the property itself, and context for the others.
	 * - Technical installations whose status is historic or registered in error (statusCode 10 or 11)
	 *   are left out; getTechnicalInstallationHistory() has them.
	 * - Buildings are ordered by building number, technical installations by installation number,
	 *   each then by id.
	 * - Every code comes with its label from BbrCodeLists, null for an unknown code.
	 *   codeListSnapshotDate and codeListSources name the dates of the code lists.
	 *
	 * Typical usage:
	 *   $property = $this->app->bbr->getPropertyByBfe('4268969');
	 *
	 * @param  int|string  $bfeNumber  BFE number: A positive integer without leading zeroes.
	 * @return array{
	 *     bfeNumber:int,
	 *     propertyRelationId:string,
	 *     propertyType:string,
	 *     codeListSnapshotDate:string,
	 *     codeListSources:array{primarySnapshotDate:string,verifiedSourceCheckDate:string,supplementCoverageDate:string},
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
	 * }|null  Null when BBR has no current property with the number.
	 * @throws \InvalidArgumentException  When the BFE number is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed, or BBR has several current properties with the number.
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
		$buildings = \array_values($graph['buildings']);
		$technicalInstallations = \array_values(
			\array_filter(
				$graph['technicalInstallations'],
				static function(array $installation): bool {
					$statusCode = $installation['statusCode'] ?? null;

					// Historical and error-registered lifecycle rows belong in history, not the current snapshot.
					return $statusCode !== '10' && $statusCode !== '11';
				}
			)
		);

		\usort(
			$buildings,
			static fn(array $a, array $b): int =>
				($a['buildingNumber'] ?? \PHP_INT_MAX) <=> ($b['buildingNumber'] ?? \PHP_INT_MAX)
				?: (($a['id'] ?? '') <=> ($b['id'] ?? ''))
		);
		\usort(
			$technicalInstallations,
			static fn(array $a, array $b): int =>
				($a['installationNumber'] ?? \PHP_INT_MAX) <=> ($b['installationNumber'] ?? \PHP_INT_MAX)
				?: (($a['id'] ?? '') <=> ($b['id'] ?? ''))
		);

		return [
			'bfeNumber' => $bfe,
			'propertyRelationId' => $propertyId,
			'propertyType' => $kind,
			'codeListSnapshotDate' => BbrCodeLists::SNAPSHOT_DATE,
			'codeListSources' => [
				'primarySnapshotDate' => BbrCodeLists::SNAPSHOT_DATE,
				'verifiedSourceCheckDate' => BbrCodeLists::VERIFIED_SOURCE_CHECK_DATE,
				'supplementCoverageDate' => BbrCodeLists::SUPPLEMENT_COVERAGE_DATE,
			],
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
			'buildings' => $buildings,
			'units' => \array_values($graph['units']),
			'floors' => \array_values($graph['floors']),
			'entrances' => \array_values($graph['entrances']),
			'technicalInstallations' => $technicalInstallations,
		];
	}

	/**
	 * Return every registered version of the technical installations of one property by BFE number.
	 *
	 * For due diligence, such as former oil tanks, which the current data no longer shows once they
	 * are removed.
	 *
	 * Behavior:
	 * - Covers the technical installations of the property's own grounds, buildings and units, and
	 *   for a building on foreign ground or an owner apartment those registered on the property.
	 * - Returns every version that BBR has registered now, also those whose effect has ended. A
	 *   version that several lookups return is returned once.
	 * - Ordered by installation number, then validFrom, registeredFrom and id.
	 * - Empty when BBR has no current property with the number.
	 *
	 * Typical usage:
	 *   $history = $this->app->bbr->getTechnicalInstallationHistory('4268969');
	 *
	 * @param  int|string  $bfeNumber  BFE number: A positive integer without leading zeroes.
	 * @return list<array<string,mixed>>  Technical installations with scope property.
	 * @throws \InvalidArgumentException  When the BFE number is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed, or BBR has several current properties with the number.
	 * @see \CitOmni\DanishPublicSectorData\Service\Bbr::normalizeTechnicalInstallation()  The keys of a technical installation.
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
				($a['installationNumber'] ?? \PHP_INT_MAX) <=> ($b['installationNumber'] ?? \PHP_INT_MAX)
				?: (($a['validFrom'] ?? '') <=> ($b['validFrom'] ?? ''))
				?: (($a['registeredFrom'] ?? '') <=> ($b['registeredFrom'] ?? ''))
				?: ($a['id'] <=> $b['id'])
		);

		return \array_values($history);
	}

	/**
	 * Load the objects of a samlet fast ejendom (SFE).
	 *
	 * Behavior:
	 * - Holds the grounds of the property, their buildings, the units of those buildings, and the
	 *   floors, entrances and technical installations of the property's own buildings and units.
	 * - A building that belongs to a building on foreign ground or to an owner apartment, a unit of
	 *   an owner apartment, and a technical installation registered on either have scope context;
	 *   the other objects have scope property.
	 *
	 * @param  string  $propertyId  BBR property relation id of the SFE.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return BbrGraph  Normalized objects keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the objects of a building on foreign ground (BPFG).
	 *
	 * Behavior:
	 * - Holds the buildings of the property, their units, floors and entrances, the grounds they
	 *   stand on, and the technical installations of the property, of its buildings and of its own
	 *   units.
	 * - The grounds and the units of owner apartments have scope context; the other objects have
	 *   scope property.
	 *
	 * @param  string  $propertyId  BBR property relation id of the BPFG.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return BbrGraph  Normalized objects keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the objects of an owner apartment.
	 *
	 * Behavior:
	 * - Holds the units of the property, the buildings, floors and entrances of those units, the
	 *   buildings registered on the property, the grounds of all these buildings, and the technical
	 *   installations of the property and of its units.
	 * - The units, the buildings registered on the property and the technical installations have
	 *   scope property; the other objects have scope context.
	 *
	 * @param  string  $propertyId  BBR property relation id of the owner apartment.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return BbrGraph  Normalized objects keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Collect the properties that BBR relates to one DAR house number, and select one when the evidence is decisive.
	 *
	 * Behavior:
	 * - The candidates are the SFE of the grounds under the buildings of the house number, the
	 *   BPFG and owner apartments of those buildings, and the owner apartments of their units, or
	 *   of the units at the selected address when there is one.
	 * - evidence names how a candidate was found: ground_sfe, building_bpfg,
	 *   building_ejerlejlighed, unit_ejerlejlighed or selected_unit_ejerlejlighed.
	 * - selectedBfeNumber is the one candidate with selected_unit_ejerlejlighed, or else the only
	 *   candidate; null otherwise.
	 * - Candidates are ordered by BFE number.
	 *
	 * @param  string  $houseNumberId  DAR id of the house number.
	 * @param  array<string,mixed>|null  $selectedAddress  DAR address node that the typed address selects, or null.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array{
	 *     candidates:list<array{bfeNumber:int,propertyType:string,propertyRelationId:string,evidence:list<string>}>,
	 *     selectedBfeNumber:?int
	 * }
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed, or a candidate has no valid BFE number or an unknown property type.
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
	 * Find the DAR street of a typed street name within one postcode.
	 *
	 * Behavior:
	 * - A street of the postcode with exactly the typed name wins with status exact, when it is
	 *   the only one.
	 * - Otherwise every street of the postcode is a candidate for chooseStreet().
	 *
	 * @param  string  $input  Typed street name.
	 * @param  string  $postcodeId  DAR id of the postcode.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array{
	 *     status:string,
	 *     input:string,
	 *     matched:?string,
	 *     selected:array<string,mixed>|null,
	 *     candidates:list<array{name:string,distance:int,similarity:float}>
	 * }
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\Bbr::chooseStreet()  How a candidate is chosen.
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
	 * Choose the street that a typed name means among candidate streets.
	 *
	 * Behavior:
	 * - Names are compared by edit distance, without letter case, spaces and punctuation, and with
	 *   Danish letters spelt out.
	 * - status is exact for a name at distance 0, and fuzzy_unique when the closest name has a
	 *   similarity of at least 0.85 and leads the next by at least 0.08; selected is then that
	 *   street. Otherwise status is ambiguous, or not_found without candidates, and selected null.
	 * - candidates lists the five closest names, closest first.
	 *
	 * @param  string  $input  Typed street name.
	 * @param  list<array<string,mixed>>  $streets  DAR street nodes.
	 * @return array{
	 *     status:string,
	 *     input:string,
	 *     matched:?string,
	 *     selected:array<string,mixed>|null,
	 *     candidates:list<array{name:string,distance:int,similarity:float}>
	 * }
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a street name is neither a string nor an int.
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
	 * Split a typed address into street, house number, floor and door, postcode and city.
	 *
	 * Behavior:
	 * - A trailing m.fl. or mfl., in any letter case, is removed from lookupInput and sets
	 *   multiplePropertiesHint.
	 * - postcode is the first four-digit word that a city name follows; houseNumber is the number
	 *   after the street name, with an optional letter in upper case; text after a comma behind it is
	 *   the floor and door.
	 * - A part that cannot be read is null.
	 *
	 * @param  string  $rawInput  Typed address.
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
	 * Split a typed floor and door, such as 2 22 or st. tv.
	 *
	 * Behavior:
	 * - The first word is the floor and the rest the door, each normalized as normalizeUnitPart()
	 *   does.
	 *
	 * @param  string  $value  Floor and door as typed.
	 * @return array{raw:string,floor:?string,door:?string}  raw is the value as typed.
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
	 * Select the DAR address of a typed floor and door among the addresses of one house number.
	 *
	 * Behavior:
	 * - With a floor and door: unit_exact and the address when exactly one address has them, as
	 *   normalizeUnitPart() compares them; unit_not_unique and null otherwise.
	 * - Without: single_address and the address when the house number has one address;
	 *   multiple_addresses or no_address and null otherwise.
	 *
	 * @param  list<array<string,mixed>>  $addresses  DAR address nodes of the house number.
	 * @param  array{raw:string,floor:?string,door:?string}|null  $unit  Typed floor and door, or null.
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
	 * Return the final status of an address resolution.
	 *
	 * Behavior:
	 * - resolved, or primary_resolved_scope_incomplete with the multiple-properties hint, when a BFE
	 *   number is selected.
	 * - Otherwise ambiguous_unit when the floor and door did not select one address, no_bfe without
	 *   candidates, and ambiguous_bfe with several.
	 *
	 * @param  bool  $multiplePropertiesHint  Whether the typed address ended in m.fl.
	 * @param  array{status:string,selected:array<string,mixed>|null}  $addressSelection  Result of selectAddress().
	 * @param  array{candidates:list<array<string,mixed>>,selectedBfeNumber:?int}  $candidateData  Result of resolveBfeCandidates().
	 * @return string
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
	 * Build the result of resolveAddress().
	 *
	 * @param  string  $input  Typed address, trimmed.
	 * @param  array<string,mixed>  $parsed  Result of parseAuctionAddress().
	 * @param  string  $status  Final status.
	 * @param  array<string,mixed>|null  $streetMatch  Street match; its selected node is left out of the result.
	 * @param  string|null  $matchedAddress  Text of the selected DAR address.
	 * @param  list<array{bfeNumber:int,propertyType:string,propertyRelationId:string,evidence:list<string>}>  $candidates  BFE candidates.
	 * @param  int|null  $selectedBfeNumber  Selected BFE number.
	 * @param  list<string>  $warnings  Warnings.
	 * @return array<string,mixed>
	 * @see \CitOmni\DanishPublicSectorData\Service\Bbr::resolveAddress()  The shape of the result.
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
	 * Return the nodes of every page of one Datafordeler connection.
	 *
	 * @param  string  $service  Datafordeler service.
	 * @param  string  $version  Version of that service.
	 * @param  string  $root  Connection name.
	 * @param  callable(?string):string  $queryBuilder  Builder of the query document for one page; its argument is the cursor of the previous page, null for the first.
	 * @return list<array<string,mixed>>  Nodes in response order.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails, or a page is malformed or announces a next page without a cursor.
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
	 * Fetch the current nodes whose identifier field holds one of many ids, up to 100 ids per request.
	 *
	 * @param  string  $root  Connection name.
	 * @param  string  $field  Identifier field that the builder filters on.
	 * @param  list<string>  $ids  Ids to look for.
	 * @param  string  $effectiveAt  Unused: The builder carries the effect time.
	 * @param  callable(string,list<string>,?string):string  $builder  Builder of the query document for one page; its arguments are the field, at most 100 ids and the cursor of the previous page.
	 * @return array<string,array<string,mixed>>  Raw nodes keyed by id_lokalId.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails, or a page or a node id is malformed.
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
	 * Fetch current buildings by id.
	 *
	 * @param  list<string>  $ids  BBR ids of the buildings.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Bygning nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch current grounds by id.
	 *
	 * @param  list<string>  $ids  BBR ids of the grounds.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Grund nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch current units by id.
	 *
	 * @param  list<string>  $ids  BBR ids of the units.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Enhed nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch the current property relations of buildings.
	 *
	 * @param  list<string>  $ids  BBR ids of the buildings.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_BygningEjendomsrelation nodes keyed by relation id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch the current property relations of units.
	 *
	 * @param  list<string>  $ids  BBR ids of the units.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_EnhedEjendomsrelation nodes keyed by relation id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch current property relations by id.
	 *
	 * @param  list<string>  $ids  BBR property relation ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Ejendomsrelation nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the current floors of buildings, with scope property.
	 *
	 * @param  list<string>  $buildingIds  BBR ids of the buildings.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Normalized floors keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the current entrances of buildings, with scope property.
	 *
	 * @param  list<string>  $buildingIds  BBR ids of the buildings.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Normalized entrances keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch current floors by id.
	 *
	 * @param  list<string>  $ids  BBR ids of the floors.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Etage nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch current entrances by id.
	 *
	 * @param  list<string>  $ids  BBR ids of the entrances.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_Opgang nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch the current technical installations of grounds, buildings or units.
	 *
	 * @param  string  $field  grund, bygning or enhed.
	 * @param  list<string>  $ids  BBR ids of the objects.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw BBR_TekniskAnlaeg nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the DAR house numbers that the grounds, buildings, entrances and technical installations of a graph refer to.
	 *
	 * @param  BbrGraph  $graph  Normalized objects of a property.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return list<array{id:string,formatted:?string,houseNumber:?string,landParcelId:?string,buildingId:?string,technicalInstallationId:?string}>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Load the DAR addresses of units.
	 *
	 * @param  array<string,array<string,mixed>>  $units  Normalized units.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return list<array{id:string,formatted:?string,houseNumberId:?string,floor:?string,door:?string}>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Normalize the BBR provenance of one node.
	 *
	 * @param  array<string,mixed>  $node  Raw GraphQL node.
	 * @return array<string,string>  The business event, area and process, each with its label, and the registration and effect actors; only those that hold a value.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a value is neither null, a string nor an int.
	 */
	private static function normalizeSourceMetadata(array $node): array {
		$metadata = [
			'businessEventCode' => self::nullableString($node['forretningshaendelse'] ?? null),
			'businessEventLabel' => self::codeLabel('ForretningsHaendelse', $node['forretningshaendelse'] ?? null),
			'businessAreaCode' => self::nullableString($node['forretningsomraade'] ?? null),
			'businessAreaLabel' => self::codeLabel('ForretningsOmraade', $node['forretningsomraade'] ?? null),
			'businessProcessCode' => self::nullableString($node['forretningsproces'] ?? null),
			'businessProcessLabel' => self::codeLabel('ForretningsProcess', $node['forretningsproces'] ?? null),
			'registrationActor' => self::nullableString($node['registreringsaktoer'] ?? null),
			'effectActor' => self::nullableString($node['virkningsaktoer'] ?? null),
		];

		return \array_filter(
			$metadata,
			static fn(?string $value): bool => $value !== null && $value !== ''
		);
	}

	/**
	 * Normalize one BBR_Grund node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
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
			'wastewaterOrderDeadline' => self::nullableString($node['gru024FristVedrSpildevandsafledning'] ?? null),
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('ground', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR_Bygning node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
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
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
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
			'dischargePermitCode' => self::nullableString($node['byg119Udledningstilladelse'] ?? null),
			'dischargePermitLabel' => self::codeLabel('Udledningstilladelse', $node['byg119Udledningstilladelse'] ?? null),
			'wastewaterMembershipCode' => self::nullableString($node['byg123MedlemskabAfSpildevandsforsyning'] ?? null),
			'wastewaterMembershipLabel' => self::codeLabel('MedlemsskabAfSplidevandforsyning', $node['byg123MedlemskabAfSpildevandsforsyning'] ?? null),
			'wastewaterOrderCode' => self::nullableString($node['byg124PaabudVedrSpildevandsafledning'] ?? null),
			'wastewaterOrderLabel' => self::codeLabel('Rensningspaabud', $node['byg124PaabudVedrSpildevandsafledning'] ?? null),
			'wastewaterOrderDeadline' => self::nullableString($node['byg125FristVedrSpildevandsafledning'] ?? null),
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
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('building', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR_Enhed node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
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
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
			'applicationCode' => self::nullableString($node['enh020EnhedensAnvendelse'] ?? null),
			'applicationLabel' => self::codeLabel('EnhAnvendelse', $node['enh020EnhedensAnvendelse'] ?? null),
			'housingTypeCode' => self::nullableString($node['enh023Boligtype'] ?? null),
			'housingTypeLabel' => self::codeLabel('Boligtype', $node['enh023Boligtype'] ?? null),
			'totalArea' => self::nullableInt($node['enh026EnhedensSamledeAreal'] ?? null),
			'residentialArea' => self::nullableInt($node['enh027ArealTilBeboelse'] ?? null),
			'businessArea' => self::nullableInt($node['enh028ArealTilErhverv'] ?? null),
			'roomCount' => self::nullableInt($node['enh031AntalVaerelser'] ?? null),
			'flushToiletCount' => self::nullableInt($node['enh065AntalVandskylledeToiletter'] ?? null),
			'bathroomCount' => self::nullableInt($node['enh066AntalBadevaerelser'] ?? null),
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
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('unit', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR_Etage node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
	 */
	private function normalizeFloor(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR floor id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
			'label' => self::nullableString($node['eta006BygningensEtagebetegnelse'] ?? null),
			'totalArea' => self::nullableInt($node['eta020SamletArealAfEtage'] ?? null),
			'usedAtticArea' => self::nullableInt($node['eta021ArealAfUdnyttetDelAfTagetage'] ?? null),
			'basementArea' => self::nullableInt($node['eta022Kaelderareal'] ?? null),
			'legalBasementResidentialArea' => self::nullableInt($node['eta023ArealAfLovligBeboelseIKaelder'] ?? null),
			'typeCode' => self::nullableString($node['eta025Etagetype'] ?? null),
			'typeLabel' => self::codeLabel('EtageType', $node['eta025Etagetype'] ?? null),
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('floor', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR_Opgang node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
	 */
	private function normalizeEntrance(array $node, string $scope): array {
		return [
			'id' => self::requiredUuid($node['id_lokalId'] ?? null, 'BBR entrance id'),
			'scope' => $scope,
			'statusCode' => self::nullableString($node['status'] ?? null),
			'statusLabel' => self::codeLabel('Livscyklus', $node['status'] ?? null),
			'buildingId' => self::nullableString($node['bygning'] ?? null),
			'houseNumberId' => self::nullableString($node['adgangFraHusnummer'] ?? null),
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
			'elevatorCode' => self::nullableString($node['opg020Elevator'] ?? null),
			'elevatorLabel' => self::codeLabel('Elevator', $node['opg020Elevator'] ?? null),
			'houseNumberFunctionCode' => self::nullableString($node['opg021HusnummerFunktion'] ?? null),
			'houseNumberFunctionLabel' => self::codeLabel('HusnummerRolle', $node['opg021HusnummerFunktion'] ?? null),
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('entrance', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Normalize one BBR_TekniskAnlaeg node.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $scope  property or context.
	 * @return array<string,mixed>  coordinate is crs and wkt, or null when the node has none.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no valid id or a value is invalid.
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
			'municipalityCode' => self::nullableString($node['kommunekode'] ?? null),
			'municipalityLabel' => self::codeLabel('Kommunekode', $node['kommunekode'] ?? null),
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
			'sourceMetadata' => self::normalizeSourceMetadata($node),
			'additionalFields' => BbrFieldCatalog::normalizeAdditionalFields('technicalInstallation', $node),
			'registeredFrom' => self::nullableString($node['registreringFra'] ?? null),
			'registeredTo' => self::nullableString($node['registreringTil'] ?? null),
			'validFrom' => self::nullableString($node['virkningFra'] ?? null),
			'validTo' => self::nullableString($node['virkningTil'] ?? null),
		];
	}

	/**
	 * Return the ids of the objects with one scope.
	 *
	 * @param  array<string,array<string,mixed>>  $nodes  Normalized objects keyed by id.
	 * @param  string  $scope  property or context.
	 * @return list<string>
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
	 *
	 * Behavior:
	 * - A key that is a UUID is taken as the id of its node; a listed node is keyed by its
	 *   id_lokalId, and a listed value that is not a node is skipped.
	 * - A node replaces one with the same id in the map.
	 *
	 * @param  array<string,array<string,mixed>>  $target  Map to merge into.
	 * @param  array<array-key,mixed>  $nodes  Nodes keyed by id, or listed.
	 * @return void
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a listed node has no valid id.
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
	 * Merge versions of technical installations, keeping each version once.
	 *
	 * Behavior:
	 * - Two nodes are the same version when id_lokalId, virkningFra, virkningTil and
	 *   registreringFra are equal; the later one replaces the earlier.
	 *
	 * @param  array<string,array<string,mixed>>  $target  Versions keyed by version.
	 * @param  list<array<string,mixed>>  $nodes  Raw BBR_TekniskAnlaeg nodes.
	 * @return void
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a node has no valid id.
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
	 * Fetch the street-postcode relations of one postcode.
	 *
	 * @param  string  $postcodeId  DAR id of the postcode.
	 * @param  list<string>|null  $streetIds  Only these streets, or null for every street of the postcode.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return list<array<string,mixed>>  Raw DAR_NavngivenVejPostnummer nodes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Fetch DAR streets by id.
	 *
	 * @param  list<string>  $ids  DAR ids of the streets.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw DAR_NavngivenVej nodes keyed by id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed.
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
	 * Return nodes keyed by their id_lokalId.
	 *
	 * @param  list<array<string,mixed>>  $nodes
	 * @return array<string,array<string,mixed>>  A later node replaces an earlier one with the same id.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a node has no valid id.
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
	 * Return one node per id_lokalId.
	 *
	 * @param  list<array<string,mixed>>  $nodes
	 * @return list<array<string,mixed>>  The last node of each id, in the order the ids first occur.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a node has no valid id.
	 */
	private static function uniqueById(array $nodes): array {
		return \array_values(self::keyById($nodes));
	}

	/**
	 * Add a value to a set when it is a UUID.
	 *
	 * @param  array<string,bool>  $set  UUIDs as keys.
	 * @param  mixed  $value
	 * @return void
	 */
	private static function addUuid(array &$set, mixed $value): void {
		if (\is_string($value) && self::isUuid($value)) {
			$set[$value] = true;
		}
	}

	/**
	 * Return the bundled label of an optional BBR code.
	 *
	 * @param  string  $list  Name of the BBR code list.
	 * @param  mixed  $value  Raw upstream code.
	 * @return string|null  Null for no code or an unknown one.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is neither null, a string nor an int.
	 * @see \CitOmni\DanishPublicSectorData\Util\BbrCodeLists::label()  Where the label comes from.
	 */
	private static function codeLabel(string $list, mixed $value): ?string {
		$code = self::nullableString($value);

		return BbrCodeLists::label($list, $code);
	}

	/**
	 * Normalize a BFE number.
	 *
	 * @param  int|string  $value  Surrounding whitespace of a string is ignored.
	 * @return int  At least 1.
	 * @throws \InvalidArgumentException  When the value is not a positive integer without leading zeroes, or exceeds the int range.
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
	 * Map a BBR property type code to the package's property type.
	 *
	 * Behavior:
	 * - 1 is sfe, 2 bpfg and 3 ejerlejlighed.
	 *
	 * @param  string  $typeCode  BBR ejendomstype.
	 * @return string
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  For any other code.
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
	 * Normalize an upstream string or int to text, or null.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @return string|null  Null for null and for an empty string.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is neither null, a string nor an int.
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
	 * Normalize a required upstream string or int to non-empty text.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Name of the value in exception messages.
	 * @return string
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing or empty, or neither a string nor an int.
	 */
	private static function requiredString(mixed $value, string $label): string {
		$value = self::nullableString($value);
		if ($value === null) {
			throw new InvalidResponseException($label . ' is missing or empty.');
		}

		return $value;
	}

	/**
	 * Normalize an upstream integer, or null.
	 *
	 * @param  mixed  $value  Raw upstream value: An int, or a string of an integer.
	 * @return int|null  Null for null.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not an integer in the int range.
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
	 * Normalize a required upstream UUID.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Name of the value in exception messages.
	 * @return string
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing or not a UUID.
	 */
	private static function requiredUuid(mixed $value, string $label): string {
		$value = self::nullableString($value);
		if ($value === null || !self::isUuid($value)) {
			throw new InvalidResponseException($label . ' is missing or invalid.');
		}

		return $value;
	}

	/**
	 * Tell whether a string is a UUID in its hexadecimal 8-4-4-4-12 form, in any letter case.
	 *
	 * @param  string  $value
	 * @return bool
	 */
	private static function isUuid(string $value): bool {
		return (bool)\preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di',
			$value
		);
	}

	/**
	 * Return the current time in UTC as a Datafordeler effect time.
	 *
	 * @return string  Such as 2026-01-31T12:00:00Z.
	 */
	private static function now(): string {
		return \gmdate('Y-m-d\TH:i:s\Z');
	}

	/**
	 * Collapse whitespace to single spaces and trim.
	 *
	 * @param  string  $value
	 * @return string
	 */
	private static function normalizeSpaces(string $value): string {
		return \trim((string)\preg_replace('/\s+/u', ' ', \trim($value)));
	}

	/**
	 * Normalize a floor or a door for comparison.
	 *
	 * Behavior:
	 * - Lower case, with Danish letters spelt out as ae, oe and aa, and without dots and whitespace.
	 *
	 * @param  string  $value  Floor or door as typed or as DAR writes it.
	 * @return string|null  Null when nothing is left.
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
	 * Normalize a street name for edit-distance comparison.
	 *
	 * Behavior:
	 * - Lower case, with Danish letters spelt out as ae, oe and aa, other letters transliterated to
	 *   ASCII where iconv can, and everything but letters and digits removed.
	 *
	 * @param  string  $value  Street name.
	 * @return string
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
	 * Encode a string as a GraphQL string literal.
	 *
	 * @param  string  $value
	 * @return string  A JSON string literal, which is also a GraphQL string literal.
	 * @throws \InvalidArgumentException  When the string cannot be encoded as JSON, such as invalid UTF-8.
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
	 * Encode a list of strings as a GraphQL list literal.
	 *
	 * @param  list<string>  $values
	 * @return string
	 * @throws \InvalidArgumentException  When a value cannot be encoded.
	 */
	private static function gqlStringList(array $values): string {
		return '[' . \implode(
			',',
			\array_map(static fn(string $value): string => self::gqlString($value), $values)
		) . ']';
	}

	/**
	 * Build the after argument of a page request.
	 *
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  Argument line with its leading line break, or an empty string for the first page.
	 * @throws \InvalidArgumentException  When the cursor cannot be encoded as a GraphQL string.
	 */
	private static function afterArgument(?string $after): string {
		return $after !== null
			? "\n\t\tafter: " . self::gqlString($after)
			: '';
	}

	/**
	 * Return the pageInfo selection of a connection.
	 *
	 * @return string
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

	/**
	 * Build the query document for one page of the DAR postcodes with one number.
	 *
	 * @param  string  $postcode  Postcode as typed, such as 7600.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the DAR streets with exactly one name.
	 *
	 * @param  string  $street  Street name as typed.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the street-postcode relations of one postcode.
	 *
	 * @param  string  $postcodeId  DAR id of the postcode.
	 * @param  list<string>|null  $streetIds  At most 100 DAR street ids to narrow to, or null for every street.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of DAR streets by id.
	 *
	 * @param  list<string>  $ids  At most 100 DAR street ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the DAR house numbers with one text on one street in one postcode.
	 *
	 * @param  string  $streetId  DAR id of the street.
	 * @param  string  $postcodeId  DAR id of the postcode.
	 * @param  string  $houseNumber  House number text, such as 27A.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the DAR addresses of one house number.
	 *
	 * @param  string  $houseNumberId  DAR id of the house number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the BBR property relations with one BFE number.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When the cursor cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of BBR property relations by id.
	 *
	 * @param  list<string>  $ids  At most 100 BBR property relation ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the grounds of one property.
	 *
	 * @param  string  $propertyId  BBR property relation id.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of grounds by id.
	 *
	 * @param  list<string>  $ids  At most 100 BBR ground ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of buildings by their id or the id of their ground.
	 *
	 * @param  string  $field  id_lokalId or grund.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the buildings registered on one owner apartment.
	 *
	 * @param  string  $propertyId  BBR property relation id of the owner apartment.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the buildings of one house number, with their ground and owner apartment only.
	 *
	 * @param  string  $houseNumberId  DAR id of the house number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the property relations of buildings.
	 *
	 * @param  list<string>  $ids  At most 100 BBR building ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the building relations of one building on foreign ground.
	 *
	 * @param  string  $propertyId  BBR property relation id of the building on foreign ground.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of units by their id or the id of their building.
	 *
	 * @param  string  $field  id_lokalId or bygning.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the units of one DAR address, with their placement only.
	 *
	 * @param  string  $addressId  DAR id of the address.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the property relations of units.
	 *
	 * @param  string  $field  enhed, the only field the query filters on.
	 * @param  list<string>  $ids  At most 100 BBR unit ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the unit relations of one owner apartment.
	 *
	 * @param  string  $propertyId  BBR property relation id of the owner apartment.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of floors by their id or the id of their building.
	 *
	 * @param  string  $field  id_lokalId or bygning.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of entrances by their id or the id of their building.
	 *
	 * @param  string  $field  id_lokalId or bygning.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the technical installations of grounds, buildings or units.
	 *
	 * @param  string  $field  grund, bygning or enhed.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the technical installations registered on one building on foreign ground or owner apartment.
	 *
	 * @param  string  $field  bygningPaaFremmedGrund or ejerlejlighed.
	 * @param  string  $propertyId  BBR property relation id.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of DAR house numbers by id.
	 *
	 * @param  list<string>  $ids  At most 100 DAR house-number ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of DAR addresses by id.
	 *
	 * @param  list<string>  $ids  At most 100 DAR address ids.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of every registered version of the technical installations of grounds, buildings or units.
	 *
	 * @param  string  $field  grund, bygning or enhed.
	 * @param  list<string>  $ids  At most 100 ids.
	 * @param  string  $registeredAt  UTC registration time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of every registered version of the technical installations registered on one building on foreign ground or owner apartment.
	 *
	 * @param  string  $field  bygningPaaFremmedGrund or ejerlejlighed.
	 * @param  string  $propertyId  BBR property relation id.
	 * @param  string  $registeredAt  UTC registration time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \LogicException  When the field is another one.
	 * @throws \InvalidArgumentException  When a value cannot be encoded as a GraphQL string.
	 */
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
	 * Require an identifier field that a query builder supports, before it becomes part of a query document.
	 *
	 * @param  string  $field
	 * @param  list<string>  $allowed  Fields the builder supports.
	 * @return void
	 * @throws \LogicException  When the field is not among them.
	 */
	private static function assertIdentifierField(string $field, array $allowed): void {
		if (!\in_array($field, $allowed, true)) {
			throw new \LogicException('Unsupported internal GraphQL identifier field.');
		}
	}

	// ----------------------------------------------------------------
	// GraphQL field selections
	// ----------------------------------------------------------------

	/**
	 * Return the pageInfo selection of a connection, for interpolation in a query document.
	 *
	 * @return string
	 */
	private function pageInfo(): string {
		return self::pageInfoSelection();
	}

	/**
	 * Return the field selection of every query of grounds.
	 *
	 * @return string  BBR_Grund field names, one per line.
	 */
	private function groundFields(): string {
		return <<<'GRAPHQL'
bestemtFastEjendom
forretningshaendelse
forretningsomraade
forretningsproces
gru009Vandforsyning
gru010Afloebsforhold
gru021Udledningstilladelse
gru022MedlemskabAfSpildevandsforsyning
gru023PaabudVedrSpildevandsafledning
gru024FristVedrSpildevandsafledning
gru025TilladelseTilUdtraeden
gru026DatoForTilladelseTilUdtraeden
gru027TilladelseTilAlternativBortskaffelseEllerAfledning
gru028DatoForTilladelseTilAlternativBortskaffelseEllerAfledning
gru029DispensationFritagelseIftKollektivVarmeforsyning
gru030DatoForDispensationFritagelseIftKollektivVarmeforsyning
gru500Notatlinjer
husnummer
id_lokalId
kommunekode
registreringFra
registreringsaktoer
registreringTil
status
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}

	/**
	 * Return the field selection of every full query of buildings.
	 *
	 * @return string  BBR_Bygning field names, one per line.
	 */
	private function buildingFields(): string {
		return <<<'GRAPHQL'
byg007Bygningsnummer
byg021BygningensAnvendelse
byg024AntalLejlighederMedKoekken
byg025AntalLejlighederUdenKoekken
byg026Opfoerelsesaar
byg027OmTilbygningsaar
byg029DatoForMidlertidigOpfoertBygning
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
byg046SamletArealAfLukkedeOverdaekningerPaaBygningen
byg047ArealAfAffaldsrumITerraenniveau
byg048AndetAreal
byg049ArealAfOverdaekketAreal
byg050ArealAabneOverdaekningerPaaBygningenSamlet
byg051Adgangsareal
byg052BeregningsprincipCarportAreal
byg053BygningsarealerKilde
byg054AntalEtager
byg055AfvigendeEtager
byg056Varmeinstallation
byg057Opvarmningsmiddel
byg058SupplerendeVarme
byg069Sikringsrumpladser
byg070Fredning
byg071BevaringsvaerdighedReference
byg094Revisionsdato
byg111StormraadetsOversvoemmelsesSelvrisiko
byg112DatoForRegistreringFraStormraadet
byg113Byggeskadeforsikringsselskab
byg114DatoForByggeskadeforsikring
byg119Udledningstilladelse
byg121OmfattetAfByggeskadeforsikring
byg122Gyldighedsdato
byg123MedlemskabAfSpildevandsforsyning
byg124PaabudVedrSpildevandsafledning
byg125FristVedrSpildevandsafledning
byg126TilladelseTilUdtraeden
byg127DatoForTilladelseTilUdtraeden
byg128TilladelseTilAlternativBortskaffelseEllerAfledning
byg129DatoForTilladelseTilAlternativBortskaffelseEllerAfledning
byg130ArealAfUdvendigEfterisolering
byg131DispensationFritagelseIftKollektivVarmeforsyning
byg132DatoForDispensationFritagelseIftKollektivVarmeforsyning
byg133KildeTilKoordinatsaet
byg134KvalitetAfKoordinatsaet
byg135SupplerendeOplysningOmKoordinatsaet
byg136PlaceringPaaSoeterritorie
byg137BanedanmarkBygvaerksnummer
byg140ServitutForUdlejningsEjendomDato
byg150Gulvbelaegning
byg151Frihoejde
byg152AabenLukketKonstruktion
byg153Konstruktionsforhold
byg301TypeAfFlytning
byg302Tilflytterkommune
byg403OevrigeBemaerkningerFraStormraadet
byg404Koordinat {
	crs
	wkt
}
byg406Koordinatsystem
byg500Notatlinjer
ejerlejlighed
forretningshaendelse
forretningsomraade
forretningsproces
grund
husnummer
id_lokalId
jordstykke
kommunekode
registreringFra
registreringsaktoer
registreringTil
status
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}

	/**
	 * Return the field selection of every full query of units.
	 *
	 * @return string  BBR_Enhed field names, one per line.
	 */
	private function unitFields(): string {
		return <<<'GRAPHQL'
adresseIdentificerer
bygning
enh008UUIDTilModerlejlighed
enh020EnhedensAnvendelse
enh023Boligtype
enh024KondemneretBoligenhed
enh025OprettelsesdatoForEnhedensIdentifikation
enh026EnhedensSamledeAreal
enh027ArealTilBeboelse
enh028ArealTilErhverv
enh030KildeTilEnhedensArealer
enh031AntalVaerelser
enh032Toiletforhold
enh033Badeforhold
enh034Koekkenforhold
enh035Energiforsyning
enh039AndetAreal
enh041LovligAnvendelse
enh042DatoForTidsbegraensetDispensation
enh044DatoForDelvisIbrugtagningsTilladelse
enh045Udlejningsforhold
enh046OffentligStoette
enh047IndflytningDato
enh048GodkendtTomBolig
enh051Varmeinstallation
enh052Opvarmningsmiddel
enh053SupplerendeVarme
enh060EnhedensAndelFaellesAdgangsareal
enh061ArealAfAabenOverdaekning
enh062ArealAfLukketOverdaekningUdestue
enh063AntalVaerelserTilErhverv
enh065AntalVandskylledeToiletter
enh066AntalBadevaerelser
enh067Stoejisolering
enh068FlexboligTilladelsesart
enh069FlexboligOphoersdato
enh070AabenAltanTagterrasseAreal
enh071AdresseFunktion
enh101Gyldighedsdato
enh102HerafAreal1
enh103HerafAreal2
enh104HerafAreal3
enh105SupplerendeAnvendelseskode1
enh106SupplerendeAnvendelseskode2
enh107SupplerendeAnvendelseskode3
enh127FysiskArealTilBeboelse
enh128FysiskArealTilErhverv
enh500Notatlinjer
etage
forretningshaendelse
forretningsomraade
forretningsproces
id_lokalId
kommunekode
opgang
registreringFra
registreringsaktoer
registreringTil
status
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}

	/**
	 * Return the field selection of every query of floors.
	 *
	 * @return string  BBR_Etage field names, one per line.
	 */
	private function floorFields(): string {
		return <<<'GRAPHQL'
bygning
eta006BygningensEtagebetegnelse
eta020SamletArealAfEtage
eta021ArealAfUdnyttetDelAfTagetage
eta022Kaelderareal
eta023ArealAfLovligBeboelseIKaelder
eta024EtagensAdgangsareal
eta025Etagetype
eta026ErhvervIKaelder
eta500Notatlinjer
forretningshaendelse
forretningsomraade
forretningsproces
id_lokalId
kommunekode
registreringFra
registreringsaktoer
registreringTil
status
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}

	/**
	 * Return the field selection of every query of entrances.
	 *
	 * @return string  BBR_Opgang field names, one per line.
	 */
	private function entranceFields(): string {
		return <<<'GRAPHQL'
adgangFraHusnummer
bygning
forretningshaendelse
forretningsomraade
forretningsproces
id_lokalId
kommunekode
opg020Elevator
opg021HusnummerFunktion
opg500Notatlinjer
registreringFra
registreringsaktoer
registreringTil
status
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}

	/**
	 * Return the field selection of every query of technical installations.
	 *
	 * @return string  BBR_TekniskAnlaeg field names, one per line.
	 */
	private function technicalFields(): string {
		return <<<'GRAPHQL'
bygning
bygningPaaFremmedGrund
ejerlejlighed
enhed
forretningshaendelse
forretningsomraade
forretningsproces
grund
husnummer
id_lokalId
jordstykke
kommunekode
registreringFra
registreringsaktoer
registreringTil
status
tek007Anlaegsnummer
tek020Klassifikation
tek021FabrikatType
tek022EksternDatabase
tek023EksternNoegle
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
tek037Areal
tek038Hoejde
tek039Effekt
tek040Fredning
tek042Revisionsdato
tek045Koordinatsystem
tek067Fabrikationsaar
tek068Materiale
tek069SupplerendeIndvendigKorrosionsbeskyttelse
tek070DatoForSenestUdfoerteSupplerendeIndvendigKorrosionsbeskyttelse
tek072Sloejfningsaar
tek073Navhoejde
tek074Vindmoellenummer
tek075Rotordiameter
tek076KildeTilKoordinatsaet
tek077KvalitetAfKoordinatsaet
tek078SupplerendeOplysningOmKoordinatsaet
tek101Gyldighedsdato
tek102FabrikatVindmoelle
tek103FabrikatOliefyr
tek104FabrikatSolcelleanlaegSolvarme
tek105OverdaekningTank
tek106InspektionsdatoTank
tek107PlaceringPaaSoeterritorie
tek109Koordinat {
	crs
	wkt
}
tek110Driftstatus
tek111DatoForSenesteInspektion
tek112InspicerendeVirksomhed
tek500Notatlinjer
virkningFra
virkningsaktoer
virkningTil
GRAPHQL;
	}
}
