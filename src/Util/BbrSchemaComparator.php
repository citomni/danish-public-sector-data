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
 * Compare parsed BBR GraphQL schema type maps.
 *
 * Behavior:
 * - Reports added/removed BBR types, added/removed fields and changed field types.
 * - Operates on normalized type maps produced by BbrSchemaDocument.
 *
 * Notes:
 * - This utility is pure and performs no IO.
 * - Pagination wrapper filtering belongs to BbrSchemaDocument.
 *
 * Typical usage:
 *   $diff = BbrSchemaComparator::compare($bundled, $remote);
 */
final class BbrSchemaComparator {

	/**
	 * Compare bundled and remote BBR type maps.
	 *
	 * @param array<string,array<string,string>> $bundled Bundled type map.
	 * @param array<string,array<string,string>> $remote Remote type map.
	 * @return array{
	 *   has_drift:bool,
	 *   new_types:list<string>,
	 *   removed_types:list<string>,
	 *   changed_types:array<string,array{
	 *     added_fields:array<string,string>,
	 *     removed_fields:array<string,string>,
	 *     changed_field_types:array<string,array{bundled:string,remote:string}>
	 *   }>
	 * }
	 */
	public static function compare(array $bundled, array $remote): array {
		$newTypes = \array_keys(\array_diff_key($remote, $bundled));
		$removedTypes = \array_keys(\array_diff_key($bundled, $remote));
		\sort($newTypes, \SORT_STRING);
		\sort($removedTypes, \SORT_STRING);

		$changedTypes = [];
		foreach (\array_intersect(\array_keys($bundled), \array_keys($remote)) as $typeName) {
			$bundledFields = $bundled[$typeName];
			$remoteFields = $remote[$typeName];

			$addedFields = \array_diff_key($remoteFields, $bundledFields);
			$removedFields = \array_diff_key($bundledFields, $remoteFields);
			$changedFieldTypes = [];

			foreach (\array_intersect(\array_keys($bundledFields), \array_keys($remoteFields)) as $fieldName) {
				if ($bundledFields[$fieldName] === $remoteFields[$fieldName]) {
					continue;
				}

				$changedFieldTypes[$fieldName] = [
					'bundled' => $bundledFields[$fieldName],
					'remote' => $remoteFields[$fieldName],
				];
			}

			\ksort($addedFields, \SORT_STRING);
			\ksort($removedFields, \SORT_STRING);
			\ksort($changedFieldTypes, \SORT_STRING);

			if ($addedFields === [] && $removedFields === [] && $changedFieldTypes === []) {
				continue;
			}

			$changedTypes[$typeName] = [
				'added_fields' => $addedFields,
				'removed_fields' => $removedFields,
				'changed_field_types' => $changedFieldTypes,
			];
		}

		\ksort($changedTypes, \SORT_STRING);

		return [
			'has_drift' => $newTypes !== [] || $removedTypes !== [] || $changedTypes !== [],
			'new_types' => $newTypes,
			'removed_types' => $removedTypes,
			'changed_types' => $changedTypes,
		];
	}
}
