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

namespace CitOmni\DanishPublicSectorData\Command;

use CitOmni\DanishPublicSectorData\Operation\CheckBbrSchema;
use CitOmni\Kernel\Command\BaseCommand;

/**
 * BbrSchemaCheckCommand: CLI adapter that checks the live Datafordeler BBR schemas for drift.
 *
 * Lets operators and scripts see whether the BBR schemas that Datafordeler publishes still match
 * the schemas the package bundles. It prints the result and maps it to an exit code; the
 * comparison belongs to CheckBbrSchema.
 *
 * Rules:
 * - Check only through CheckBbrSchema.
 */
class BbrSchemaCheckCommand extends BaseCommand {

	/**
	 * Declare the command interface.
	 *
	 * @return array{arguments?: array<string,array<string,mixed>>, options?: array<string,array<string,mixed>>}
	 * @see \CitOmni\Kernel\Command\BaseCommand::signature()  The signature format.
	 */
	protected function signature(): array {
		return [
			'arguments' => [],
			'options' => [
				'scope' => ['type' => 'string', 'default' => 'all', 'allowed' => ['all', 'current', 'history'], 'description' => 'Check all, current or history BBR endpoints'],
				'json' => ['type' => 'bool', 'description' => 'Emit compact JSON on stdout'],
			],
		];
	}

	/**
	 * Check the endpoints in --scope and report the result.
	 *
	 * Behavior:
	 * - Exit code 0 when CheckBbrSchema finds no drift, 1 when it reports drift.
	 * - With --json: One JSON line on stdout with the result of CheckBbrSchema. Otherwise a report
	 *   per endpoint and a result line.
	 *
	 * @return int  Process exit code.
	 * @see \CitOmni\DanishPublicSectorData\Operation\CheckBbrSchema::execute()  The result behind the exit code and the JSON.
	 */
	protected function execute(): int {
		$result = (new CheckBbrSchema($this->app))->execute($this->getString('scope'));

		if ($this->getBool('json')) {
			$this->stdout(\json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
			return $result['status'] === CheckBbrSchema::RESULT_OK ? self::SUCCESS : self::FAILURE;
		}

		$this->writeHumanResult($result);

		return $result['status'] === CheckBbrSchema::RESULT_OK ? self::SUCCESS : self::FAILURE;
	}

	/**
	 * Print the report of every checked endpoint and the result line.
	 *
	 * @param  array{status:string,checks:list<array<string,mixed>>}  $result  Result of CheckBbrSchema::execute().
	 * @return void
	 */
	private function writeHumanResult(array $result): void {
		$this->stdout('BBR schema drift check');
		$this->stdout('');

		foreach ($result['checks'] as $check) {
			$this->stdout(\ucfirst((string)$check['scope']) . ' endpoint: ' . $check['service'] . '/' . $check['version']);
			$this->stdout('Bundled schema: ' . $check['bundled_schema'] . ' (' . $check['bundled_version'] . ')');
			$this->stdout('BBR domain types: ' . $check['remote_type_count'] . ' remote / ' . $check['bundled_type_count'] . ' bundled');
			$this->stdout('Status: ' . (($check['has_drift'] ?? false) ? 'DRIFT' : 'OK'));

			if (($check['version_mismatch'] ?? false) === true) {
				$this->stdout('  ! Configured endpoint version differs from bundled schema version.');
			}

			$this->writeTypeList('New remote types', $check['new_types'] ?? [], '+');
			$this->writeTypeList('Missing remote types', $check['removed_types'] ?? [], '-');
			$this->writeChangedTypes($check['changed_types'] ?? []);
			$this->stdout('');
		}

		if ($result['status'] === CheckBbrSchema::RESULT_OK) {
			$this->success('Result: No BBR schema drift detected.');
			return;
		}

		$this->warning('Result: BBR schema drift detected. Review the differences before updating bundled schemas or selections.');
	}

	/**
	 * Print a labelled list of type names; nothing for an empty list.
	 *
	 * @param  string  $label  Heading of the list.
	 * @param  list<string>  $types  Type names.
	 * @param  string  $prefix  Marker before each name, such as + or -.
	 * @return void
	 */
	private function writeTypeList(string $label, array $types, string $prefix): void {
		if ($types === []) {
			return;
		}

		$this->stdout($label . ':');
		foreach ($types as $type) {
			$this->stdout('  ' . $prefix . ' ' . $type);
		}
	}

	/**
	 * Print the added, removed and changed fields of each changed type.
	 *
	 * @param  array<string,array{added_fields?:array<string,string>,removed_fields?:array<string,string>,changed_field_types?:array<string,array{bundled:string,remote:string}>}>  $changedTypes  Changes keyed by type name.
	 * @return void
	 */
	private function writeChangedTypes(array $changedTypes): void {
		foreach ($changedTypes as $typeName => $changes) {
			$this->stdout('Changed type: ' . $typeName);

			foreach (($changes['added_fields'] ?? []) as $fieldName => $fieldType) {
				$this->stdout('  + ' . $fieldName . ': ' . $fieldType);
			}
			foreach (($changes['removed_fields'] ?? []) as $fieldName => $fieldType) {
				$this->stdout('  - ' . $fieldName . ': ' . $fieldType);
			}
			foreach (($changes['changed_field_types'] ?? []) as $fieldName => $types) {
				$this->stdout('  ~ ' . $fieldName . ': ' . $types['bundled'] . ' -> ' . $types['remote']);
			}
		}
	}
}
