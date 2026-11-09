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
 * InvalidResponseException: Exception for a remote answer that cannot be interpreted safely.
 *
 * Lets a caller tell an answer that the package will not guess about, such as a malformed document
 * or an ambiguous value, from a request that failed.
 */
class InvalidResponseException extends PublicDataException {
}
