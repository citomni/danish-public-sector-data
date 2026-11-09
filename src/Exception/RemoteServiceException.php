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

namespace CitOmni\DanishPublicSectorData\Exception;

/**
 * RemoteServiceException: Exception for a failure returned by, or while contacting, a remote service.
 *
 * Lets a caller tell a request that failed from an answer that cannot be interpreted, and read the
 * HTTP status when the service answered.
 */
class RemoteServiceException extends PublicDataException {

	/** HTTP status of the answer, or null when the request failed before an answer. */
	private readonly ?int $statusCode;

	/**
	 * Create the exception with the HTTP status of the answer.
	 *
	 * @param  string  $message  Message without credentials or raw response content.
	 * @param  int|null  $statusCode  HTTP status, or null when there was no answer.
	 */
	public function __construct(string $message, ?int $statusCode = null) {
		parent::__construct($message);

		$this->statusCode = $statusCode;
	}

	/**
	 * Return the HTTP status of the answer.
	 *
	 * @return int|null  Null for a failure before an answer, such as a transport failure.
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;
	}
}
