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
 * EjfOwnerType: Enum of the kinds of owner that an ownership in Ejerfortegnelsen (EJF) has.
 *
 * The one vocabulary for who owns a property, so lookups, stored snapshots and host apps agree
 * on it. Which EJF fields make an owner of each kind belongs to the Ejf service.
 *
 * Rules:
 * - Never rename or remove a backing value: Host apps store and transmit them.
 */
enum EjfOwnerType: string {

	/** A person with a CPR number. */
	case Person = 'person';

	/** A company with a CVR number. */
	case Company = 'company';

	/** An owner without CPR or CVR number, registered by a municipality, such as a foreign owner or an estate. */
	case Other = 'other';

	/** An owner that EJF does not identify. */
	case Unknown = 'unknown';

}
