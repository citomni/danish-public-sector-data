# Danish Public Sector Data

Reusable CitOmni clients for authoritative data services provided by the Danish public sector.

## Package

`citomni/danish-public-sector-data`

Repository: https://github.com/citomni/danish-public-sector-data

**citomni/danish-public-sector-data** is a CitOmni provider package for PHP 8.5+.
It exposes small, normalized CitOmni services while keeping upstream API protocols,
authentication details, and source-specific data models inside the provider.

The initial implementation supports non-access-restricted company data from the
Danish Central Business Register (CVR) through Datafordeler GraphQL.

## Requirements

- PHP 8.5+
- Composer
- `citomni/kernel`
- `citomni/infrastructure`
- An enabled `CitOmni\Infrastructure\Boot\Registry` in the host app
- A Datafordeler IT-system with an API key for non-access-restricted data

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

The company lookup intentionally does not infer accounting year, VAT registration,
capital, purpose, signing rules, or other values that are not exposed by the selected
CVR GraphQL contract. Consumers should obtain those values from an appropriate source
or ask the user instead of deriving them from unrelated CVR fields.

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

## Internal Datafordeler client

Datafordeler authentication and GraphQL transport are internal package concerns.
`Support\DatafordelerClient` is instantiated by public package services as needed and
is deliberately not registered in the host application's service map.

The first release supports API-key authentication for non-access-restricted data.
OAuth and access-restricted datasets are deliberately outside the initial scope.

## Architecture

The provider keeps the boundaries intentionally small:

- `Service\Cvr` is the public CVR capability and owns CVR-specific queries and normalization.
- `Support\DatafordelerClient` owns Datafordeler authentication, GraphQL transport, response validation, and safe exception translation.
- `Exception` contains transport-agnostic integration failure semantics.
- No SQL, HTTP controller behavior, or CLI output belongs in these services.

New public-sector sources should be added only when there is a concrete consumer.
Do not force unrelated REST, GraphQL, geospatial, or file-download APIs behind one
artificial generic abstraction.

## Configuration and services

The provider contributes one shared service ID:

- `cvr`

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
