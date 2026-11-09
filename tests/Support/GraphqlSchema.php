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

namespace CitOmni\DanishPublicSectorData\Tests\Support;

/**
 * GraphqlSchema: Test support that checks GraphQL query documents against a schema document.
 *
 * Lets a suite prove without a request that every query document a service sends uses only
 * fields, arguments, filter fields and filter operators that the schema defines, and count the
 * joins of a document. It reads the SDL as Datafordeler generates it, and the query documents
 * as the package builds them: No fragments, aliases or variables. The limits a document must
 * stay within belong to the suite.
 *
 * Rules:
 * - Report a document that does not fit the schema with CheckFailed, so the case fails with the
 *   reason as its message.
 *
 * Typical usage:
 *   $schema = GraphqlSchema::fromFile($root . '/resources/bbr/FLEX_CURRENT_V003.schema.graphql');
 *   $stats = $schema->check($document);
 */
class GraphqlSchema {

	/** @var array<string,array<string,array{type:string,arguments:string}>> Fields of each object type: The named type without list and non-null markers, and the arguments as written. */
	private array $types = [];

	/** @var array<string,array<string,array{type:string,arguments:string}>> Fields of each input type, in the same form. */
	private array $inputs = [];


	/**
	 * Read the object and input types of a schema document.
	 *
	 * @param  string  $source  GraphQL SDL as Datafordeler generates it.
	 */
	public function __construct(string $source) {
		$source = (string)\preg_replace('/""".*?"""\s*/s', '', $source);
		\preg_match_all('/^(type|input) ([A-Za-z0-9_]+)(?:\s+implements\s+[^\{]+)?\s*\{\R(.*?)^\}/ms', $source, $blocks, \PREG_SET_ORDER);

		foreach ($blocks as $block) {
			foreach (\preg_split('/\R/', $block[3]) ?: [] as $line) {
				if (!\preg_match('/^\s{2}([A-Za-z_][A-Za-z0-9_]*)(\(.*\))?:\s*([^ @]+)/', $line, $field)) {
					continue;
				}

				$definition = ['type' => \trim($field[3], '[]!'), 'arguments' => $field[2]];
				if ($block[1] === 'type') {
					$this->types[$block[2]][$field[1]] = $definition;
				} else {
					$this->inputs[$block[2]][$field[1]] = $definition;
				}
			}
		}
	}


	/**
	 * Read a schema document from a file.
	 *
	 * @param  string  $path  Path of a GraphQL SDL file.
	 * @return self
	 * @throws \RuntimeException  When the file cannot be read.
	 */
	public static function fromFile(string $path): self {
		$source = \is_file($path) ? \file_get_contents($path) : false;
		if ($source === false) {
			throw new \RuntimeException('Cannot read the schema document ' . $path);
		}

		return new self($source);
	}


	/**
	 * Tell whether an object type of the schema has a field.
	 *
	 * @param  string  $type  Object type, such as Query.
	 * @param  string  $field
	 * @return bool
	 */
	public function hasField(string $type, string $field): bool {
		return isset($this->types[$type][$field]);
	}


	/**
	 * Tell whether an input type of the schema has a field.
	 *
	 * @param  string  $input  Input type, such as CVR_ProduktionsenhedFilterInput.
	 * @param  string  $field
	 * @return bool
	 */
	public function hasInputField(string $input, string $field): bool {
		return isset($this->inputs[$input][$field]);
	}


	/**
	 * Check one query document against the schema and count its joins.
	 *
	 * Behavior:
	 * - Every field must exist on its type, and only fields of an object type have a selection
	 *   set.
	 * - Every argument must exist on its field. Inside where, every filter field must exist on
	 *   the filter input of the field, and every operator on the input type of that filter field.
	 * - A join is a field whose name ends in _ref. joins counts them in the whole document, and
	 *   depth is the longest chain of joins nested in each other.
	 *
	 * @param  string  $document  GraphQL query document.
	 * @return array{operation:string,roots:list<string>,joins:int,depth:int}  operation is the name after query, or '' without one; roots lists the root fields.
	 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When the document cannot be parsed or does not fit the schema.
	 */
	public function check(string $document): array {
		\preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[A-Za-z_][A-Za-z0-9_]*|\S/', $document, $matches);
		$tokens = $matches[0];
		$position = \array_search('{', $tokens, true);
		if (!\is_int($position)) {
			throw new CheckFailed('The query document has no selection set.');
		}

		$operation = $position === 2 && $tokens[0] === 'query' ? $tokens[1] : '';
		$fields = $this->selectionSet($tokens, $position);
		if ($position !== \count($tokens)) {
			throw new CheckFailed('The query document continues after its selection set.');
		}

		$stats = $this->checkFields($fields, 'Query');

		return [
			'operation' => $operation,
			'roots'     => \array_column($fields, 'name'),
			'joins'     => $stats['joins'],
			'depth'     => $stats['depth'],
		];
	}


