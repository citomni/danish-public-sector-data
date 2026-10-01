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

$check(BbrCodeLists::SNAPSHOT_DATE === '2026-09-30', 'Primary code-list snapshot date is explicit.');
$check(BbrCodeLists::VERIFIED_SOURCE_CHECK_DATE === '2026-10-01', 'Individually verified BBR Teknik source date is explicit.');
$check(BbrCodeLists::SUPPLEMENT_COVERAGE_DATE === '2026-02-24', 'Supplement coverage date is explicit and separate.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '5') === 'Tegl', 'Roof material code 5 resolves to Tegl.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '80') === '(UDFASES) Ingen', 'Deprecated roof material remains historically resolvable.');
$check(BbrCodeLists::label('Vandforsyning', '2') === 'Privat vandforsyningsanlæg', 'Water supply code 2 resolves.');
$check(BbrCodeLists::label('Afloebsforhold', '10') === 'Afløb til offentligt kloaksystem', 'Drainage code 10 resolves.');
$check(BbrCodeLists::label('MedlemsskabAfSplidevandforsyning', '2') === 'Medlemskab af spildevandsforsyning', 'Wastewater membership code 2 resolves.');
$check(BbrCodeLists::label('Rensningspaabud', '5') === 'Rensning skal forbedres til O', 'Wastewater improvement order code 5 resolves.');
$check(BbrCodeLists::label('Rensningspaabud', '6') === 'Skal tilsluttes spildevandsforsyningsselskab', 'Supplemented wastewater order code 6 resolves.');
$check(BbrCodeLists::label('Rensningspaabud', '7') === 'Skal tilsluttes separatkloakering', 'Supplemented wastewater order code 7 resolves.');
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
$check(BbrCodeLists::label('Placering', '0') === 'Ukendt', 'Supplemented technical placement code 0 resolves.');
$check(BbrCodeLists::label('Placering', '2') === 'Over terræn, udendørs', 'Technical placement code 2 resolves.');
$check(BbrCodeLists::label('Placering', '3') === 'Indendørs', 'Technical placement code 3 resolves.');
$check(BbrCodeLists::label('Indhold', '12') === 'Fyringsgasolie', 'Tank content code 12 resolves.');
$check(BbrCodeLists::label('Driftstatus', '2') === 'Ikke i drift', 'Operating status code 2 resolves.');
$check(BbrCodeLists::label('Elevator', '0') === 'Der er ikke elevator i opgangen', 'Elevator code 0 resolves.');
$check(BbrCodeLists::label('Elevator', '1') === 'Der er elevator i opgangen', 'Elevator code 1 resolves.');
$check(BbrCodeLists::label('EtageType', '0') === 'Ikke tagetage', 'Primary floor type code 0 remains authoritative.');
$check(BbrCodeLists::label('EtageType', '1') === 'Tagetage', 'Floor type code 1 resolves.');
$check(BbrCodeLists::label('EtageType', '2') === 'Kælder', 'Supplemented floor type code 2 resolves.');
$check(BbrCodeLists::label('HusnummerRolle', '0') === 'Fastsat til denne', 'House-number role code 0 resolves.');
$check(BbrCodeLists::label('HusnummerRolle', '1') === 'Kun vejledende', 'House-number role code 1 resolves.');
$check(BbrCodeLists::label('Sloejfning', '1') === 'Tanken er afblændet', 'Tank decommission code 1 resolves.');
$check(BbrCodeLists::label('Livscyklus', '10') === 'Historisk', 'Lifecycle code 10 resolves.');
$check(BbrCodeLists::label('AsbestholdigtMateriale', '1') === 'Asbestholdigt ydervægsmateriale', 'Supplemented asbestos code list resolves.');
$check(BbrCodeLists::label('Stoerrelsesklasse', '1') === 'Under 6.000 l', 'Supplemented tank size class resolves.');
$check(BbrCodeLists::label('Udlejningsforhold', '1') === 'Udlejet', 'Supplemented rental-status code list resolves.');
$check(BbrCodeLists::label('KvalitetAfKoordinatsaet', '1') === 'Sikker geokodning', 'Supplemented coordinate-quality code list resolves.');
$check(BbrCodeLists::label('AdresseRolle', '0') === 'Fastsat til denne', 'Verified address role code 0 resolves.');
$check(BbrCodeLists::label('AdresseRolle', '1') === 'Kun vejledende', 'Verified address role code 1 resolves.');
$check(BbrCodeLists::label('BeregningsprincipForArealAfCarport', '1') === 'Carportareal er målt efter tagflade', 'Verified carport area principle code 1 resolves.');
$check(BbrCodeLists::label('BeregningsprincipForArealAfCarport', '2') === 'Carportarealet er målt ½ meter inde på åbne sider', 'Verified carport area principle code 2 resolves.');
$check(BbrCodeLists::label('Konstruktion', '1') === 'Åben konstruktion', 'Verified construction code 1 resolves.');
$check(BbrCodeLists::label('Konstruktion', '2') === 'Lukket konstruktion', 'Verified construction code 2 resolves.');
$check(BbrCodeLists::label('Konstruktionsforhold', '1') === 'Bygningen har jernbetonskelet', 'Verified construction condition code 1 resolves.');
$check(BbrCodeLists::label('Konstruktionsforhold', '2') === 'Bygningen har ikke jernbetonskelet', 'Verified construction condition code 2 resolves.');
$check(BbrCodeLists::label('Materiale', '1') === 'Plast', 'Verified technical material code 1 resolves.');
$check(BbrCodeLists::label('Materiale', '2') === 'Stål', 'Verified technical material code 2 resolves.');
$check(BbrCodeLists::label('Materiale', '3') === 'Plasttank med udvendig stålvæg', 'Verified technical material code 3 resolves.');
$check(BbrCodeLists::label('TypeAfVaegge', '1') === 'Enkeltvægget', 'Verified wall type code 1 resolves.');
$check(BbrCodeLists::label('TypeAfVaegge', '2') === 'Dobbeltvægget', 'Verified wall type code 2 resolves.');
$check(BbrCodeLists::label('TypeAfVaegge', '3') === 'Dobbeltvægget med overvågning', 'Verified wall type code 3 resolves.');
$check(BbrCodeLists::label('TypeAfVaegge', '4') === 'Overjordisk anlæg, hele anlægget er tilgængeligt for udvendig visuel inspektion', 'Verified wall type code 4 resolves.');
$check(BbrCodeLists::label('TypeAfVaegge', '5') === 'Tanke som er installeret før 1970, udvendig korrosionsbeskyttelse med bitumenbelægning', 'Verified wall type code 5 resolves.');

