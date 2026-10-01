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
 * Parse the BBR object-type surface from one bundled GraphQL SDL document.
 *
 * Behavior:
 * - Extracts every object type whose name starts with "BBR_".
 * - Preserves field names and GraphQL type signatures.
 * - Ignores descriptions, directives and non-BBR schema types.
 *
 * Notes:
 * - This parser targets Datafordeler's generated SDL formatting bundled with this package.
 * - It performs no IO and is safe to use from tests and maintenance operations.
 *
 * Typical usage:
 *   $types = BbrSchemaDocument::types($schemaSource);
 *
 * @param string $schemaSource GraphQL SDL source.
 * @return array<string,array<string,string>> Type name => field name => type signature.
 * @throws \UnexpectedValueException When no BBR object types can be parsed.
 */
final class BbrSchemaDocument {

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
