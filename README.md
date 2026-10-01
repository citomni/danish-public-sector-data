# Danish Public Sector Data

Reusable CitOmni clients for authoritative data services provided by the Danish public sector.

## Package

`citomni/danish-public-sector-data`

Repository: https://github.com/citomni/danish-public-sector-data

**citomni/danish-public-sector-data** is a CitOmni provider package for PHP 8.5+.
It exposes small, normalized CitOmni services while keeping upstream API protocols,
authentication details, and source-specific data models inside the provider.

The provider currently exposes normalized CVR, BBR, VAT-registration, and soil-contamination capabilities backed by the relevant Danish public-sector sources.

## Requirements

- PHP 8.5+
- Composer
- `citomni/kernel`
- `citomni/infrastructure`
- An enabled `CitOmni\Infrastructure\Boot\Registry` in the host app
- A Datafordeler IT-system with an API key for non-access-restricted data when using Datafordeler-backed lookups

## Install

```bash
composer require citomni/danish-public-sector-data
```

Enable the infrastructure provider before this provider in the host app:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\Infrastructure\Boot\Registry::class,
	\CitOmni\DanishPublicSectorData\Boot\Registry::class,
];
```

## Datafordeler API key

Store the API key in the normal app-local CitOmni secret store. Do not put the
credential in application configuration, templates, JavaScript, or version control.

```php
<?php
declare(strict_types=1);

return [
	'datafordeler.api_key' => 'YOUR_API_KEY',
];
```

The active file is selected by `CITOMNI_ENVIRONMENT`, for example
`var/secrets/app.secret.dev.php` during local development.

### Credential safety

Datafordeleren authenticates API-key requests with the key in the request URL.
The internal Datafordeler client reuses the shared CitOmni `curl` service and marks
`apiKey` through `sensitive_query_keys`. The real value is preserved for the outbound
request, while URLs exposed through Curl metadata, logs, and exceptions are redacted.
Raw transport metadata is not returned by the public CVR service.

## BBR property lookup

The provider exposes `bbr` for current property data from BBR/DAR through Datafordeler.
The service is BFE-centered internally and supports all three BBR property types:

- samlet fast ejendom (SFE)
- bygning på fremmed grund (BPFG)
- ejerlejlighed

Resolve human-entered auction-style addresses separately from loading the property:

```php
$resolution = $this->app->bbr->resolveAddress('Nørmarkvej 29, 2 22, 7600 Struer');

