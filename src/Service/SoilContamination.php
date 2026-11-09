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
 * SoilContamination: Service that reads the soil-contamination status that DKjord registers on Danish cadastral parcels.
 *
 * Gives host apps the status of a parcel, or of the parcels of a property, from the DKjord
 * localities and from Datafordeler's Matriklen data. The status is the one DKjord registers on the
 * parcel itself, not one derived from the status-declaration layers that list the parcel; Paabud,
 * omraadeklassificering and nuancering are not part of it. Transport belongs to DatafordelerClient
 * and DkJordWfsClient.
 *
 * Rules:
 * - Fail rather than guess when DKjord or Matriklen is ambiguous, such as an unknown status text,
 *   two statuses of one parcel or two current properties with one BFE number.
 * - Never present a parcel that no locality lists as uncontaminated: Its status is null.
 *
 * @phpstan-type SoilParcelStatus array{
 *     cadastralDistrictIdentifier:int,
 *     landParcelIdentifier:string,
 *     hasDkJordMatch:bool,
 *     status:?string,
 *     statusText:?string,
 *     locations:list<array{locationReference:string,locationName:?string}>,
 *     fetchedAt:string
 * }
 * @phpstan-type SoilParcel array{
 *     landParcelId:string,
 *     cadastralDistrictId:string,
 *     cadastralDistrictIdentifier:int,
 *     cadastralDistrictName:?string,
 *     landParcelIdentifier:string,
 *     registeredArea:?int,
 *     matrikelStatus:?string
 * }
 * @phpstan-type SoilParcelScope array{
 *     bfeNumber:int,
 *     propertyType:string,
 *     propertyId:string,
 *     underlyingSfeId:?string,
 *     parcelResolutionStatus:string,
 *     fetchedAt:string,
 *     parcels:list<SoilParcel>
 * }
 * @phpstan-type SoilParcelWithStatus array{
 *     landParcelId:string,
 *     cadastralDistrictId:string,
 *     cadastralDistrictIdentifier:int,
 *     cadastralDistrictName:?string,
 *     landParcelIdentifier:string,
 *     registeredArea:?int,
 *     matrikelStatus:?string,
 *     contamination:SoilParcelStatus
 * }
 * @phpstan-type SoilPropertyStatus array{
 *     bfeNumber:int,
 *     propertyType:string,
 *     propertyId:string,
 *     underlyingSfeId:?string,
 *     parcelResolutionStatus:string,
 *     fetchedAt:string,
 *     parcels:list<SoilParcelWithStatus>,
 *     hasDkJordMatch:bool,
 *     statuses:list<string>
 * }
 */
class SoilContamination extends BaseService {

	/** Page size of the Matriklen lookups of parcels and of ejerlav. */
	private const int PAGE_SIZE = 1000;

	/** Maximum number of ids in one in filter. */
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

	/** Datafordeler client for Matriklen, which authenticates with the API key. */
	private DatafordelerClient $datafordeler;

	/** Client of the DKjord WFS. */
	private DkJordWfsClient $dkJordWfs;

	/** Datafordeler service that serves Matriklen. */
	private string $matrikelService;

	/** Version of that service. */
	private string $matrikelVersion;

	/** @var array<int,array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}>> Parsed DKjord localities per ejerlav, keyed by matrikelnummer. */
	private array $dkJordDistrictCache = [];

