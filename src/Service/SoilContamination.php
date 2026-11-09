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

use CitOmni\DanishPublicSectorData\Enum\SoilContaminationStatus;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;
use CitOmni\DanishPublicSectorData\Support\DkJordWfsClient;
use CitOmni\Kernel\Service\BaseService;

/**
 * SoilContamination: Read the DKjord soil-contamination status of Danish cadastral parcels.
 *
 * Behavior:
 * - Reads the status that DKjord registers on each parcel from the public DKjord WFS locality layer.
 * - Matches matrikelnumre exactly inside the semicolon-separated Lokalitetsmatrikler field of that layer.
 * - Resolves a BFE number to its current physical cadastral parcels through Datafordeleren's Matriklen data.
 *
 * Notes:
 * - The status is the parcel's own status, not one derived from which status-declaration layers list the parcel.
 * - Paabud, omraadeklassificering and nuancering are not part of the result.
 * - No geometry is downloaded; the DKjord client requests only locality metadata.
 * - Owner apartments and buildings on foreign ground inherit the physical parcel scope of the underlying SFE.
 * - A successful lookup of a parcel that no DKjord locality lists returns hasDkJordMatch=false rather than null.
 *
 * Typical usage:
 *   $result = $this->app->soilContamination->getByBfe(3208712);
 *   $parcel = $this->app->soilContamination->getByParcel(2005352, '311a');
 *
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When a remote lookup fails.
 */
final class SoilContamination extends BaseService {

	private const int PAGE_SIZE = 1000;
	private const int FILTER_LIST_SIZE = 100;

	/** WFS type of the DKjord localities; Lokalitetsmatrikler lists each parcel of a locality with the parcel's status. */
	private const string DKJORD_LOCALITY_TYPE = 'DKJord:View_Lokaliteter';

	/** @var list<string> Locality properties that the lookup reads; geometry and descriptive fields stay out. */
	private const array DKJORD_LOCALITY_PROPERTIES = [
		'Lokalitetsnr',
		'Lokalitetsnavn',
		'Lokalitetsejerlavkode',
		'Lokalitetsmatrikler',
	];

	/** @var array<string,SoilContaminationStatus> Parcel status texts of DKjord, keyed by the exact text. */
	private const array DKJORD_STATUS_TEXTS = [
		'V1 kortlagt' => SoilContaminationStatus::V1,
		'V2 kortlagt' => SoilContaminationStatus::V2,
		'V1 og V2 kortlagt' => SoilContaminationStatus::V1AndV2,
		'Lokaliseret (uafklaret)' => SoilContaminationStatus::Localized,
		// Code 12 of DKjord's status code list; assumed to carry this text, not yet seen in WFS data.
		'Lokaliseret (uafklaret). Oprydning iværksat' => SoilContaminationStatus::Localized,
		'Udgået efter kortlægning' => SoilContaminationStatus::RemovedAfterMapping,
		'Udgået inden kortlægning' => SoilContaminationStatus::RemovedBeforeMapping,
	];

	private DatafordelerClient $datafordeler;
	private DkJordWfsClient $dkJordWfs;
	private string $matrikelService;
	private string $matrikelVersion;

	/** @var array<int,array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}>> Parsed DKjord localities per ejerlav, keyed by matrikelnummer. */
	private array $dkJordDistrictCache = [];

