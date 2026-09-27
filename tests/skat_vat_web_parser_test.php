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

use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Support\SkatVatWebClient;

$root = \dirname(__DIR__);
require_once $root . '/src/Exception/PublicDataException.php';
require_once $root . '/src/Exception/InvalidResponseException.php';
require_once $root . '/src/Support/SkatVatWebClient.php';

$client = (new \ReflectionClass(SkatVatWebClient::class))->newInstanceWithoutConstructor();
$checks = 0;

$invoke = static function (string $method, mixed ...$arguments) use ($client): mixed {
	return (new \ReflectionMethod(SkatVatWebClient::class, $method))->invoke($client, ...$arguments);
};

$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new \RuntimeException('FAIL: ' . $message);
	}
	++$checks;
};

$formHtml = <<<'HTML'
<!doctype html>
<html lang="da">
<body>
<form method="post" action="/ntse-front/public/momsnummer/soeg;jsessionid=SECRET?execution=e1s1">
	<input type="hidden" name="flowToken" value="opaque-state">
	<label for="i1">Søg på cvr-/se-nummer</label>
	<input type="radio" name="sogCVRSENummer" id="i1" value="CVRSENummer1" checked>
	<label for="virksomhedCVRNummer">CVR-/SE-nummer:</label>
	<input type="text" name="virksomhedCVRNummer.cvrNummer" id="virksomhedCVRNummer" value="">
	<input type="submit" name="_eventId_sogDKMomsNumreEvent" id="Sog1" value="Søg">
	<label for="i2">Søg på virksomhedens navn og/eller adresse</label>
	<input type="radio" name="sogCVRSENummer" id="i2" value="CVRSENummer2">
	<label for="virksomhedNavnFirmaNavnKort">Firmanavn</label>
	<input type="text" name="virksomhedNavnFirmaNavnKort.navn" id="virksomhedNavnFirmaNavnKort" value="">
	<input type="submit" name="_eventId_sogDKMomsNumreEvent" id="Sog2" value="Søg">
</form>
</body>
</html>
HTML;

try {
	$form = $invoke('parseSearchForm', $formHtml, 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg?execution=e1s1', '40386270');
} catch (InvalidResponseException $exception) {
	throw new \RuntimeException('FAIL: Search form fixture was rejected: ' . $exception->getMessage(), 0, $exception);
}

$check($form['method'] === 'POST', 'Search form method is preserved.');
$check($form['action'] === 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg?execution=e1s1', 'jsessionid is removed from the form action.');
$check(($form['fields']['flowToken'] ?? null) === 'opaque-state', 'Hidden flow state is preserved.');
$check(($form['fields']['sogCVRSENummer'] ?? null) === 'CVRSENummer1', 'CVR/SE search mode is selected instead of name/address search.');
$check(($form['fields']['virksomhedCVRNummer.cvrNummer'] ?? null) === '40386270', 'Queried CVR/SE number is submitted in the discovered field.');
$check(($form['fields']['_eventId_sogDKMomsNumreEvent'] ?? null) === 'Søg', 'Search submit control is preserved.');

$positive = 'Listen indeholder momsregistrerede virksomheder. Verifikationsdato: 27-09-2026 Virksomheden er momsregistreret CVR-/SE-nummer Firmanavn Adresse 40 38 62 70 Malmkjær ApS Clasonsborgvej 1A, 6933 Kibæk';
$negative = 'Der er ingen momsregistrerede virksomheder på det, du søgte. Specificer eventuelt din søgning. [TSE711]. [TSE7110]';
$generic = 'Søg på navn, adresse, cvr- eller se-nummer, for at se om en virksomhed er momsregistreret.';

$check($invoke('registrationState', $positive, '40386270') === true, 'Observed positive SKAT result resolves to registered=true.');
$check($invoke('verificationDate', $positive) === '2026-09-27', 'Observed SKAT verification date is normalized to ISO format.');
$check($invoke('registrationState', $negative, '40912282') === false, 'Observed negative SKAT result resolves to registered=false.');
$check($invoke('verificationDate', $negative) === null, 'Negative result without a verification date stays null.');
$check($invoke('registrationState', $generic, '40386270') === null, 'Generic explanatory text is not treated as a decisive result.');
$check($invoke('registrationState', 'Virksomheden er momsregistreret', '40386270') === null, 'Positive wording without the queried number is not sufficient.');

\fwrite(STDOUT, 'PASS ' . $checks . ' checks. SKAT VAT web form and result parser fixtures. PHP ' . \PHP_VERSION . '.' . \PHP_EOL);