$reflection = new \ReflectionClass(BbrCodeLists::class);
$primaryLists = $reflection->getConstant('LISTS');
$verifiedLists = $reflection->getConstant('VERIFIED_LISTS');
$supplementLists = $reflection->getConstant('SUPPLEMENT_LISTS');

$check(\is_array($primaryLists), 'Primary code-list snapshot remains an array.');
$check(\is_array($verifiedLists), 'Individually verified code lists are an array.');
$check(\is_array($supplementLists), 'Supplement code-list snapshot is an array.');

$verifiedCodeCount = 0;
$verifiedOverlap = [];
$mergedLists = $primaryLists;

foreach ($verifiedLists as $list => $codes) {
	foreach ($codes as $code => $label) {
		++$verifiedCodeCount;

		if ((isset($primaryLists[$list]) && \array_key_exists($code, $primaryLists[$list]))
			|| (isset($supplementLists[$list]) && \array_key_exists($code, $supplementLists[$list]))) {
			$verifiedOverlap[] = $list . ':' . $code;
		}

		$mergedLists[$list][$code] = $label;
	}
}

$supplementCodeCount = 0;
$supplementOverlap = [];

foreach ($supplementLists as $list => $codes) {
	foreach ($codes as $code => $label) {
		++$supplementCodeCount;

		if (isset($primaryLists[$list]) && \array_key_exists($code, $primaryLists[$list])) {
			$supplementOverlap[] = $list . ':' . $code;
		}

		$mergedLists[$list][$code] = $label;
	}
}

$mergedCodeCount = 0;
foreach ($mergedLists as $codes) {
	$mergedCodeCount += \count($codes);
}

$check($verifiedOverlap === [], 'Individually verified lists do not duplicate primary or KDS supplement list/code pairs.');
$check($supplementOverlap === [], 'Older supplement contains no list/code pair from the primary snapshot.');
$check(\count($verifiedLists) === 6, 'Individually verified source covers the expected 6 code lists.');
$check($verifiedCodeCount === 16, 'Individually verified source covers the expected 16 list/code pairs.');
$check(\count($supplementLists) === 45, 'Supplement contains the expected 45 affected code lists.');
$check($supplementCodeCount === 363, 'Supplement contains the expected 363 missing list/code pairs.');
$check(\count($mergedLists) === 78, 'Bundled lookup covers 78 code lists across all sources.');
$check($mergedCodeCount === 954, 'Bundled lookup covers 954 unique list/code pairs across all sources.');

$bbrSource = \file_get_contents($root . '/src/Service/Bbr.php');
$fieldCatalogSource = \file_get_contents($root . '/src/Util/BbrFieldCatalog.php');
$check($bbrSource !== false, 'BBR service source is readable for code-list usage coverage.');
$check($fieldCatalogSource !== false, 'BBR field catalog source is readable for code-list usage coverage.');

\preg_match_all("/codeLabel\\(\\s*'([^']+)'/", (string)$bbrSource, $serviceMatches);
\preg_match_all("/'codeList' => '([^']+)'/", (string)$fieldCatalogSource, $catalogMatches);
$referencedLists = \array_values(\array_unique(\array_merge(
	$serviceMatches[1] ?? [],
	$catalogMatches[1] ?? []
)));
\sort($referencedLists, \SORT_STRING);

$availableLists = \array_fill_keys(\array_keys($mergedLists), true);
$missingReferencedLists = \array_values(\array_filter(
	$referencedLists,
	static fn(string $list): bool => !isset($availableLists[$list])
));

$check($missingReferencedLists === [], 'Every code list referenced by BBR normalization is bundled.');
$check(BbrCodeLists::label('UnknownList', '1') === null, 'Unknown code-list names safely return null.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', '9999') === null, 'Unknown code values safely return null.');
$check(BbrCodeLists::label('Tagdaekningsmateriale', null) === null, 'Null code values remain null.');

\fwrite(
	STDOUT,
	'PASS ' . $checks . ' checks. Bundled BBR code-list snapshot ' . BbrCodeLists::SNAPSHOT_DATE . '. PHP '
	. \PHP_VERSION . '.' . \PHP_EOL
);
