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
 * Cfg: Test double for CitOmni\Kernel\Cfg with the kernel's read semantics.
 *
 * Gives code under test the kernel's cfg reads over a plain array, without loading
 * citomni/kernel.
 *
 * Rules:
 * - Mirror the kernel's read semantics; a difference makes a suite pass for code that fails
 *   in an app.
 */
final class Cfg {

	/**
	 * Wrap one configuration node.
	 *
	 * @param  array<string,mixed>  $data  Node data.
	 */
	public function __construct(private readonly array $data) {
	}


	/**
	 * Read one key of this node.
	 *
	 * @param  string  $key
	 * @return mixed  A nested node for an empty or associative array; a list or scalar as it is.
	 * @throws \OutOfBoundsException  When the key does not exist at this node.
	 * @see \CitOmni\Kernel\Cfg::__get()  The behavior this mirrors.
	 */
	public function __get(string $key): mixed {
		if (!\array_key_exists($key, $this->data)) {
			throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
		}

		$value = $this->data[$key];

		return \is_array($value) && ($value === [] || !\array_is_list($value)) ? new self($value) : $value;
	}


	/**
	 * Tell whether the key exists at this node.
	 *
	 * Behavior:
	 * - Decides isset() and ?? on the key, so both are true for a key whose value is null.
	 *
	 * @param  string  $key
	 * @return bool
	 * @see \CitOmni\Kernel\Cfg::__isset()  The behavior this mirrors.
	 */
	public function __isset(string $key): bool {
		return \array_key_exists($key, $this->data);
	}


	/**
	 * Return the node's data as a plain array.
	 *
	 * @return array<string,mixed>
	 * @see \CitOmni\Kernel\Cfg::toArray()  The behavior this mirrors.
	 */
	public function toArray(): array {
		return $this->data;
	}

}