	/**
	 * Initialize the Matriklen and DKjord WFS clients.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When package configuration is incomplete.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->soil_contamination;

		$this->matrikelService = (string)$cfg->matrikel_service;
		$this->matrikelVersion = (string)$cfg->matrikel_version;

		if ($this->matrikelService === '' || $this->matrikelVersion === '') {
			throw new \UnexpectedValueException('Soil contamination Matriklen service and version must be configured.');
		}

		$this->datafordeler = new DatafordelerClient($this->app);
		$this->dkJordWfs = new DkJordWfsClient($this->app);
	}

	/**
	 * Return the DKjord soil-contamination status of one exact cadastral parcel.
	 *
	 * Behavior:
	 * - status is the SoilContaminationStatus value of the status that DKjord registers on the parcel,
	 *   and statusText DKjord's own text for it, such as "V1 og V2 kortlagt".
	 * - A parcel that no DKjord locality lists has hasDkJordMatch=false, status and statusText null
	 *   and no locations. This does not mean that the soil is uncontaminated.
	 * - locations lists the DKjord localities that list the parcel, ordered by locationReference.
	 * - Requests each ejerlav from DKjord once per service instance.
	 *
	 * @param int|string $cadastralDistrictIdentifier Official ejerlav identifier.
	 * @param string $landParcelIdentifier Exact matrikelnummer within the cadastral district.
	 * @return array{cadastralDistrictIdentifier:int,landParcelIdentifier:string,hasDkJordMatch:bool,status:?string,statusText:?string,locations:list<array{locationReference:string,locationName:?string}>,fetchedAt:string} Normalized parcel status.
	 * @throws \InvalidArgumentException When an identifier is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When DKjord lists the parcel with an unknown status text or with several statuses, or returns a malformed locality.
	 */
	public function getByParcel(int|string $cadastralDistrictIdentifier, string $landParcelIdentifier): array {
		$cadastralDistrictIdentifier = self::normalizePositiveInt(
			$cadastralDistrictIdentifier,
			'Cadastral district identifier'
		);
		$landParcelIdentifier = self::normalizeLandParcelIdentifier($landParcelIdentifier);

		return self::normalizeParcelStatus(
			$cadastralDistrictIdentifier,
			$landParcelIdentifier,
			$this->loadDkJordDistrict($cadastralDistrictIdentifier)
		);
	}

	/**
	 * Resolve one BFE number to its current physical cadastral parcel scope.
	 *
	 * Behavior:
	 * - Resolves SFE/BPFG/owner-apartment relations through current Matriklen data.
	 * - Returns current parcel identifiers and cadastral district metadata only.
	 * - Does not call DKjord WFS.
	 *
	 * @param int|string $bfeNumber Positive BFE number.
	 * @return array<string,mixed>|null Normalized property parcel scope, or null when the BFE is not found in current Matriklen data.
	 */
	public function getParcelsByBfe(int|string $bfeNumber): ?array {
		$bfe = self::normalizePositiveInt($bfeNumber, 'BFE number');
		$effectiveAt = self::now();
		$property = $this->resolveMatrikelProperty($bfe, $effectiveAt);

		if ($property === null) {
			return null;
		}

		$sfeId = $property['underlyingSfeId'];
		$parcels = $sfeId !== null
			? $this->loadCurrentParcels($sfeId, $effectiveAt)
			: [];
		$districts = $this->loadCadastralDistricts($parcels, $effectiveAt);
		$normalizedParcels = [];

		foreach ($parcels as $parcel) {
			$districtId = self::requiredString($parcel['ejerlavLokalId'] ?? null, 'MAT_Jordstykke.ejerlavLokalId');
			$district = $districts[$districtId] ?? null;
			if (!\is_array($district)) {
				throw new InvalidResponseException('Datafordeler did not return the cadastral district for a current parcel.');
			}

			$normalizedParcels[] = [
				'landParcelId' => self::requiredString($parcel['id_lokalId'] ?? null, 'MAT_Jordstykke.id_lokalId'),
				'cadastralDistrictId' => $districtId,
				'cadastralDistrictIdentifier' => self::requiredInt(
					$district['ejerlavskode'] ?? null,
					'MAT_Ejerlav.ejerlavskode'
				),
				'cadastralDistrictName' => self::nullableString($district['ejerlavsnavn'] ?? null),
				'landParcelIdentifier' => self::normalizeLandParcelIdentifier(
					self::requiredString($parcel['matrikelnummer'] ?? null, 'MAT_Jordstykke.matrikelnummer')
				),
				'registeredArea' => self::nullableInt($parcel['registreretAreal'] ?? null),
				'matrikelStatus' => self::nullableString($parcel['status'] ?? null),
			];
		}

		\usort(
			$normalizedParcels,
			static fn(array $a, array $b): int => [
				$a['cadastralDistrictIdentifier'],
				$a['landParcelIdentifier'],
			] <=> [
				$b['cadastralDistrictIdentifier'],
				$b['landParcelIdentifier'],
			]
		);

		return [
			'bfeNumber' => $bfe,
			'propertyType' => $property['propertyType'],
			'propertyId' => $property['propertyId'],
			'underlyingSfeId' => $sfeId,
			'parcelResolutionStatus' => $sfeId === null
				? 'no_underlying_sfe'
				: ($normalizedParcels === [] ? 'no_current_parcels' : 'resolved'),
			'fetchedAt' => self::now(),
			'parcels' => $normalizedParcels,
		];
	}

