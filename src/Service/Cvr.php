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
 * Cvr: Read normalized Danish company data from the official CVR register.
 *
 * Behavior:
 * - Looks up one company by its eight-digit CVR number through Datafordeler flexibleCurrent.
 * - Requests only the fields needed for the normalized company result.
 * - Prefers the registered location address and falls back to the postal address.
 * - Returns null when no matching company exists.
 *
 * Notes:
 * - Datafordeler-specific GraphQL relation names are contained inside this service.
 * - The public result shape is intentionally independent of the upstream GraphQL schema.
 * - Only non-access-restricted company data is queried.
 *
 * Typical usage:
 *   $company = $this->app->cvr->getCompany('12345678');
 *
 * @throws \InvalidArgumentException When the CVR number is not exactly eight digits.
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails.
 */
final class Cvr extends BaseService {

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

		$this->service = (string)($cfg->service ?? '');
		$this->version = (string)($cfg->version ?? '');

		if ($this->service === '' || $this->version === '') {
			throw new \UnexpectedValueException('CVR Datafordeler service and version must be configured.');
		}

		$this->datafordeler = new DatafordelerClient($this->app);
	}

	/**
	 * Return one normalized CVR company record.
	 *
	 * @param string $cvrNumber Exact eight-digit CVR number.
	 * @return array{
	 *     cvrNumber:string,
	 *     name:?string,
	 *     status:?string,
	 *     startDate:?string,
	 *     companyType:array{code:?string,name:?string}|null,
	 *     address:array{
	 *         type:?string,
	 *         formatted:?string,
	 *         careOf:?string,
	 *         street:?string,
	 *         houseNumberFrom:?string,
	 *         houseNumberTo:?string,
	 *         floor:?string,
	 *         door:?string,
	 *         postalCode:?string,
	 *         city:?string,
	 *         supplementaryCity:?string,
	 *         countryCode:?string,
	 *         freeText:?string
	 *     }|null
	 * }|null Normalized company data, or null when the CVR number does not exist.
	 * @throws \InvalidArgumentException When the CVR number is not exactly eight digits.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is malformed.
	 */
	public function getCompany(string $cvrNumber): ?array {
		if (!\preg_match('/^[0-9]{8}$/D', $cvrNumber)) {
			throw new \InvalidArgumentException('CVR number must contain exactly eight digits.');
		}

		$query = $this->buildCompanyQuery($cvrNumber);
		$data  = $this->datafordeler->query($this->service, $this->version, $query);

		$connection = $data['CVR_Virksomhed'] ?? null;
		if (!\is_array($connection)) {
			throw new InvalidResponseException('CVR response does not contain a valid CVR_Virksomhed connection.');
		}

		$nodes = $connection['nodes'] ?? null;
		if (!\is_array($nodes)) {
			throw new InvalidResponseException('CVR response does not contain a valid company node list.');
		}
		if ($nodes === []) {
			return null;
		}

		$company = $nodes[0] ?? null;
		if (!\is_array($company)) {
			throw new InvalidResponseException('CVR response contains an invalid company node.');
		}

		$returnedCvr = self::nullableString($company['CVRNummer'] ?? null);
		if ($returnedCvr !== $cvrNumber) {
			throw new InvalidResponseException('CVR response contains an unexpected CVR number.');
		}

		$entity = $this->firstConnectionNode($company['id_CVR_CVREnhed_id_ref'] ?? null, 'CVR entity relation');

		$name = null;
		$address = null;
		if ($entity !== null) {
			$nameRelation = $entity['id_CVR_Navn_CVREnhedsId_ref'] ?? null;
			if ($nameRelation !== null && !\is_array($nameRelation)) {
				throw new InvalidResponseException('CVR response contains an invalid name relation.');
			}
			$name = \is_array($nameRelation) ? self::nullableString($nameRelation['vaerdi'] ?? null) : null;

			$address = $this->normalizeAddress($entity['id_CVR_Adressering_CVREnhedsId_ref'] ?? null);
		}

		$companyType = $this->normalizeCompanyType($company['id_CVR_Virksomhedsform_CVREnhedsId_ref'] ?? null);

		return [
			'cvrNumber' => $cvrNumber,
			'name' => $name,
			'status' => self::nullableString($company['status'] ?? null),
			'startDate' => self::nullableString($company['virksomhedStartdato'] ?? null),
			'companyType' => $companyType,
			'address' => $address,
		];
	}

	/**
	 * Build the minimal flexibleCurrent query needed by getCompany().
	 *
	 * @param string $cvrNumber Validated eight-digit CVR number.
	 * @return string GraphQL query document.
	 */
	private function buildCompanyQuery(string $cvrNumber): string {
		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');

		return <<<GRAPHQL
query GetCvrCompany {
	CVR_Virksomhed(
		first: 1
		virkningstid: "{$effectiveAt}"
		where: { CVRNummer: { eq: {$cvrNumber} } }
	) {
		nodes {
			status
			CVRNummer
			virksomhedStartdato
			id_CVR_CVREnhed_id_ref(first: 1) {
				nodes {
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
				}
			}
			id_CVR_Virksomhedsform_CVREnhedsId_ref {
				vaerdi
				vaerdiTekst
			}
		}
	}
}
GRAPHQL;
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
	 * Normalize the preferred CVR address from its GraphQL connection.
	 *
	 * @param mixed $relation Raw address relation.
	 * @return array{
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
	 * }|null Normalized address or null when unavailable.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException When the relation shape is invalid.
	 */
	private function normalizeAddress(mixed $relation): ?array {
		if ($relation === null) {
			return null;
		}
		if (!\is_array($relation) || !\is_array($relation['nodes'] ?? null)) {
			throw new InvalidResponseException('CVR response contains an invalid address relation.');
		}

		$preferred = null;
		$fallback = null;

		foreach ($relation['nodes'] as $node) {
			if (!\is_array($node)) {
				throw new InvalidResponseException('CVR response contains an invalid address node.');
			}

			$type = self::nullableString($node['AdresseringAnvendelse'] ?? null);
			if ($type === 'beliggenhedsadresse') {
				$preferred = $node;
				break;
			}
			if ($fallback === null && $type === 'postadresse') {
				$fallback = $node;
			}
		}

		$node = $preferred ?? $fallback;
		if ($node === null) {
			return null;
		}

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
	 * - The upstream `Adresse` field is a DAR address reference, not formatted address text.
	 * - No external address lookup is required.
	 *
	 * @param array{
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
	 * } $address Normalized CVR address fields.
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
