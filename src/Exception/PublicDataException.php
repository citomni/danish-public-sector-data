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
 * PublicDataException: Base exception of the package's public-sector data lookups.
 *
 * Exists so that a caller can catch the failures of a lookup in one catch, whichever source the
 * lookup reads. Which subclass describes a failure belongs to the code that throws it; invalid
 * method input is not a lookup failure and stays \InvalidArgumentException.
 *
 * Rules:
 * - Never carry a credential, a raw response body or a request URL, which may hold an API key, in
 *   a message or a property.
 */
class PublicDataException extends \RuntimeException {
}
