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
 * BbrSchemaComparator: Util that compares two BBR type maps.
 *
 * Gives the schema check one definition of drift between a bundled and a remote schema. Reading a
 * type map from a schema document belongs to BbrSchemaDocument.
 *
 * Rules:
 * - Pure: Every result depends only on the arguments.
 */
class BbrSchemaComparator {

	/**
	 * Compare a bundled and a remote BBR type map.
	 *
	 * Behavior:
	 * - new_types lists the types that only the remote map has, removed_types those that only the
	 *   bundled map has.
	 * - changed_types holds each type of both maps whose fields differ: added_fields only the remote
	 *   type has, removed_fields only the bundled type has, and changed_field_types have another type
	 *   in each.
	 * - has_drift is true when any of them is not empty.
	 * - Every list and map is sorted by name.
	 *
	 * Typical usage:
	 *   $diff = BbrSchemaComparator::compare($bundled, $remote);
	 *
	 * @param  array<string,array<string,string>>  $bundled  Type map of the bundled schema.
	 * @param  array<string,array<string,string>>  $remote  Type map of the remote schema.
	 * @return array{
	 *     has_drift:bool,
	 *     new_types:list<string>,
	 *     removed_types:list<string>,
	 *     changed_types:array<string,array{
	 *         added_fields:array<string,string>,
	 *         removed_fields:array<string,string>,
	 *         changed_field_types:array<string,array{bundled:string,remote:string}>
	 *     }>
	 * }  Field types keyed by field name.
	 * @see \CitOmni\DanishPublicSectorData\Util\BbrSchemaDocument::types()  The shape of a type map.
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