	/**
	 * Resolve one BFE number to physical cadastral parcels and attach their current DKjord status.
	 *
	 * Behavior:
	 * - Every parcel gets contamination, the getByParcel() result for that parcel.
	 * - hasDkJordMatch is true when DKjord lists at least one of the parcels.
	 * - statuses holds each distinct parcel status once, in SoilContaminationStatus case order.
	 *
	 * @param int|string $bfeNumber Positive BFE number.
	 * @return array<string,mixed>|null Normalized property parcel scope, or null when the BFE is not found in current Matriklen data.
	 * @throws \InvalidArgumentException When the BFE number is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When DKjord lists one of the parcels with an unknown status text or with several statuses, or returns a malformed locality.
	 * @see \CitOmni\DanishPublicSectorData\Service\SoilContamination::getByParcel()  The status result of each parcel.
	 */
	public function getByBfe(int|string $bfeNumber): ?array {
		$scope = $this->getParcelsByBfe($bfeNumber);
		if ($scope === null) {
			return null;
		}

		$normalizedParcels = [];
		$propertyStatuses = [];
		$hasDkJordMatch = false;

		foreach ($scope['parcels'] as $parcel) {
			$contamination = $this->getByParcel(
				$parcel['cadastralDistrictIdentifier'],
				$parcel['landParcelIdentifier']
			);

			if ($contamination['status'] !== null) {
				$propertyStatuses[$contamination['status']] = true;
			}
			$hasDkJordMatch = $hasDkJordMatch || $contamination['hasDkJordMatch'];

			$parcel['contamination'] = $contamination;
			$normalizedParcels[] = $parcel;
		}

		$scope['hasDkJordMatch'] = $hasDkJordMatch;
		$scope['statuses'] = self::orderedStatuses($propertyStatuses);
		$scope['fetchedAt'] = self::now();
		$scope['parcels'] = $normalizedParcels;

		return $scope;
	}

	/**
	 * Load, parse and cache the DKjord localities of one ejerlav.
	 *
	 * @return array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}> Parcels keyed by matrikelnummer.
	 */
	private function loadDkJordDistrict(int $cadastralDistrictIdentifier): array {
		if (isset($this->dkJordDistrictCache[$cadastralDistrictIdentifier])) {
			return $this->dkJordDistrictCache[$cadastralDistrictIdentifier];
		}

		$parcels = self::parseLocalities(
			$cadastralDistrictIdentifier,
			$this->dkJordWfs->getFeatures(
				self::DKJORD_LOCALITY_TYPE,
				$cadastralDistrictIdentifier,
				self::DKJORD_LOCALITY_PROPERTIES
			)
		);

		$this->dkJordDistrictCache[$cadastralDistrictIdentifier] = $parcels;

		return $parcels;
	}

