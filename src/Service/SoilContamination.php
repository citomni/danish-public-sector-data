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
use CitOmni\DanishPublicSectorData\Support\DkJordWfsClient;
use CitOmni\Kernel\Service\BaseService;

/**
 * SoilContamination: Read DKjord soil-contamination classifications for Danish cadastral parcels.
 *
 * Behavior:
 * - Reads the public DKjord WFS layers used for parcel-linked soil-contamination classifications.
 * - Matches matrikelnumre exactly inside DKjord's semicolon-separated Lokalitetsmatrikler field.
 * - Resolves a BFE number to its current physical cadastral parcels through Datafordeleren's Matriklen data.
 * - Returns explicit WFS layer classifications instead of translating legacy Parcel API status codes.
 *
 * Notes:
 * - Classification is derived from WFS layer membership, not from locality-level descriptive status text.
 * - No geometry is downloaded; the DKjord client requests only parcel-linkage metadata.
 * - Owner apartments and buildings on foreign ground inherit the physical parcel scope of the underlying SFE.
 * - A successful lookup with no matching DKjord feature returns hasDkJordMatch=false rather than null.
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

	/** @var array<string,string> */
	private const array DKJORD_LAYERS = [
		'localized' => 'DKJord:View_LokaliseretFlader',
		'v1' => 'DKJord:View_V1Flader',
		'v2' => 'DKJord:View_V2Flader',
		'removed_after_mapping' => 'DKJord:View_UEKFlader',
		'removed_before_mapping' => 'DKJord:View_UIKFlader',
	];

	/** @var array<string,true> */
	private const array DKJORD_CERTIFICATE_LAYERS = [
		'v1' => true,
		'v2' => true,
	];

	private DatafordelerClient $datafordeler;
	private DkJordWfsClient $dkJordWfs;
	private string $matrikelService;
	private string $matrikelVersion;

	/** @var array<int,array<string,list<array<string,mixed>>>> */
	private array $dkJordDistrictCache = [];

	/**
	 * Initialize the Matriklen and DKjord WFS clients.
	 *
	 * @return void
	 * @throws \UnexpectedValueException When package configuration is incomplete.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->soil_contamination;

		$this->matrikelService = (string)($cfg->matrikel_service ?? '');
		$this->matrikelVersion = (string)($cfg->matrikel_version ?? '');

		if ($this->matrikelService === '' || $this->matrikelVersion === '') {
			throw new \UnexpectedValueException('Soil contamination Matriklen service and version must be configured.');
		}

		$this->datafordeler = new DatafordelerClient($this->app);
		$this->dkJordWfs = new DkJordWfsClient($this->app);
	}

	/**
	 * Return DKjord WFS classifications for one exact cadastral parcel.
	 *
	 * @param int|string $cadastralDistrictIdentifier Official ejerlav identifier.
	 * @param string $landParcelIdentifier Exact matrikelnummer within the cadastral district.
	 * @return array<string,mixed> Normalized parcel classification result.
	 */
	public function getByParcel(int|string $cadastralDistrictIdentifier, string $landParcelIdentifier): array {
		$cadastralDistrictIdentifier = self::normalizePositiveInt(
			$cadastralDistrictIdentifier,
			'Cadastral district identifier'
		);
		$landParcelIdentifier = self::normalizeLandParcelIdentifier($landParcelIdentifier);

		return self::normalizeWfsParcel(
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
	 * Resolve one BFE number to physical cadastral parcels and attach current DKjord WFS classifications.
	 *
	 * @param int|string $bfeNumber Positive BFE number.
	 * @return array<string,mixed>|null Normalized property parcel scope, or null when the BFE is not found in current Matriklen data.
	 */
	public function getByBfe(int|string $bfeNumber): ?array {
		$scope = $this->getParcelsByBfe($bfeNumber);
		if ($scope === null) {
			return null;
		}

		$normalizedParcels = [];
		$propertyClassifications = [];

		foreach ($scope['parcels'] as $parcel) {
			$contamination = $this->getByParcel(
				$parcel['cadastralDistrictIdentifier'],
				$parcel['landParcelIdentifier']
			);

			foreach ($contamination['classifications'] as $classification) {
				$propertyClassifications[$classification] = true;
			}

			$parcel['contamination'] = $contamination;
			$normalizedParcels[] = $parcel;
		}

		$classifications = self::orderedClassifications($propertyClassifications);
		$scope['hasDkJordMatch'] = $classifications !== [];
		$scope['classifications'] = $classifications;
		$scope['fetchedAt'] = self::now();
		$scope['parcels'] = $normalizedParcels;

		return $scope;
	}

	/**
	 * Load and cache all configured DKjord classification layers for one ejerlav.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function loadDkJordDistrict(int $cadastralDistrictIdentifier): array {
		if (isset($this->dkJordDistrictCache[$cadastralDistrictIdentifier])) {
			return $this->dkJordDistrictCache[$cadastralDistrictIdentifier];
		}

		$layers = [];
		foreach (self::DKJORD_LAYERS as $classification => $typeName) {
			$layers[$classification] = $this->dkJordWfs->getFeatures(
				$typeName,
				$cadastralDistrictIdentifier,
				isset(self::DKJORD_CERTIFICATE_LAYERS[$classification])
			);
		}

		$this->dkJordDistrictCache[$cadastralDistrictIdentifier] = $layers;

		return $layers;
	}

	/**
	 * Normalize exact parcel matches from DKjord WFS layer results.
	 *
	 * @param array<string,list<array<string,mixed>>> $layers Features keyed by package classification.
	 * @return array<string,mixed>
	 */
	private static function normalizeWfsParcel(
		int $cadastralDistrictIdentifier,
		string $landParcelIdentifier,
		array $layers
	): array {
		$matchedClassifications = [];
		$registrations = [];
		$registrationKeys = [];
		$certificateUrls = [];

		foreach (self::DKJORD_LAYERS as $classification => $_typeName) {
			$features = $layers[$classification] ?? [];
			if (!\is_array($features)) {
				throw new InvalidResponseException('DKjord WFS layer result must be an array.');
			}

			foreach ($features as $feature) {
				if (!\is_array($feature) || !\is_array($feature['properties'] ?? null)) {
					throw new InvalidResponseException('DKjord WFS layer contains an invalid feature.');
				}

				$properties = $feature['properties'];
				$district = self::requiredInt(
					$properties['Lokalitetsejerlavkode'] ?? null,
					'DKjord Lokalitets-ejerlavkode'
				);
				if ($district !== $cadastralDistrictIdentifier) {
					throw new InvalidResponseException('DKjord WFS returned a feature outside the requested cadastral district.');
				}

				$parcelList = self::splitSemicolonList(self::nullableString($properties['Lokalitetsmatrikler'] ?? null));
				if (!\in_array($landParcelIdentifier, $parcelList, true)) {
					continue;
				}

				$matchedClassifications[$classification] = true;
				$locationReference = self::nullableString($properties['Lokalitetsnr'] ?? null);
				$guid = self::nullableString($properties['GUID'] ?? null);
				$certificateUrl = self::findCertificateUrl(
					self::nullableString($properties['Jordforureningsattester'] ?? null),
					$cadastralDistrictIdentifier,
					$landParcelIdentifier
				);

				if ($certificateUrl !== null) {
					$certificateUrls[$certificateUrl] = true;
				}

				$key = $classification . "\0" . ($locationReference ?? '') . "\0" . ($certificateUrl ?? '');
				if (isset($registrationKeys[$key])) {
					continue;
				}
				$registrationKeys[$key] = true;

				$registrations[] = [
					'classification' => $classification,
					'locationReference' => $locationReference,
					'guid' => $guid,
					'certificateUrl' => $certificateUrl,
				];
			}
		}

		$classifications = self::orderedClassifications($matchedClassifications);
		$certificateUrls = \array_keys($certificateUrls);
		\sort($certificateUrls, \SORT_STRING);

		return [
			'cadastralDistrictIdentifier' => $cadastralDistrictIdentifier,
			'landParcelIdentifier' => $landParcelIdentifier,
			'hasDkJordMatch' => $classifications !== [],
			'classifications' => $classifications,
			'registrations' => $registrations,
			'certificateUrls' => $certificateUrls,
			'fetchedAt' => self::now(),
		];
	}

	/**
	 * Return matched classifications in the package's deterministic layer order.
	 *
	 * @param array<string,bool> $matched Classifications keyed by identifier.
	 * @return list<string>
	 */
	private static function orderedClassifications(array $matched): array {
		$result = [];

		foreach (self::DKJORD_LAYERS as $classification => $_typeName) {
			if (isset($matched[$classification])) {
				$result[] = $classification;
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
	 * Find the DKjord certificate URL that belongs to one exact parcel.
	 */
	private static function findCertificateUrl(
		?string $value,
		int $cadastralDistrictIdentifier,
		string $landParcelIdentifier
	): ?string {
		foreach (self::splitSemicolonList($value) as $url) {
			$query = \parse_url($url, \PHP_URL_QUERY);
			if (!\is_string($query) || $query === '') {
				continue;
			}

			$params = [];
			\parse_str($query, $params);
			if (
				(string)($params['elav'] ?? '') === (string)$cadastralDistrictIdentifier
				&& (string)($params['matrnr'] ?? '') === $landParcelIdentifier
			) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * Resolve the BFE type and the SFE whose parcels define its physical ground scope.
	 *
	 * @return array{propertyType:string,propertyId:string,underlyingSfeId:?string}|null
	 */
	private function resolveMatrikelProperty(int $bfe, string $effectiveAt): ?array {
		$sfe = $this->fetchOne(
			'MAT_SamletFastEjendom',
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
			fn(?string $after): string => $this->buildBpfgFladeByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($bpfgFlade !== null) {
			return $this->normalizeBpfgProperty($bpfgFlade, 'MAT_BygningPaaFremmedGrundFlade');
		}

		$bpfgPunkt = $this->fetchOne(
			'MAT_BygningPaaFremmedGrundPunkt',
			fn(?string $after): string => $this->buildBpfgPunktByBfeQuery($bfe, $effectiveAt, $after)
		);
		if ($bpfgPunkt !== null) {
			return $this->normalizeBpfgProperty($bpfgPunkt, 'MAT_BygningPaaFremmedGrundPunkt');
		}

		$ownerApartment = $this->fetchOne(
			'MAT_Ejerlejlighed',
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
					fn(?string $after): string => $this->buildBpfgFladeByIdQuery($bpfgFladeId, $effectiveAt, $after)
				);
				$sfeId = \is_array($parent)
					? self::nullableString($parent['samletFastEjendomLokalId'] ?? null)
					: null;
			} elseif ($bpfgPunktId !== null) {
				$parent = $this->fetchOne(
					'MAT_BygningPaaFremmedGrundPunkt',
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
			if ($status !== null && !self::isCurrentParcelStatus($status)) {
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
	 * Fetch zero or one current node from one connection.
	 */
	private function fetchOne(string $root, callable $queryBuilder): ?array {
		$rows = $this->fetchAll($root, $queryBuilder);
		if ($rows === []) {
			return null;
		}
		if (\count($rows) !== 1) {
			throw new InvalidResponseException('Datafordeler returned multiple current nodes for unique ' . $root . ' lookup.');
		}

		return $rows[0];
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
	 * Return whether one Matriklen parcel status represents a current parcel.
	 */
	private static function isCurrentParcelStatus(string $status): bool {
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