	/**
	 * Read the Matriklen endpoint from cfg and create the Datafordeler and DKjord clients.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.soil_contamination.matrikel_service or .matrikel_version is empty, or the Datafordeler or DKjord WFS cfg is invalid.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
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
	 * Return the DKjord soil-contamination status of one cadastral parcel.
	 *
	 * Behavior:
	 * - status is the SoilContaminationStatus value of the status that DKjord registers on the
	 *   parcel, and statusText DKjord's own text for it, such as "V1 og V2 kortlagt".
	 * - A parcel that no DKjord locality lists has hasDkJordMatch false, status and statusText null
	 *   and no locations. This does not mean that the soil is uncontaminated.
	 * - locations lists the DKjord localities that list the parcel, ordered by locationReference.
	 * - The matrikelnummer must match exactly; 311 does not match 311a.
	 * - fetchedAt is the time of the call in UTC, such as 2026-01-31T12:00:00Z.
	 * - Requests each ejerlav from DKjord once per service instance.
	 *
	 * Typical usage:
	 *   $parcel = $this->app->soilContamination->getByParcel(2005352, '311a');
	 *
	 * @param  int|string  $cadastralDistrictIdentifier  Official ejerlav code: A positive integer without leading zeroes.
	 * @param  string  $landParcelIdentifier  Matrikelnummer within the ejerlav, such as 311a: At most 64 bytes without control characters; surrounding whitespace is ignored.
	 * @return SoilParcelStatus
	 * @throws \InvalidArgumentException  When an identifier is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When DKjord lists the parcel with an unknown status text or with several statuses, or returns a malformed locality.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When the DKjord lookup fails.
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
	 * Return the current cadastral parcels of the ground of one property by BFE number.
	 *
	 * Resolves the property in current Matriklen data and the SFE whose parcels make up its ground,
	 * without asking DKjord.
	 *
	 * Behavior:
	 * - propertyType is sfe, bpfg or ejerlejlighed, and propertyId the property's Matriklen id.
	 * - underlyingSfeId is the property itself for an SFE, and for a building on foreign ground or an
	 *   owner apartment the SFE it stands on; null when Matriklen names none.
	 * - parcels lists the current parcels of that SFE, ordered by ejerlav code and matrikelnummer.
	 * - parcelResolutionStatus is resolved, no_current_parcels when the SFE has no current parcel, or
	 *   no_underlying_sfe when there is no SFE.
	 * - Only Matriklen objects with status Gaeldende, or without a status, count: A preliminary
	 *   version from a pending cadastral case is ignored.
	 * - fetchedAt is the time of the call in UTC, such as 2026-01-31T12:00:00Z.
	 *
	 * Typical usage:
	 *   $scope = $this->app->soilContamination->getParcelsByBfe(3208712);
	 *
	 * @param  int|string  $bfeNumber  BFE number: A positive integer without leading zeroes.
	 * @return SoilParcelScope|null  Null when current Matriklen data has no property with the number.
	 * @throws \InvalidArgumentException  When the BFE number is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When Matriklen has several current properties with the number, a parcel without its ejerlav, or a malformed answer.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a Matriklen lookup fails.
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
	 * Return the current cadastral parcels of one property by BFE number, each with its DKjord status.
	 *
	 * Behavior:
	 * - Every parcel gets contamination, the getByParcel() result for that parcel.
	 * - hasDkJordMatch is true when DKjord lists at least one of the parcels.
	 * - statuses holds each distinct parcel status once, in SoilContaminationStatus case order.
	 *
	 * Typical usage:
	 *   $result = $this->app->soilContamination->getByBfe(3208712);
	 *
	 * @param  int|string  $bfeNumber  BFE number: A positive integer without leading zeroes.
	 * @return SoilPropertyStatus|null  Null when current Matriklen data has no property with the number.
	 * @throws \InvalidArgumentException  When the BFE number is invalid.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When Matriklen or DKjord is ambiguous or malformed, as getParcelsByBfe() and getByParcel() describe.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a Matriklen or DKjord lookup fails.
	 * @see \CitOmni\DanishPublicSectorData\Service\SoilContamination::getParcelsByBfe()  The property and its parcels.
	 * @see \CitOmni\DanishPublicSectorData\Service\SoilContamination::getByParcel()  The status of each parcel.
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
	 * Return the parsed DKjord localities of one ejerlav, requested once per service instance.
	 *
	 * @param  int  $cadastralDistrictIdentifier  Official ejerlav code.
	 * @return array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}>  Parcels keyed by matrikelnummer.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When the DKjord lookup fails or a locality is malformed.
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
	 * @param  int  $cadastralDistrictIdentifier  Official ejerlav code that every locality must have.
	 * @param  list<array<string,mixed>>  $features  GeoJSON features of the locality layer.
	 * @return array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}>  Parcels keyed by matrikelnummer; locations maps locality number to name.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When a feature is invalid, lies outside the ejerlav or holds a malformed entry.
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
	 * @param  int  $cadastralDistrictIdentifier  Official ejerlav code.
	 * @param  string  $landParcelIdentifier  Matrikelnummer.
	 * @param  array<string,array{statusTexts:array<string,true>,locations:array<string,?string>}>  $parcels  Parsed parcels of the ejerlav, keyed by matrikelnummer.
	 * @return SoilParcelStatus
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the parcel carries several status texts or an unknown one.
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
	 * @param  array<string,bool>  $statuses  Status values as keys.
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
	 * Split a semicolon-separated DKjord field into its trimmed, non-empty values.
	 *
	 * @param  string|null  $value
	 * @return list<string>  Empty for null.
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
	 * Find the property of a BFE number in Matriklen and the SFE whose parcels make up its ground.
	 *
	 * Behavior:
	 * - Looks for an SFE, a building on foreign ground (area, then point) and an owner apartment, in
	 *   that order, and takes the first found.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array{propertyType:string,propertyId:string,underlyingSfeId:?string}|null  Null when Matriklen has no current property with the number.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a lookup fails or is malformed, or a lookup finds several current objects.
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
	 * Normalize one Matriklen node of a building on foreign ground.
	 *
	 * @param  array<string,mixed>  $node  Raw node.
	 * @param  string  $root  Connection name, for the exception message.
	 * @return array{propertyType:string,propertyId:string,underlyingSfeId:?string}
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node has no id or a value is invalid.
	 */
	private function normalizeBpfgProperty(array $node, string $root): array {
		return [
			'propertyType' => 'bpfg',
			'propertyId' => self::requiredString($node['id_lokalId'] ?? null, $root . '.id_lokalId'),
			'underlyingSfeId' => self::nullableString($node['samletFastEjendomLokalId'] ?? null),
		];
	}

