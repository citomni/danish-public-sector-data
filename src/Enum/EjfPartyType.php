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
 * EjfPartyType: Enum of the kinds of party in an administration of Ejerfortegnelsen (EJF).
 *
 * The one vocabulary for who administers and who is administered, so lookups, stored data and
 * host apps agree on it. Which EJF fields make a party of each kind belongs to the Ejf service.
 *
 * Rules:
 * - Never rename or remove a backing value: Host apps store and transmit them.
 */
enum EjfPartyType: string {

	/** A person with a CPR number. */
	case Person = 'person';

	/** A company with a CVR number. */
	case Company = 'company';

	/** A production unit of a company, known by its P-number only. */
	case ProductionUnit = 'production_unit';

	/** A party without CPR or CVR number, registered by a municipality, such as a foreign party or an estate. */
	case Other = 'other';

	/** A party that EJF does not identify. */
	case Unknown = 'unknown';

}
