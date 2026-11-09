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
 * RateLimitException: Exception for a request that a remote service refused because of rate limiting.
 *
 * Lets a caller tell a refusal that waiting may lift from other failures of a remote service.
 */
class RateLimitException extends RemoteServiceException {
}