	/**
	 * Return the current parcels of one SFE.
	 *
	 * Behavior:
	 * - A parcel with a status counts only when the status is Gaeldende.
	 *
	 * @param  string  $sfeId  Matriklen id of the SFE.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return list<array<string,mixed>>  Raw MAT_Jordstykke nodes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails or a page is malformed.
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
	 * Return the ejerlav of parcels.
	 *
	 * @param  list<array<string,mixed>>  $parcels  Raw MAT_Jordstykke nodes.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @return array<string,array<string,mixed>>  Raw MAT_Ejerlav nodes keyed by id_lokalId.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails or a page is malformed.
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
	 * @param  string  $root  Connection name.
	 * @param  string  $subject  What the lookup asks for, such as "BFE 123"; names it in the exception message.
	 * @param  callable(?string):string  $queryBuilder  Cursor-aware query builder whose nodes select id_lokalId and status.
	 * @return array<string,mixed>|null  The node, or null when no current version matches.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails, a response is malformed, or several current objects match.
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
	 * @param  string  $root  Connection name.
	 * @param  string  $subject  What the lookup asks for; names it in the exception message.
	 * @param  list<array<string,mixed>>  $nodes  Nodes of the lookup.
	 * @return array<string,mixed>|null  The node, or null when no current version is left.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When several different current nodes remain, or a status is not a scalar.
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
	 * Return the nodes of every page of one Matriklen connection.
	 *
	 * @param  string  $root  Connection name.
	 * @param  callable(?string):string  $queryBuilder  Builder of the query document for one page; its argument is the cursor of the previous page, null for the first.
	 * @return list<array<string,mixed>>  Nodes in response order.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails, or a page is malformed or announces a next page without a cursor.
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

	/**
	 * Build the query document for one page of the SFE with a BFE number.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
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

	/**
	 * Build the query document for one page of the building on foreign ground (area) with a BFE number.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
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

	/**
	 * Build the query document for one page of the building on foreign ground (point) with a BFE number.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
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

	/**
	 * Build the query document for one page of the owner apartment with a BFE number.
	 *
	 * @param  int  $bfe  BFE number.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
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

	/**
	 * Build the query document for one page of the building on foreign ground (area) with a Matriklen id.
	 *
	 * @param  string  $id  Matriklen id.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When the id cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the building on foreign ground (point) with a Matriklen id.
	 *
	 * @param  string  $id  Matriklen id.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When the id cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of the parcels of an SFE.
	 *
	 * @param  string  $sfeId  Matriklen id of the SFE.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When the id cannot be encoded as a GraphQL string.
	 */
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

