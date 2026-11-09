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
 * BaseService: Test double for CitOmni\Kernel\Service\BaseService.
 *
 * Gives Services under test the constructor contract of the kernel base without loading
 * citomni/kernel.
 *
 * Rules:
 * - Mirror the kernel base wherever code under test can tell.
 */
abstract class BaseService {

	/** The app the service works with. */
	protected App $app;

	/** @var array<string,mixed> Service options, as a service map entry would give them. */
	protected array $options;


	/**
	 * Keep the app and the options, then run the subclass's init() when it has one.
	 *
	 * @param  App  $app  App double.
	 * @param  array<string,mixed>  $options  Service options.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  The constructor this mirrors.
	 */
	public function __construct(App $app, array $options = []) {
		$this->app = $app;
		$this->options = $options;

		if (\method_exists($this, 'init')) {
			$this->init();
		}
	}

}
