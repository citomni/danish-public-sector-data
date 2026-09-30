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

use CitOmni\DanishPublicSectorData\Util\BbrCodeLists;

$root = \dirname(__DIR__);
require_once $root . '/src/Util/BbrCodeLists.php';

$checks = 0;

$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new \RuntimeException('FAIL: ' . $message);
	}

	++$checks;
};

$check(BbrCodeLists::SNAPSHOT_DATE === '2026-09-30', 'Code-list snapshot date is explicit.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '5') === 'Tegl', 'Roof material code 5 resolves to Tegl.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '80') === '(UDFASES) Ingen', 'Deprecated roof material remains historically resolvable.');
$check(BbrCodeLists::label('Vandforsyning', '2') === 'Privat vandforsyningsanlæg', 'Water supply code 2 resolves.');
$check(BbrCodeLists::label('Afloebsforhold', '10') === 'Afløb til offentligt kloaksystem', 'Drainage code 10 resolves.');
$check(BbrCodeLists::label('Varmeinstallation', '2') === 'Centralvarme med én fyringsenhed', 'Heating installation code 2 resolves.');
$check(BbrCodeLists::label('BygAnvendelse', '120') === 'Fritliggende enfamiliehus', 'Building application code 120 resolves.');
$check(BbrCodeLists::label('BygAnvendelse', '910') === 'Garage', 'Secondary building application code 910 resolves.');
$check(BbrCodeLists::label('EnhAnvendelse', '120') === 'Fritliggende enfamiliehus', 'Unit application code 120 resolves.');
$check(BbrCodeLists::label('Boligtype', '1') === 'Egentlig beboelseslejlighed med eget køkken', 'Housing type code 1 resolves.');
$check(BbrCodeLists::label('Toiletforhold', 'T') === 'Vandskyllende toilet i enheden', 'Toilet code T resolves.');
$check(BbrCodeLists::label('Badeforhold', 'V') === 'Badeværelse i enheden', 'Bath code V resolves.');
$check(BbrCodeLists::label('Koekkenforhold', 'E') === 'Eget køkken med afløb', 'Kitchen code E resolves.');
$check(BbrCodeLists::label('Kommunekode', '0810') === 'Brønderslev Kommune', 'Municipality code 0810 resolves.');
$check(BbrCodeLists::label('Kommunekode', '0615') === 'Horsens Kommune', 'Municipality code 0615 resolves.');
$check(BbrCodeLists::label('Klassifikation', '1110') === 'Tank', 'Technical classification 1110 resolves.');
$check(BbrCodeLists::label('Placering', '3') === 'Indendørs', 'Technical placement code 3 resolves.');
$check(BbrCodeLists::label('Indhold', '12') === 'Fyringsgasolie', 'Tank content code 12 resolves.');
$check(BbrCodeLists::label('Driftstatus', '2') === 'Ikke i drift', 'Operating status code 2 resolves.');
$check(BbrCodeLists::label('Elevator', '0') === 'Der er ikke elevator i opgangen', 'Elevator code 0 resolves.');
$check(BbrCodeLists::label('Elevator', '1') === 'Der er elevator i opgangen', 'Elevator code 1 resolves.');
$check(BbrCodeLists::label('EtageType', '0') === 'Ikke tagetage', 'Floor type code 0 resolves.');
$check(BbrCodeLists::label('EtageType', '1') === 'Tagetage', 'Floor type code 1 resolves.');
$check(BbrCodeLists::label('HusnummerRolle', '0') === 'Fastsat til denne', 'House-number role code 0 resolves.');
$check(BbrCodeLists::label('HusnummerRolle', '1') === 'Kun vejledende', 'House-number role code 1 resolves.');
$check(BbrCodeLists::label('Sloejfning', '1') === 'Tanken er afblændet', 'Tank decommission code 1 resolves.');
$check(BbrCodeLists::label('Livscyklus', '10') === 'Historisk', 'Lifecycle code 10 resolves.');
$check(BbrCodeLists::label('UnknownList', '1') === null, 'Unknown code-list names safely return null.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '9999') === null, 'Unknown code values safely return null.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', null) === null, 'Null code values remain null.');

\fwrite(
	STDOUT,
	'PASS ' . $checks . ' checks. Bundled BBR code-list snapshot ' . BbrCodeLists::SNAPSHOT_DATE . '. PHP '
	. \PHP_VERSION . '.' . \PHP_EOL
);
