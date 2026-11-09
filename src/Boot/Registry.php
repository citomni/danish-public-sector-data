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

namespace CitOmni\DanishPublicSectorData\Boot;


/**
 * Registry: Boot contributions of citomni/danish-public-sector-data.
 *
 * Declares what the package adds to a host app as constant arrays: The public-sector data
 * services, their cfg baseline and the BBR schema check command. Merging them with the host
 * app's own maps, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Ship a baseline for every cfg path the package reads, with a comment on what each key
 *   controls and its unit.
 * - Keep the package's cfg under danish_public_sector_data.
 */
class Registry {

	/** Services for HTTP and CLI. */
	public const array MAP_COMMON = [
		'bbr' => \CitOmni\DanishPublicSectorData\Service\Bbr::class,
		'soilContamination' => \CitOmni\DanishPublicSectorData\Service\SoilContamination::class,
		'cvr' => \CitOmni\DanishPublicSectorData\Service\Cvr::class,
		'vatRegistration' => \CitOmni\DanishPublicSectorData\Service\VatRegistration::class,
		'ejf' => \CitOmni\DanishPublicSectorData\Service\Ejf::class,
	];

	// Services for one mode. Not used: every service works in both modes.
	// public const array MAP_HTTP = [];
	// public const array MAP_CLI = [];


	/** Cfg baseline for both modes. */
	public const array CFG_COMMON = [
		'danish_public_sector_data' => [
			'datafordeler' => [
				'base_url'        => 'https://graphql.datafordeler.dk', // Datafordeler GraphQL base URL; must be HTTPS.
				'token_url'       => 'https://auth.datafordeler.dk/realms/distribution/protocol/openid-connect/token', // OAuth token endpoint for Shared Secret clients; must be HTTPS.
				'timeout'         => 15,                                 // Request timeout in seconds, >= 0.
				'connect_timeout' => 5,                                  // Connect timeout in seconds, >= 0.
			],
			'dkjord_wfs' => [
				'base_url'        => 'https://jord.miljoeportal.dk/geo/wfs', // DKjord WFS endpoint of Danmarks Miljoeportal; must be HTTPS.
				'timeout'         => 15,                                     // Request timeout in seconds, > 0.
				'connect_timeout' => 5,                                      // Connect timeout in seconds, > 0.
			],
			'skat_vat_web' => [
				'search_url'      => 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg', // SKAT's public VAT-number search page; must be HTTPS.
				'timeout'         => 15,                                                       // Request timeout in seconds, >= 0.
				'connect_timeout' => 5,                                                        // Connect timeout in seconds, >= 0.
			],
			'bbr' => [
				'current_service' => 'flexibleCurrent', // Datafordeler service for current BBR data; must not be empty.
				'current_version' => 'v3',              // Version of that service; must not be empty.
				'history_service' => 'BBR',             // Datafordeler service for BBR history; must not be empty.
				'history_version' => 'v3',              // Version of that service; must not be empty.
			],
			'soil_contamination' => [
				'matrikel_service' => 'flexibleCurrent', // Datafordeler service for current Matriklen data; must not be empty.
				'matrikel_version' => 'v3',              // Version of that service; must not be empty.
			],
			'cvr' => [
				'service' => 'flexibleCurrent', // Datafordeler service for CVR data; must not be empty.
				'version' => 'v3',              // Version of that service; must not be empty.
			],
			'ejf' => [
				'service' => 'flexibleCurrent', // Datafordeler service for Ejerfortegnelsen (EJF) data; must not be empty.
				'version' => 'v3',              // Version of that service; must not be empty.
			],
		],
	];

	// Not used: the package reads no mode-specific cfg and has no routes.
	// public const array CFG_HTTP = [];
	// public const array CFG_CLI = [];
	// public const array ROUTES_HTTP = [];


	/** CLI commands. */
	public const array COMMANDS_CLI = [
		'bbr:schema-check' => [
			'command' => \CitOmni\DanishPublicSectorData\Command\BbrSchemaCheckCommand::class,
			'description' => 'Compare live Datafordeler BBR schemas with the bundled package schemas.',
		],
	];


}
