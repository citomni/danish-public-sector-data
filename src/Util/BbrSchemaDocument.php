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

namespace CitOmni\DanishPublicSectorData\Util;

/**
 * BbrSchemaDocument: Util that reads the BBR object types of a GraphQL schema document.
 *
 * Turns a schema into the type map that BbrSchemaComparator compares. It reads the SDL as
 * Datafordeler generates it; what a difference between two maps means belongs to the caller.
 *
 * Rules:
 * - Pure: Every result depends only on the arguments.
 */
class BbrSchemaDocument {

	/**
	 * Return the fields of every BBR object type in a schema document.
	 *
	 * Behavior:
	 * - Covers every object type whose name starts with BBR_, apart from the Connection and Edge
	 *   types of pagination.
	 * - Keeps the type of each field as written, such as Long or [String!]. Descriptions, arguments
	 *   and directives are left out.
	 * - Types and fields are sorted by name.
	 *
	 * Typical usage:
	 *   $types = BbrSchemaDocument::types($schemaSource);
	 *
	 * @param  string  $schemaSource  GraphQL SDL as Datafordeler generates it.
	 * @return array<string,array<string,string>>  Field types keyed by type name and field name.
	 * @throws \UnexpectedValueException  When the source is empty, its descriptions cannot be removed, or it holds no BBR object type.
	 */
	public static function types(string $schemaSource): array {
		if ($schemaSource === '') {
			throw new \UnexpectedValueException('BBR schema source cannot be empty.');
		}

		$withoutDescriptions = \preg_replace('/""".*?"""\s*/s', '', $schemaSource);
		if ($withoutDescriptions === null) {
			throw new \UnexpectedValueException('BBR schema descriptions could not be removed.');
		}

		if (!\preg_match_all('/^type (BBR_[A-Za-z0-9_]+)(?:\s+implements\s+[^\{]+)?\s*\{\R(.*?)^\}/ms', $withoutDescriptions, $matches, \PREG_SET_ORDER)) {
			throw new \UnexpectedValueException('BBR schema contains no parseable BBR object types.');
		}

		$types = [];
		foreach ($matches as $match) {
			$typeName = $match[1];
			if (\str_ends_with($typeName, 'Connection') || \str_ends_with($typeName, 'Edge')) {
				continue;
			}

			$fields = [];

			foreach (\preg_split('/\R/', $match[2]) ?: [] as $line) {
				if (!\preg_match('/^\s{2}([A-Za-z_][A-Za-z0-9_]*)(?:\(.*\))?:\s*([^ @]+)/', $line, $field)) {
					continue;
				}

				$fields[$field[1]] = $field[2];
			}

			\ksort($fields, \SORT_STRING);
			$types[$typeName] = $fields;
		}

		\ksort($types, \SORT_STRING);

		return $types;
	}
}
