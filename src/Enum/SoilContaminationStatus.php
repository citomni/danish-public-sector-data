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
 * SoilContaminationStatus: Enum of the soil-contamination status that DKjord registers on a parcel.
 *
 * The one vocabulary for a parcel's status under the Danish soil contamination act, so lookups,
 * stored snapshots and host apps agree on it. Which DKjord text maps to which case belongs to
 * the SoilContamination service; how a status is shown belongs to the host app.
 *
 * Rules:
 * - Never rename or remove a backing value: Host apps store and transmit them.
 */
enum SoilContaminationStatus: string {

	/** Mapped on knowledge level 1 (V1): Activities that may have contaminated the parcel are known. */
	case V1 = 'v1';

	/** Mapped on knowledge level 2 (V2): Contamination that can harm people or the environment is documented. */
	case V2 = 'v2';

	/** Mapped on both knowledge level 1 and knowledge level 2. */
	case V1AndV2 = 'v1_v2';

	/** Localized (uafklaret): Information about the parcel is registered but not yet assessed for mapping. */
	case Localized = 'localized';

	/** Removed after mapping: The parcel was mapped on V1 or V2 and is no longer. */
	case RemovedAfterMapping = 'removed_after_mapping';

	/** Removed before mapping: The parcel was assessed and released without being mapped. */
	case RemovedBeforeMapping = 'removed_before_mapping';

}
