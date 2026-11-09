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

namespace CitOmni\DanishPublicSectorData\Tests\Support\Kernel;

/**
 * App: Test double for CitOmni\Kernel\App with the cfg and the services that a case registers.
 *
 * Lets a suite run code under test without booting an app: No config files, no providers and
 * no service map.
 *
 * Rules:
 * - Hold only what the case declares, so code under test reads exactly the cfg keys and
 *   services the case gives it.
 * - Behave like the kernel App wherever code under test can tell; a difference makes a suite
 *   pass for code that fails in an app.
 *
 * Typical usage:
 *   $app = new App(['danish_public_sector_data' => ['cvr' => ['service' => 'flexibleCurrent', 'version' => 'v3']]]);
 *   $app->set('curl', $curl);
 */
final class App {

	/** Configuration of this app. */
	public readonly Cfg $cfg;

	/** @var array<string,object> Registered services by id. */
	private array $services = [];


	/**
	 * Create an app with the given configuration and no services.
	 *
	 * @param  array<string,mixed>  $cfg  Configuration, as the kernel would have merged it.
	 */
	public function __construct(array $cfg = []) {
		$this->cfg = new Cfg($cfg);
	}


	/**
	 * Register a service instance under its service id.
	 *
	 * @param  string  $id  Service id, such as curl.
	 * @param  object  $service
	 * @return void
	 */
	public function set(string $id, object $service): void {
		$this->services[$id] = $service;
	}


	/**
	 * Resolve a registered service.
	 *
	 * An unregistered id throws, so a case that registers no curl service shows that the code
	 * under test sends no request.
	 *
	 * @param  string  $id  Service id.
	 * @return object
	 * @throws \RuntimeException  When no service is registered under the id.
	 * @see \CitOmni\Kernel\App::__get()  The behavior this mirrors.
	 */
	public function __get(string $id): object {
		return $this->services[$id] ?? throw new \RuntimeException("Unknown app component: app->{$id}");
	}


	/**
	 * Tell whether a service is registered under the id.
	 *
	 * @param  string  $id  Service id.
	 * @return bool
	 * @see \CitOmni\Kernel\App::hasService()  The behavior this mirrors.
	 */
	public function hasService(string $id): bool {
		return isset($this->services[$id]);
	}

}