if ($resolution['selectedBfeNumber'] !== null) {
	$property = $this->app->bbr->getPropertyByBfe($resolution['selectedBfeNumber']);
}
```

`resolveAddress()` preserves trailing `m.fl.` as `multiplePropertiesHint=true` and returns
`primary_resolved_scope_incomplete` when the named primary property resolves but the source
explicitly indicates additional auction properties. The service never invents those additional
BFE numbers from the primary address.

Owner-apartment resolution uses the concrete DAR floor/door address and the corresponding BBR
unit/property relation. This allows the selected owner apartment to be distinguished from the
underlying SFE at the same house number.

Current property data is loaded explicitly by BFE:

```php
$property = $this->app->bbr->getPropertyByBfe('4268969');
```

The normalized property graph includes relevant addresses, house numbers, grounds, buildings,
units, floors, entrances, and technical installations. Each physical BBR object includes a
`scope` value so context objects such as an owner apartment's host building or a BPFG property's
underlying ground are not presented as directly belonging to the requested property.

Bundled BBR code-list labels are returned next to their authoritative `...Code` values,
including municipality names, floor types, elevator state, and house-number roles. Unknown codes
keep a null label so consumers can fall back to the raw value without guessing.
`codeListSnapshotDate` identifies the bundled code-list snapshot used for the labels.

Technical-installation history is opt-in:

```php
$history = $this->app->bbr->getTechnicalInstallationHistory('4268969');
```

This keeps normal current-state lookups cheaper while still allowing due-diligence workflows to
inspect historical BBR technical installations such as former tanks.

Address parsing and deterministic fuzzy-street matching have a local smoke test:

```bash
php tests/bbr_address_parser_test.php
```

## Soil contamination lookup

The provider exposes `soilContamination` for structured soil-contamination classifications from DKjord. Parcel lookup uses Danmarks Miljøportal's anonymous WFS endpoint; BFE-centered lookup additionally uses Datafordeleren's current Matriklen data to resolve the physical cadastral parcels belonging to the property.

Lookup one exact cadastral parcel by official ejerlav code and matrikelnummer:

```php
$parcel = $this->app->soilContamination->getByParcel(2005352, '311a');
```

The result always describes the requested parcel after a successful WFS lookup. `hasDkJordMatch=false` and an empty `classifications` list mean that none of the configured DKjord classification layers matched that exact ejerlav/matrikel pair; this must not be reworded as proof that the soil is uncontaminated.

The initial WFS contract classifies exact parcel matches through these public DKjord layers:

- `localized` from `DKJord:View_LokaliseretFlader`
- `v1` from `DKJord:View_V1Flader`
- `v2` from `DKJord:View_V2Flader`
- `removed_after_mapping` from `DKJord:View_UEKFlader`
- `removed_before_mapping` from `DKJord:View_UIKFlader`

Matching is exact inside DKjord's semicolon-separated `Lokalitetsmatrikler` field. The service deliberately derives classification from layer membership rather than legacy Parcel API status codes or locality-level descriptive status text. Geometry is not requested.

For property workflows, resolve the physical parcel scope from a BFE number:

```php
$result = $this->app->soilContamination->getByBfe(3208712);
```

The BFE lookup supports SFE, building-on-foreign-ground, and owner-apartment properties when an underlying SFE exists. It loads current Matriklen parcels, resolves their official ejerlav identifiers, attaches a normalized `contamination` result to every parcel, and returns a property-level union of matching classifications. A property without an underlying SFE can return `parcelResolutionStatus=no_underlying_sfe` without inventing a parcel association.

The local normalization/query-contract test does not make network requests:

```bash
php tests/soil_contamination_test.php
```

## CVR company lookup

The public CVR service intentionally returns a normalized package-owned array
rather than Datafordeler-specific GraphQL relation names.

```php
$company = $this->app->cvr->getCompany('12345678');

