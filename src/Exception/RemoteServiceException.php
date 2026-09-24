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
 * Represents a failure returned by, or while contacting, a remote public data service.
 *
 * Notes:
 * - Deliberately carries no request URL because authentication values may be URL query parameters.
 * - Deliberately does not expose raw remote response bodies.
 */
class RemoteServiceException extends PublicDataException {

	private readonly ?int $statusCode;

	/**
	 * Create a remote-service exception with safe transport metadata.
	 *
	 * @param string $message Safe exception message without credentials or raw response content.
	 * @param int|null $statusCode HTTP status code when available.
	 */
	public function __construct(string $message, ?int $statusCode = null) {
		parent::__construct($message);

		$this->statusCode = $statusCode;
	}

	/**
	 * Return the HTTP status code when the remote service supplied one.
	 *
	 * @return int|null HTTP status code or null for transport-level failures.
	 */
	public function getStatusCode(): ?int {
		return $this->statusCode;
	}
}