	/**
	 * Parse one selection set, starting at its opening brace.
	 *
	 * @param  list<string>  $tokens  Tokens of the document.
	 * @param  int  $position  Index of the opening brace; set to the index after the closing brace.
	 * @return list<array{name:string,arguments:list<string>,children:list<array>}>  Fields of the set.
	 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When the set is malformed.
	 */
	private function selectionSet(array $tokens, int &$position): array {
		if (($tokens[$position] ?? null) !== '{') {
			throw new CheckFailed('Selection set expected at token ' . $position . '.');
		}
		$position++;

		$fields = [];
		while (($tokens[$position] ?? null) !== '}') {
			$name = $tokens[$position++] ?? '';
			if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
				throw new CheckFailed('Field name expected, got "' . $name . '".');
			}

			$arguments = [];
			if (($tokens[$position] ?? null) === '(') {
				$depth = 0;
				do {
					$token = $tokens[$position++] ?? throw new CheckFailed('Unclosed arguments of ' . $name . '.');
					$depth += $token === '(' ? 1 : ($token === ')' ? -1 : 0);
					$arguments[] = $token;
				} while ($depth > 0);
			}

			$children = ($tokens[$position] ?? null) === '{' ? $this->selectionSet($tokens, $position) : [];
			$fields[] = ['name' => $name, 'arguments' => $arguments, 'children' => $children];
		}
		$position++;

		return $fields;
	}


	/**
	 * Check a field tree against the schema and count its joins.
	 *
	 * @param  list<array{name:string,arguments:list<string>,children:list<array>}>  $fields  Field tree.
	 * @param  string  $type  Object type the fields belong to.
	 * @return array{joins:int,depth:int}  Join fields in the tree, and the longest chain of nested joins.
	 * @throws \CitOmni\DanishPublicSectorData\Tests\Support\CheckFailed  When a field, an argument, a filter field or an operator is not in the schema, or a selection set does not fit its type.
	 */
	private function checkFields(array $fields, string $type): array {
		$joins = 0;
		$depth = 0;

		foreach ($fields as $field) {
			$path = $type . '.' . $field['name'];
			$definition = $this->types[$type][$field['name']] ?? null;
			if ($definition === null) {
				throw new CheckFailed('The schema has no field ' . $path . '.');
			}

			// Top-level argument names, the filter fields inside where, and their operators.
			$braces = 0;
			$inWhere = false;
			$filterInput = '';
			$filterType = '';
			foreach ($field['arguments'] as $index => $token) {
				if ($token === '{' || $token === '}') {
					$braces += $token === '{' ? 1 : -1;
					$inWhere = $inWhere && $braces > 0;
					continue;
				}
				if (($field['arguments'][$index + 1] ?? null) !== ':' || !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token)) {
					continue;
				}

				if ($braces === 0) {
					if (!\preg_match('/[(\s]' . $token . ': /', $definition['arguments'])) {
						throw new CheckFailed($path . ' has no argument ' . $token . '.');
					}
					$inWhere = $token === 'where';
					$filterInput = \preg_match('/[(\s]where: ([A-Za-z0-9_]+)/', $definition['arguments'], $match) === 1 ? $match[1] : '';
				} elseif ($inWhere && $braces === 1) {
					$filterType = $this->inputs[$filterInput][$token]['type']
						?? throw new CheckFailed('The where filter of ' . $path . ' has no field ' . $token . '.');
				} elseif ($inWhere && $braces === 2 && !isset($this->inputs[$filterType][$token])) {
					throw new CheckFailed('The where filter of ' . $path . ' has no operator ' . $token . ' in ' . $filterType . '.');
				}
			}

			$isObject = isset($this->types[$definition['type']]);
			if ($isObject === ($field['children'] === [])) {
				throw new CheckFailed($path . ' has a selection set that does not fit its type.');
			}

			$nested = $isObject ? $this->checkFields($field['children'], $definition['type']) : ['joins' => 0, 'depth' => 0];
			$isJoin = \str_ends_with($field['name'], '_ref') ? 1 : 0;
			$joins += $nested['joins'] + $isJoin;
			$depth = \max($depth, $nested['depth'] + $isJoin);
		}

		return ['joins' => $joins, 'depth' => $depth];
	}


}