if ($company === null) {
	// No matching CVR company exists.
}
```

Result shape:

```php
[
	'cvrNumber' => '12345678',
	'name' => 'Example ApS',
	'status' => 'aktiv',
	'startDate' => '2020-01-01',
	'endDate' => null,
	'companyType' => [
		'code' => '80',
		'name' => 'Anpartsselskab',
	],
	'address' => [
		'type' => 'beliggenhedsadresse',
		'formatted' => 'Example Street 12, 7400 Herning',
		'careOf' => null,
		'street' => 'Example Street',
		'houseNumberFrom' => '12',
		'houseNumberTo' => null,
		'floor' => null,
		'door' => null,
		'postalCode' => '7400',
		'city' => 'Herning',
		'supplementaryCity' => null,
		'countryCode' => 'DK',
		'freeText' => null,
	],
	'postalAddress' => null,
	'contact' => [
		'email' => 'info@example.test',
		'phone' => '12345678',
		'marketingProtected' => false,
	],
	'industries' => [
		'primary' => [
			'code' => '000000',
			'name' => 'Example industry',
			'sequence' => 0,
		],
		'secondary' => [],
	],
]
```

`getCompany()` requires exactly eight CVR digits and returns `null` when the
upstream query returns no company node. Integration failures throw exceptions
from `CitOmni\DanishPublicSectorData\Exception`. The `address.formatted` value is
built locally from the normalized CVR address fields; Datafordeler's upstream
`Adresse` value is a DAR address reference rather than formatted display text.

`address` continues to prefer the registered location address and falls back to the
postal address for backwards-compatible lookup behavior. `postalAddress` exposes the
postal address explicitly when CVR supplies one. Industry sequence `0` is normalized as
the primary industry; sequences `1` through `3` are returned as secondary industries.

The normalized `getCompany()` contract intentionally remains limited to fields exposed by
the selected Datafordeler GraphQL contract. Source-specific payloads are not part of
`getCompany()` and must not leak into application persistence contracts.

The current default uses Datafordeler `flexibleCurrent/v3`. Host applications can
override the endpoint selection through the normal CitOmni configuration flow:

```php
return [
	'danish_public_sector_data' => [
		'cvr' => [
			'service' => 'flexibleCurrent',
			'version' => 'v3',
		],
	],
];
```

A service-version change that also changes the GraphQL schema may require a package
update; overriding the version does not make incompatible schemas compatible.

## VAT registration lookup

The provider also exposes `vatRegistration`. Its current implementation checks one exact
CVR/SE number through SKAT's anonymous public VAT-number web lookup:

```php
$status = $this->app->vatRegistration->getStatus('12345678');
```

The normalized result contains the queried number, the decisive current registration state,
the displayed verification date when available, and `skat.dk` as source. The web transport
is deliberately isolated because the public SKAT page is not a documented API contract.
No CAPTCHA, login, or access control is bypassed. The exact queried CVR/SE number is retained
because a legal CVR number may use separate administrative SE numbers for VAT registration.

The parser smoke test covers the observed CVR/SE search controls plus decisive positive and
negative result wording without making a live network request:

```bash
php tests/skat_vat_web_parser_test.php
```

## Internal Datafordeler client

Datafordeler authentication and GraphQL transport are internal package concerns.
`Support\DatafordelerClient` is instantiated by public package services as needed and
is deliberately not registered in the host application's service map.

The first release supports API-key authentication for non-access-restricted data.
OAuth and access-restricted datasets are deliberately outside the initial scope.

## Architecture

The provider keeps the boundaries intentionally small:

- `Service\Bbr` resolves DAR addresses and returns normalized BBR property data.
- `Service\SoilContamination` resolves physical Matriklen parcels and returns normalized DKjord soil-contamination data.
- `Service\Cvr` is the public CVR capability and owns CVR-specific queries and normalization.
- `Service\VatRegistration` is the public VAT-registration status capability.
- `Support\DatafordelerClient` owns Datafordeler authentication, GraphQL transport, response validation, and safe exception translation.
- `Support\DkJordWfsClient` isolates anonymous DKjord WFS transport and GeoJSON response validation.
- `Support\SkatVatWebClient` isolates the temporary public SKAT web-flow transport details.
- `Exception` contains transport-agnostic integration failure semantics.
- No SQL, HTTP controller behavior, or CLI output belongs in these services.

New public-sector sources should be added only when there is a concrete consumer.
Do not force unrelated REST, GraphQL, geospatial, or file-download APIs behind one
artificial generic abstraction.

## Configuration and services

The provider contributes these shared service IDs:

- `bbr`
- `soilContamination`
- `cvr`
- `vatRegistration`

Internal transport helpers are not registered as host-app services.

Package-owned defaults live under `danish_public_sector_data` in
`src/Boot/Registry.php` and may be overridden by the host app through normal
CitOmni configuration precedence.

## Data and licensing

The package source code is released under the MIT License. Data retrieved from
public-sector services remains subject to the terms, licences, access conditions,
and other rules of the respective data provider. This package does not grant any
rights to third-party or public-sector data.

## Coding conventions

- PHP 8.5+
- PSR-1 / PSR-4
- PascalCase classes
- camelCase methods and variables
- UPPER_SNAKE_CASE constants
- K&R braces
- Tabs for indentation
- PHPDoc and inline comments in English
- Fail fast unless a failure is genuinely recoverable

## License

citomni/danish-public-sector-data is released under the MIT License.
See [`LICENSE`](./LICENSE) and [`NOTICE`](./NOTICE).

## Trademarks

See [`TRADEMARKS.md`](./TRADEMARKS.md) for the CitOmni trademark notice
applicable to this package.
