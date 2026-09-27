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
use CitOmni\DanishPublicSectorData\Support\SkatVatWebClient;
use CitOmni\Kernel\Service\BaseService;

/**
 * VatRegistration: Check public Danish VAT-registration status.
 *
 * Behavior:
 * - Looks up one exact eight-digit CVR/SE number through SKAT's anonymous public lookup.
 * - Returns a small normalized current-status contract.
 * - Fails when the temporary web integration cannot determine the status explicitly.
 *
 * Notes:
 * - The current implementation is a temporary web fallback, not a documented SKAT API contract.
 * - Consumers depend only on this service so the transport can later be replaced transparently.
 * - A CVR number may have separate administrative SE units; the queried number is preserved exactly.
 *
 * Typical usage:
 *   $status = $this->app->vatRegistration->getStatus('12345678');
 *
 * @throws \InvalidArgumentException When the CVR/SE number is not exactly eight digits.
 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is inconclusive.
 */
final class VatRegistration extends BaseService {

	private SkatVatWebClient $skatVatWeb;

	/**
	 * Create the internal SKAT web client for this service instance.
	 *
	 * @return void
	 */
	protected function init(): void {
		$this->skatVatWeb = new SkatVatWebClient($this->app);
	}

	/**
	 * Return current VAT-registration status for one CVR/SE number.
	 *
	 * @param string $registrationNumber Exact eight-digit CVR/SE number.
	 * @return array{registrationNumber:string,registered:bool,verifiedOn:?string,source:string} Normalized current status.
	 * @throws \InvalidArgumentException When the CVR/SE number is not exactly eight digits.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException When the remote lookup fails or is inconclusive.
	 */
	public function getStatus(string $registrationNumber): array {
		$this->validateRegistrationNumber($registrationNumber);
		$result = $this->skatVatWeb->lookup($registrationNumber);

		if (!\is_bool($result['registered'])) {
			throw new InvalidResponseException('SKAT VAT lookup did not expose a decisive registration status.');
		}

		return [
			'registrationNumber' => $registrationNumber,
			'registered' => $result['registered'],
			'verifiedOn' => $result['verifiedOn'],
			'source' => 'skat.dk',
		];
	}

	/**
	 * Validate one Danish CVR/SE number without guessing registration semantics.
	 *
	 * @param string $registrationNumber Candidate number.
	 * @return void
	 */
	private function validateRegistrationNumber(string $registrationNumber): void {
		if (!\preg_match('/^[0-9]{8}$/D', $registrationNumber)) {
			throw new \InvalidArgumentException('CVR/SE number must contain exactly eight digits.');
		}
	}
}
