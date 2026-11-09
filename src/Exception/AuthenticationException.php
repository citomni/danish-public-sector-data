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
 * AuthenticationException: Exception for credentials that are missing, empty, rejected or insufficient for the requested data.
 *
 * Lets a caller tell a credential or access problem of the host app, which a retry does not fix,
 * from other failures of a remote service.
 */
class AuthenticationException extends RemoteServiceException {
}
