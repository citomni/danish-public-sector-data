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
 * Declare this provider package's boot contributions.
 *
 * CitOmni reads these constants when composing the active HTTP or CLI app.
 * All supported contribution constants are documented below but intentionally
 * left undeclared. Uncomment only those the concrete package actually needs.
 *
 * Behavior:
 * - MAP_COMMON contributes services shared by HTTP and CLI.
 * - MAP_HTTP and MAP_CLI contribute mode-specific services.
 * - CFG_COMMON contributes configuration shared by HTTP and CLI.
 * - CFG_HTTP and CFG_CLI contribute mode-specific configuration.
 * - ROUTES_HTTP contributes HTTP dispatch entries.
 * - COMMANDS_CLI contributes CLI dispatch entries.
 *
 * Notes:
 * - Service definitions may be an FQCN string or an array containing "class"
 *   and optional "options".
 * - Within one provider, mode-specific service definitions override shared
 *   service definitions with the same service ID.
 * - Within one provider, mode-specific configuration is merged after shared
 *   configuration, so mode-specific values win on conflicting associative keys.
 * - HTTP routes and CLI commands are dispatch maps, not configuration values.
 * - Keep SQL in Repositories and transport concerns in Controllers or Commands.
 */
final class Registry {

	/**
	 * Services available in both HTTP and CLI mode.
	 *
	 * Typical entries use either a service class directly or a class/options
	 * definition. Services are resolved lazily through the App service map.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	public const array MAP_COMMON = [
		'bbr' => \CitOmni\DanishPublicSectorData\Service\Bbr::class,
		'soilContamination' => \CitOmni\DanishPublicSectorData\Service\SoilContamination::class,
		'cvr' => \CitOmni\DanishPublicSectorData\Service\Cvr::class,
		'vatRegistration' => \CitOmni\DanishPublicSectorData\Service\VatRegistration::class,
	];

	/**
	 * Services available only in HTTP mode.
	 *
	 * Use this only when a service genuinely depends on the HTTP runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_HTTP = [
	// ];

	/**
	 * Services available only in CLI mode.
	 *
	 * Use this only when a service genuinely depends on the CLI runtime.
	 * Definitions here take precedence over MAP_COMMON for the same service ID.
	 *
	 * @var array<string, class-string|array{class: class-string, options?: array<array-key, mixed>}>
	 */
	// public const array MAP_CLI = [
	// ];

	/**
	 * Configuration defaults shared by HTTP and CLI mode.
	 *
	 * Keep package-owned defaults under package-specific keys. Concrete host apps
	 * may override provider configuration through the normal CitOmni config flow.
	 *
	 * @var array<string|int, mixed>
	 */
	public const array CFG_COMMON = [
		'danish_public_sector_data' => [
			'datafordeler' => [
				'base_url' => 'https://graphql.datafordeler.dk',
				'timeout' => 15,
				'connect_timeout' => 5,
			],
			'dkjord_wfs' => [
				'base_url' => 'https://jord.miljoeportal.dk/geo/wfs',
				'timeout' => 15,
				'connect_timeout' => 5,
			],
			'skat_vat_web' => [
				'search_url' => 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg',
				'timeout' => 15,
				'connect_timeout' => 5,
			],
			'bbr' => [
				'current_service' => 'flexibleCurrent',
				'current_version' => 'v3',
				'history_service' => 'BBR',
				'history_version' => 'v3',
			],
			'soil_contamination' => [
				'matrikel_service' => 'flexibleCurrent',
				'matrikel_version' => 'v3',
			],
			'cvr' => [
				'service' => 'flexibleCurrent',
				'version' => 'v3',
			],
		],
	];

	/**
	 * Configuration defaults used only in HTTP mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_HTTP = [
	// ];

	/**
	 * Configuration defaults used only in CLI mode.
	 *
	 * These values are merged after CFG_COMMON and therefore win on conflicting
	 * associative keys within this provider.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array CFG_CLI = [
	// ];

	/**
	 * HTTP route dispatch entries contributed by this provider.
	 *
	 * Keep route definitions here rather than inside CFG_COMMON or CFG_HTTP.
	 * The concrete route entry contract is owned by the CitOmni HTTP layer.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array ROUTES_HTTP = [
	// ];

	/**
	 * CLI command dispatch entries contributed by this provider.
	 *
	 * Keep command definitions here rather than inside MAP_CLI, CFG_COMMON, or
	 * CFG_CLI. The concrete command entry contract is owned by the CitOmni CLI layer.
	 *
	 * @var array<string|int, mixed>
	 */
	// public const array COMMANDS_CLI = [
	// ];


}