	/**
	 * Collect the status texts and localities of every parcel that the localities of one ejerlav list.
	 *
	 * Lokalitetsmatrikler holds "matrikelnummer,status text" entries separated by semicolons. The
	 * same locality can come in several features with the same list, and a parcel can repeat in one
	 * list; both collapse here.
	 *
	 * @param list<array<string,mixed>> $features GeoJSON features of the locality layer.
	 * @return array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}> Parcels keyed by matrikelnummer; locations maps locality number to name.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When a feature is invalid, lies outside the ejerlav or holds a malformed entry.
	 */
	private static function parseLocalities(int $cadastralDistrictIdentifier, array $features): array {
		$parcels = [];

		foreach ($features as $feature) {
			if (!\is_array($feature) || !\is_array($feature['properties'] ?? null)) {
				throw new InvalidResponseException('DKjord WFS locality layer contains an invalid feature.');
			}

			$properties = $feature['properties'];
			$district = self::requiredInt(
				$properties['Lokalitetsejerlavkode'] ?? null,
				'DKjord Lokalitetsejerlavkode'
			);
			if ($district !== $cadastralDistrictIdentifier) {
				throw new InvalidResponseException('DKjord WFS returned a locality outside the requested cadastral district.');
			}

			$locationReference = self::requiredString($properties['Lokalitetsnr'] ?? null, 'DKjord Lokalitetsnr');
			$locationName = self::nullableString($properties['Lokalitetsnavn'] ?? null);

			foreach (self::splitSemicolonList(self::nullableString($properties['Lokalitetsmatrikler'] ?? null)) as $entry) {
				$separator = \strpos($entry, ',');
				$landParcelIdentifier = $separator !== false ? \trim(\substr($entry, 0, $separator)) : '';
				$statusText = $separator !== false ? \trim(\substr($entry, $separator + 1)) : '';

				if ($landParcelIdentifier === '' || $statusText === '') {
					throw new InvalidResponseException('DKjord Lokalitetsmatrikler contains a malformed entry in locality ' . $locationReference . '.');
				}

				$parcels[$landParcelIdentifier]['statusTexts'][$statusText] = true;
				$parcels[$landParcelIdentifier]['locations'][$locationReference] = $locationName;
			}
		}

		return $parcels;
	}

	/**
	 * Build the status result of one parcel from the parsed localities of its ejerlav.
	 *
	 * @param array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}> $parcels Parsed parcels of the ejerlav, keyed by matrikelnummer.
	 * @return array{cadastralDistrictIdentifier:int,landParcelIdentifier:string,hasDkJordMatch:bool,status:?string,statusText:?string,locations:list<array{locationReference:string,locationName:?string}>,fetchedAt:string}
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the parcel carries several status texts or an unknown one.
	 */
	private static function normalizeParcelStatus(
		int $cadastralDistrictIdentifier,
		string $landParcelIdentifier,
		array $parcels
	): array {
		$parcel = $parcels[$landParcelIdentifier] ?? null;

		if ($parcel === null) {
			return [
				'cadastralDistrictIdentifier' => $cadastralDistrictIdentifier,
				'landParcelIdentifier' => $landParcelIdentifier,
				'hasDkJordMatch' => false,
				'status' => null,
				'statusText' => null,
				'locations' => [],
				'fetchedAt' => self::now(),
			];
		}

		$label = $cadastralDistrictIdentifier . ' ' . $landParcelIdentifier;
		$statusTexts = \array_keys($parcel['statusTexts']);

		// DKjord registers one status per parcel; two texts would leave the status ambiguous.
		if (\count($statusTexts) !== 1) {
			throw new InvalidResponseException('DKjord lists parcel ' . $label . ' with several statuses: ' . \implode(', ', $statusTexts) . '.');
		}

		$statusText = (string)$statusTexts[0];
		$status = self::DKJORD_STATUS_TEXTS[$statusText] ?? null;
		if ($status === null) {
			throw new InvalidResponseException('DKjord lists parcel ' . $label . ' with an unknown status: ' . $statusText . '.');
		}

		$locations = [];
		foreach ($parcel['locations'] as $locationReference => $locationName) {
			$locations[] = [
				'locationReference' => (string)$locationReference,
				'locationName' => $locationName,
			];
		}
		\usort(
			$locations,
			static fn(array $a, array $b): int => \strcmp($a['locationReference'], $b['locationReference'])
		);

		return [
			'cadastralDistrictIdentifier' => $cadastralDistrictIdentifier,
			'landParcelIdentifier' => $landParcelIdentifier,
			'hasDkJordMatch' => true,
			'status' => $status->value,
			'statusText' => $statusText,
			'locations' => $locations,
			'fetchedAt' => self::now(),
		];
	}

