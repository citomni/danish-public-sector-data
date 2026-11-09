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

namespace CitOmni\DanishPublicSectorData\Enum;


/**
 * DatafordelerAuthentication: Enum of the ways the package authenticates with Datafordeler.
 *
 * Lets each service choose the credentials that its data needs: access-restricted data, such as
 * Ejerfortegnelsen, does not accept an API key. How a credential is read and sent belongs to
 * DatafordelerClient.
 */
enum DatafordelerAuthentication {

	/** The API key of the IT-system, sent as the apiKey query parameter. */
	case ApiKey;

	/** The Shared Secret of the IT-system: An OAuth access token from its client id and secret, sent as a bearer token. */
	case SharedSecret;

}
