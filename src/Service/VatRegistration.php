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
 * VatRegistration: Service that tells whether a CVR/SE number is registered for VAT in Denmark.
 *
 * Gives host apps a small status contract that does not depend on how SKAT is asked. The answer
 * comes from SKAT's public web lookup, which is no documented API and may be replaced; the web flow
 * belongs to SkatVatWebClient.
 *
 * Rules:
 * - Fail rather than guess when SKAT's answer is not decisive.
 * - Keep the number as queried: A CVR number can have separate SE numbers for VAT.
 */
class VatRegistration extends BaseService {

	/** Client of SKAT's web lookup. */
	private SkatVatWebClient $skatVatWeb;

	/**
	 * Create the client of SKAT's web lookup.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.skat_vat_web is invalid.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$this->skatVatWeb = new SkatVatWebClient($this->app);
	}

	/**
	 * Return the current VAT registration of one CVR/SE number.
	 *
	 * Typical usage:
	 *   $status = $this->app->vatRegistration->getStatus('12345678');
	 *
	 * @param  string  $registrationNumber  CVR/SE number of exactly eight digits.
	 * @return array{registrationNumber:string,registered:bool,verifiedOn:?string,source:string}  verifiedOn is the verification date that SKAT shows, as YYYY-MM-DD; source is skat.dk.
	 * @throws \InvalidArgumentException  When the number is not exactly eight digits.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When the lookup fails or SKAT's answer is not decisive.
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
	 * Require a CVR/SE number of exactly eight digits.
	 *
	 * @param  string  $registrationNumber
	 * @return void
	 * @throws \InvalidArgumentException  When the number has another form.
	 */
	private function validateRegistrationNumber(string $registrationNumber): void {
		if (!\preg_match('/^[0-9]{8}$/D', $registrationNumber)) {
			throw new \InvalidArgumentException('CVR/SE number must contain exactly eight digits.');
		}
	}
}