	/**
	 * Return statuses in SoilContaminationStatus case order.
	 *
	 * @param array<string,bool> $statuses Status values as keys.
	 * @return list<string>
	 */
	private static function orderedStatuses(array $statuses): array {
		$result = [];

		foreach (SoilContaminationStatus::cases() as $status) {
			if (isset($statuses[$status->value])) {
				$result[] = $status->value;
			}
		}

		return $result;
	}

	/**
	 * Split one DKjord semicolon-separated source field into exact non-empty values.
	 *
	 * @return list<string>
	 */
	private static function splitSemicolonList(?string $value): array {
		if ($value === null) {
			return [];
		}

		$result = [];
		foreach (\explode(';', $value) as $item) {
			$item = \trim($item);
			if ($item !== '') {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * Resolve the BFE type and the SFE whose parcels define its physical ground scope.
	 *
	 * @return array{propertyType:string,propertyId:string,underlyingSfeId:?string}|null
	 */
	private function resolveMatrikelProperty(int $bfe, string $effectiveAt): ?array {
		$sfe = $this->fetchOne(
			'MAT_SamletFastEjendom',
			'BFE ' . $bfe,
			fn(?string $after): string => $this->buildSfeByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($sfe !== null) {
			$id = self::requiredString($sfe['id_lokalId'] ?? null, 'MAT_SamletFastEjendom.id_lokalId');

			return [
				'propertyType' => 'sfe',
				'propertyId' => $id,
				'underlyingSfeId' => $id,
			];
		}

		$bpfgFlade = $this->fetchOne(
			'MAT_BygningPaaFremmedGrundFlade',
			'BFE ' . $bfe,
			fn(?string $after): string => $this->buildBpfgFladeByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($bpfgFlade !== null) {
			return $this->normalizeBpfgProperty($bpfgFlade, 'MAT_BygningPaaFremmedGrundFlade');
		}

		$bpfgPunkt = $this->fetchOne(
			'MAT_BygningPaaFremmedGrundPunkt',
			'BFE ' . $bfe,
			fn(?string $after): string => $this->buildBpfgPunktByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($bpfgPunkt !== null) {
			return $this->normalizeBpfgProperty($bpfgPunkt, 'MAT_BygningPaaFremmedGrundPunkt');
		}

		$ownerApartment = $this->fetchOne(
			'MAT_Ejerlejlighed',
			'BFE ' . $bfe,
			fn(?string $after): string => $this->buildOwnerApartmentByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($ownerApartment === null) {
			return null;
		}

		$propertyId = self::requiredString($ownerApartment['id_lokalId'] ?? null, 'MAT_Ejerlejlighed.id_lokalId');
		$sfeId = self::nullableString($ownerApartment['samletFastEjendomLokalId'] ?? null);

		if ($sfeId === null) {
			$bpfgFladeId = self::nullableString($ownerApartment['b_BygningPaaFremmedGrundFladeL'] ?? null);
			$bpfgPunktId = self::nullableString($ownerApartment['b_BygningPaaFremmedGrundPunktL'] ?? null);

			if ($bpfgFladeId !== null) {
				$parent = $this->fetchOne(
					'MAT_BygningPaaFremmedGrundFlade',
					'id ' . $bpfgFladeId,
					fn(?string $after): string => $this->buildBpfgFladeByIdQuery($bpfgFladeId, $effectiveAt, $after)
				);
				$sfeId = \is_array($parent)
					? self::nullableString($parent['samletFastEjendomLokalId'] ?? null)
					: null;
			} elseif ($bpfgPunktId !== null) {
				$parent = $this->fetchOne(
					'MAT_BygningPaaFremmedGrundPunkt',
					'id ' . $bpfgPunktId,
					fn(?string $after): string => $this->buildBpfgPunktByIdQuery($bpfgPunktId, $effectiveAt, $after)
				);
				$sfeId = \is_array($parent)
					? self::nullableString($parent['samletFastEjendomLokalId'] ?? null)
					: null;
			}
		}

		return [
			'propertyType' => 'ejerlejlighed',
			'propertyId' => $propertyId,
			'underlyingSfeId' => $sfeId,
		];
	}

	/**
	 * Normalize one building-on-foreign-ground Matriklen node.
	 *
	 * @return array{propertyType:string,propertyId:string,underlyingSfeId:?string}
	 */
	private function normalizeBpfgProperty(array $node, string $root): array {
		return [
			'propertyType' => 'bpfg',
			'propertyId' => self::requiredString($node['id_lokalId'] ?? null, $root . '.id_lokalId'),
			'underlyingSfeId' => self::nullableString($node['samletFastEjendomLokalId'] ?? null),
		];
	}

	/**
	 * Load current parcels belonging to one SFE.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function loadCurrentParcels(string $sfeId, string $effectiveAt): array {
		$rows = $this->fetchAll(
			'MAT_Jordstykke',
			fn(?string $after): string => $this->buildParcelsBySfeQuery($sfeId, $effectiveAt, $after)
		);
		$result = [];

		foreach ($rows as $row) {
			$status = self::nullableString($row['status'] ?? null);
			if ($status !== null && !self::isCurrentStatus($status)) {
				continue;
			}
			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Load cadastral-district metadata for the returned parcels.
	 *
	 * @param list<array<string,mixed>> $parcels
	 * @return array<string,array<string,mixed>> Rows keyed by id_lokalId.
	 */
	private function loadCadastralDistricts(array $parcels, string $effectiveAt): array {
		$ids = [];
		foreach ($parcels as $parcel) {
			$id = self::nullableString($parcel['ejerlavLokalId'] ?? null);
			if ($id !== null) {
				$ids[$id] = true;
			}
		}

		if ($ids === []) {
			return [];
		}

		$result = [];

		foreach (\array_chunk(\array_keys($ids), self::FILTER_LIST_SIZE) as $chunk) {
			$rows = $this->fetchAll(
				'MAT_Ejerlav',
				fn(?string $after): string => $this->buildCadastralDistrictsByIdsQuery($chunk, $effectiveAt, $after)
			);

			foreach ($rows as $row) {
				$id = self::requiredString($row['id_lokalId'] ?? null, 'MAT_Ejerlav.id_lokalId');
				$result[$id] = $row;
			}
		}

		return $result;
	}

	/**
	 * Fetch the current version of at most one Matriklen object from one connection.
	 *
	 * @param string $root Connection name.
	 * @param string $subject What the lookup asks for, such as "BFE 123"; names it in the exception message.
	 * @param callable(?string):string $queryBuilder Cursor-aware query builder whose nodes select id_lokalId and status.
	 * @return array<string,mixed>|null The node, or null when no current version matches.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When several current objects match, or a response is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\SoilContamination::currentNode()  Which node counts as current.
	 */
	private function fetchOne(string $root, string $subject, callable $queryBuilder): ?array {
		return self::currentNode($root, $subject, $this->fetchAll($root, $queryBuilder));
	}

	/**
	 * Pick the current version of at most one Matriklen object from the nodes of a lookup.
	 *
	 * Behavior:
	 * - Only nodes with status Gaeldende, or without a status, count. A preliminary version from a
	 *   pending cadastral case (status Foreloebig) is ignored.
	 * - Identical nodes count once.
	 *
	 * @param string $root Connection name.
	 * @param string $subject What the lookup asks for; names it in the exception message.
	 * @param list<array<string,mixed>> $nodes Nodes of the lookup.
	 * @return array<string,mixed>|null The node, or null when no current version is left.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When several different current nodes remain.
	 */
	private static function currentNode(string $root, string $subject, array $nodes): ?array {
		$current = [];
		$statuses = [];

		foreach ($nodes as $node) {
			$status = self::nullableString($node['status'] ?? null);
			$statuses[] = $status ?? 'none';
			if ($status !== null && !self::isCurrentStatus($status)) {
				continue;
			}

			if (!\in_array($node, $current, true)) {
				$current[] = $node;
			}
		}

		if (\count($current) > 1) {
			throw new InvalidResponseException(
				'Datafordeler returned ' . \count($current) . ' current ' . $root . ' nodes for ' . $subject
				. ' (statuses of all nodes: ' . \implode(', ', $statuses) . ').'
			);
		}

		return $current[0] ?? null;
	}

	/**
	 * Fetch all pages from one Datafordeler current-state connection.
	 *
	 * @param callable(?string):string $queryBuilder Cursor-aware query builder.
	 * @return list<array<string,mixed>>
	 */
	private function fetchAll(string $root, callable $queryBuilder): array {
		$nodes = [];
		$after = null;

		do {
			$data = $this->datafordeler->query(
				$this->matrikelService,
				$this->matrikelVersion,
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

	// ----------------------------------------------------------------
	// GraphQL current-state query builders
	// ----------------------------------------------------------------

	private function buildSfeByBfeQuery(int $bfe, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);

		return <<<GRAPHQL
query SoilContaminationSfeByBfe {
	MAT_SamletFastEjendom(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { BFEnummer: { eq: {$bfe} } }
	) {
		nodes {
			id_lokalId
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBpfgFladeByBfeQuery(int $bfe, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);

		return <<<GRAPHQL
query SoilContaminationBpfgFladeByBfe {
	MAT_BygningPaaFremmedGrundFlade(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { BFEnummer: { eq: {$bfe} } }
	) {
		nodes {
			id_lokalId
			status
			samletFastEjendomLokalId
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBpfgPunktByBfeQuery(int $bfe, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);

		return <<<GRAPHQL
query SoilContaminationBpfgPunktByBfe {
	MAT_BygningPaaFremmedGrundPunkt(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { BFEnummer: { eq: {$bfe} } }
	) {
		nodes {
			id_lokalId
			status
			samletFastEjendomLokalId
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildOwnerApartmentByBfeQuery(int $bfe, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);

		return <<<GRAPHQL
query SoilContaminationOwnerApartmentByBfe {
	MAT_Ejerlejlighed(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { BFEnummer: { eq: {$bfe} } }
	) {
		nodes {
			id_lokalId
			status
			samletFastEjendomLokalId
			b_BygningPaaFremmedGrundFladeL
			b_BygningPaaFremmedGrundPunktL
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBpfgFladeByIdQuery(string $id, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($id);

		return <<<GRAPHQL
query SoilContaminationBpfgFladeById {
	MAT_BygningPaaFremmedGrundFlade(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			status
			samletFastEjendomLokalId
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildBpfgPunktByIdQuery(string $id, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$id = self::gqlString($id);

		return <<<GRAPHQL
query SoilContaminationBpfgPunktById {
	MAT_BygningPaaFremmedGrundPunkt(
		first: 2{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { eq: {$id} } }
	) {
		nodes {
			id_lokalId
			status
			samletFastEjendomLokalId
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildParcelsBySfeQuery(string $sfeId, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$sfeId = self::gqlString($sfeId);
		$pageSize = self::PAGE_SIZE;

		return <<<GRAPHQL
query SoilContaminationParcelsBySfe {
	MAT_Jordstykke(
		first: {$pageSize}{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { samletFastEjendomLokalId: { eq: {$sfeId} } }
	) {
		nodes {
			id_lokalId
			ejerlavLokalId
			matrikelnummer
			registreretAreal
			status
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	private function buildCadastralDistrictsByIdsQuery(array $ids, string $effectiveAt, ?string $after): string {
		$afterArg = self::afterArgument($after);
		$ids = self::gqlStringList($ids);
		$pageSize = self::PAGE_SIZE;

		return <<<GRAPHQL
query SoilContaminationCadastralDistricts {
	MAT_Ejerlav(
		first: {$pageSize}{$afterArg}
		virkningstid: "{$effectiveAt}"
		where: { id_lokalId: { in: {$ids} } }
	) {
		nodes {
			id_lokalId
			ejerlavskode
			ejerlavsnavn
		}
		{$this->pageInfo()}
	}
}
GRAPHQL;
	}

	/**
	 * Return the common pageInfo GraphQL selection.
	 */
	private function pageInfo(): string {
		return <<<'GRAPHQL'
		pageInfo {
			hasNextPage
			endCursor
		}
GRAPHQL;
	}

	/**
	 * Build an optional GraphQL cursor argument.
	 */
	private static function afterArgument(?string $after): string {
		return $after !== null
			? "\n\t\tafter: " . self::gqlString($after)
			: '';
	}

	/**
	 * Encode one GraphQL string literal.
	 */
	private static function gqlString(string $value): string {
		try {
			return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('GraphQL string value cannot be encoded.');
		}
	}

	/**
	 * Encode one non-empty GraphQL string list.
	 *
	 * @param list<string> $values
	 */
	private static function gqlStringList(array $values): string {
		if ($values === []) {
			throw new \InvalidArgumentException('GraphQL string list cannot be empty.');
		}

		return '[' . \implode(', ', \array_map(
			static fn(string $value): string => self::gqlString($value),
			$values
		)) . ']';
	}

	/**
	 * Return whether a Matriklen status marks the current version of an object.
	 */
	private static function isCurrentStatus(string $status): bool {
		$normalized = \strtr(\trim($status), [
			'Æ' => 'AE',
			'Ø' => 'OE',
			'Å' => 'AA',
			'æ' => 'ae',
			'ø' => 'oe',
			'å' => 'aa',
		]);

		return \strtoupper($normalized) === 'GAELDENDE';
	}

	/**
	 * Normalize a positive integer without leading zeroes.
	 */
	private static function normalizePositiveInt(int|string $value, string $label): int {
		$string = \is_int($value) ? (string)$value : \trim($value);
		if (!\preg_match('/^[1-9][0-9]*$/D', $string)) {
			throw new \InvalidArgumentException($label . ' must be a positive integer without leading zeroes.');
		}

		$number = \filter_var($string, \FILTER_VALIDATE_INT);
		if ($number === false || $number < 1) {
			throw new \InvalidArgumentException($label . ' is outside the supported integer range.');
		}

		return $number;
	}

	/**
	 * Normalize one exact matrikelnummer.
	 */
	private static function normalizeLandParcelIdentifier(string $value): string {
		$value = \trim($value);
		if ($value === '' || \strlen($value) > 64 || \preg_match('/[\x00-\x1F\x7F]/', $value)) {
			throw new \InvalidArgumentException('Land parcel identifier must be a non-empty printable string of at most 64 bytes.');
		}

		return $value;
	}

	/**
	 * Normalize a scalar to a nullable string.
	 */
	private static function nullableString(mixed $value): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value) && !\is_int($value) && !\is_float($value)) {
			throw new InvalidResponseException('Expected a scalar string-compatible value.');
		}
		$value = \trim((string)$value);

		return $value !== '' ? $value : null;
	}

	/**
	 * Normalize a required scalar string.
	 */
	private static function requiredString(mixed $value, string $field): string {
		$value = self::nullableString($value);
		if ($value === null) {
			throw new InvalidResponseException($field . ' is missing or empty.');
		}

		return $value;
	}

	/**
	 * Normalize a nullable integer.
	 */
	private static function nullableInt(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (\is_int($value)) {
			return $value;
		}
		if (!\is_string($value) || !\preg_match('/^-?[0-9]+$/D', $value)) {
			throw new InvalidResponseException('Expected an integer-compatible value.');
		}

		$number = \filter_var($value, \FILTER_VALIDATE_INT);
		if ($number === false) {
			throw new InvalidResponseException('Integer value is outside the supported range.');
		}

		return $number;
	}

	/**
	 * Normalize a required integer.
	 */
	private static function requiredInt(mixed $value, string $field): int {
		$value = self::nullableInt($value);
		if ($value === null) {
			throw new InvalidResponseException($field . ' is missing.');
		}

		return $value;
	}

	/**
	 * Return an explicit UTC point-in-time for current-state source queries.
	 */
	private static function now(): string {
		return \gmdate('Y-m-d\TH:i:s\Z');
	}
}