	/**
	 * Build the query document for one page of ejerlav by id.
	 *
	 * @param  list<string>  $ids  At most 100 Matriklen ids of ejerlav.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 * @throws \InvalidArgumentException  When the list is empty or an id cannot be encoded as a GraphQL string.
	 */
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
	 * Return the pageInfo selection of a connection.
	 *
	 * @return string
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
	 * Encode a string as a GraphQL string literal.
	 *
	 * @param  string  $value
	 * @return string  A JSON string literal, which is also a GraphQL string literal.
	 * @throws \InvalidArgumentException  When the string cannot be encoded as JSON, such as invalid UTF-8.
	 */
	private static function gqlString(string $value): string {
		try {
			return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
		} catch (\JsonException) {
			throw new \InvalidArgumentException('GraphQL string value cannot be encoded.');
		}
	}

	/**
	 * Encode a non-empty list of strings as a GraphQL list literal.
	 *
	 * @param  list<string>  $values
	 * @return string
	 * @throws \InvalidArgumentException  When the list is empty or a value cannot be encoded.
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
	 * Tell whether a Matriklen status marks the current version of an object.
	 *
	 * Behavior:
	 * - True for Gaeldende in any letter case, after trimming. A Danish letter counts as its
	 *   two-letter spelling (ae, oe, aa), so the spelling with the Danish letter matches too.
	 *
	 * @param  string  $status
	 * @return bool
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
	 *
	 * @param  int|string  $value  Surrounding whitespace of a string is ignored.
	 * @param  string  $label  Name of the value in exception messages.
	 * @return int  At least 1.
	 * @throws \InvalidArgumentException  When the value is not such an integer or exceeds the int range.
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
	 * Normalize one matrikelnummer.
	 *
	 * @param  string  $value  Surrounding whitespace is ignored.
	 * @return string  The trimmed matrikelnummer.
	 * @throws \InvalidArgumentException  When it is empty, longer than 64 bytes or holds a control character.
	 */
	private static function normalizeLandParcelIdentifier(string $value): string {
		$value = \trim($value);
		if ($value === '' || \strlen($value) > 64 || \preg_match('/[\x00-\x1F\x7F]/', $value)) {
			throw new \InvalidArgumentException('Land parcel identifier must be a non-empty printable string of at most 64 bytes.');
		}

		return $value;
	}

	/**
	 * Normalize an upstream scalar to trimmed text, or null.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @return string|null  Null for null and for text that is empty after trimming.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is neither null nor a string, int or float.
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
	 * Normalize a required upstream scalar to trimmed, non-empty text.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $field  Name of the field in exception messages.
	 * @return string
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing, empty after trimming, or not a string, int or float.
	 */
	private static function requiredString(mixed $value, string $field): string {
		$value = self::nullableString($value);
		if ($value === null) {
			throw new InvalidResponseException($field . ' is missing or empty.');
		}

		return $value;
	}

	/**
	 * Normalize an upstream integer, or null.
	 *
	 * @param  mixed  $value  Raw upstream value: An int, or a string of an integer.
	 * @return int|null  Null for null and for an empty string.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not an integer in the int range.
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
	 * Normalize a required upstream integer.
	 *
	 * @param  mixed  $value  Raw upstream value: An int, or a string of an integer.
	 * @param  string  $field  Name of the field in exception messages.
	 * @return int
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing or not an integer in the int range.
	 */
	private static function requiredInt(mixed $value, string $field): int {
		$value = self::nullableInt($value);
		if ($value === null) {
			throw new InvalidResponseException($field . ' is missing.');
		}

		return $value;
	}

	/**
	 * Return the current time in UTC as a Datafordeler effect time.
	 *
	 * @return string  Such as 2026-01-31T12:00:00Z.
	 */
	private static function now(): string {
		return \gmdate('Y-m-d\TH:i:s\Z');
	}
}
