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

namespace CitOmni\DanishPublicSectorData\Tests;

use CitOmni\DanishPublicSectorData\Support\SkatVatWebClient;
use CitOmni\DanishPublicSectorData\Tests\Support\Kernel\App;

/*
 * Isolated suite for the SKAT web flow behind the vatRegistration service: How SkatVatWebClient
 * finds the search form and reads the result page, on fixtures of SKAT's pages. The app double
 * registers no curl service, so no request can be sent.
 *
 * Usage:
 *   php tests/vat-registration/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require \dirname(__DIR__) . '/bootstrap.php';


/** Result page of a number that is registered for VAT, as SKAT showed it. */
const POSITIVE_RESULT = 'Listen indeholder momsregistrerede virksomheder. Verifikationsdato: 27-09-2026 Virksomheden er momsregistreret CVR-/SE-nummer Firmanavn Adresse 40 38 62 70 Malmkjær ApS Clasonsborgvej 1A, 6933 Kibæk';

/** Result page of a number that is not registered for VAT, as SKAT showed it. */
const NEGATIVE_RESULT = 'Der er ingen momsregistrerede virksomheder på det, du søgte. Specificer eventuelt din søgning. [TSE711]. [TSE7110]';


// ----------------------------------------------------------------
// Helpers
// ----------------------------------------------------------------

/**
 * Call a private method of a client built on the SKAT cfg.
 *
 * @param  string  $method
 * @param  mixed  ...$arguments
 * @return mixed
 */
function call(string $method, mixed ...$arguments): mixed {
	static $client = null;
	$client ??= new SkatVatWebClient(new App(['danish_public_sector_data' => ['skat_vat_web' => [
		'search_url'      => 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg',
		'timeout'         => 15,
		'connect_timeout' => 5,
	]]]));

	return (new \ReflectionMethod(SkatVatWebClient::class, $method))->invoke($client, ...$arguments);
}


// ----------------------------------------------------------------
// Cases
// ----------------------------------------------------------------

test('parseSearchForm() chooses the CVR/SE search, keeps the flow state and submits the number in the discovered field', function (): void {
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

	$form = call('parseSearchForm', $formHtml, 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg?execution=e1s1', '40386270');

	check($form['method'] === 'POST', 'Search form method is preserved.');
	check($form['action'] === 'https://ntse.skat.dk/ntse-front/public/momsnummer/soeg?execution=e1s1', 'jsessionid is removed from the form action.');
	check(($form['fields']['flowToken'] ?? null) === 'opaque-state', 'Hidden flow state is preserved.');
	check(($form['fields']['sogCVRSENummer'] ?? null) === 'CVRSENummer1', 'CVR/SE search mode is selected instead of name/address search.');
	check(($form['fields']['virksomhedCVRNummer.cvrNummer'] ?? null) === '40386270', 'Queried CVR/SE number is submitted in the discovered field.');
	check(($form['fields']['_eventId_sogDKMomsNumreEvent'] ?? null) === 'Søg', 'Search submit control is preserved.');
});

test('registrationState() reads SKAT\'s explicit positive and negative results', function (): void {
	check(call('registrationState', POSITIVE_RESULT, '40386270') === true, 'Observed positive SKAT result resolves to registered=true.');
	check(call('registrationState', NEGATIVE_RESULT, '40912282') === false, 'Observed negative SKAT result resolves to registered=false.');
});

test('registrationState() is null for a page that is not decisive', function (): void {
	check(call('registrationState', 'Søg på navn, adresse, cvr- eller se-nummer, for at se om en virksomhed er momsregistreret.', '40386270') === null, 'Generic explanatory text is not treated as a decisive result.');
	check(call('registrationState', 'Virksomheden er momsregistreret', '40386270') === null, 'Positive wording without the queried number is not sufficient.');
});

test('verificationDate() reads the date of a result page as YYYY-MM-DD, and null without one', function (): void {
	check(call('verificationDate', POSITIVE_RESULT) === '2026-09-27', 'Observed SKAT verification date is normalized to ISO format.');
	check(call('verificationDate', NEGATIVE_RESULT) === null, 'Negative result without a verification date stays null.');
});

done();
