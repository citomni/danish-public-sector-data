# EJF suite

Isolated checks for the `ejf` service, `\CitOmni\DanishPublicSectorData\Service\Ejf`, and the Shared Secret flow of `\CitOmni\DanishPublicSectorData\Support\DatafordelerClient`: the token and query requests, and the ownerships, company ownerships, transfers, administrations and administration entries the service returns, against a scripted transport. No network request is made.

## Run

```
php tests/ejf/run.php
```

Expected result:

```
96 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

One case needs `vendor/` from `composer install` in the package root: the transport failure of the token request throws the real `\CitOmni\Infrastructure\Exception\CurlException`. Without `vendor/`, that case fails with a message that says so, and the suite ends with `95 passed, 1 failed`.

## How it works

The real service and `DatafordelerClient` run on an app double with the `datafordeler` and `ejf` cfg, a secrets double with the OAuth client id and secret, and a scripted curl double. The curl double answers each request with the next scripted answer, a status and a body, or throws the next scripted exception; a request that no answer was scripted for fails the case. A table of invalid values gives one case per row, named after the row, so a failure names the value and the other rows still run. A case that expects `RemoteServiceException` also checks the exact class, since `AuthenticationException` and `RateLimitException` extend it.

The client keeps an access token for the process, per token endpoint and client id. A case that needs a new token therefore first takes a client id of its own, and a case that does not test the token flow scripts a token answer in front of its own answers.

The curl double keeps every query document sent. The last case checks them all against the bundled `resources/bbr/FLEX_CURRENT_V003.schema.graphql` with `\CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema`, checks that the service queries only the roots `EJFCustom_EjerskabBegraenset`, `EJFCustom_PersonEllerVirksomhedsadminiBegraenset` and `EJF_Ejerskifte`, and that the reference joins it follows are only those of the Handelsoplysninger, of the share transfers (`EJF_Ejerskabsskifte`) and of `EJFCustom_AlternativAdresseBegraenset`; the schema also offers joins to entities that only public authorities get. It therefore covers the documents of the cases before it.

## Checks

| Check | Covers |
|---|---|
| The first query of a client asks the token endpoint for a client credentials token and carries it as a bearer token | The token request as a form POST with the grant, client id and secret; the secret kept out of the URL; the bearer token; no API key; the GraphQL `Accept` header; an empty result |
| A valid token is reused, also by another client of the process with the same client id | Token reuse |
| A token that expires within the renewal margin is replaced before the next request | The renewal margin |
| A token answer without expires_in gives a token that the next lookup reuses | The default lifetime |
| A missing client id or an empty client secret fails the lookup before anything is sent | `AuthenticationException`, nothing sent |
| A 401 from the token endpoint fails the lookup and sends no query | `AuthenticationException` |
| A 400 from the token endpoint fails the lookup and sends no query | `AuthenticationException` |
| A 429 from the token endpoint fails the lookup and sends no query | `RateLimitException` |
| A 500 from the token endpoint fails the lookup and sends no query | `RemoteServiceException` itself |
| A token answer that is not JSON fails the lookup and sends no query | `InvalidResponseException` |
| A token answer without access_token fails the lookup and sends no query | `InvalidResponseException` |
| A token with a line break fails the lookup and sends no query | `InvalidResponseException`, so the token never reaches a header |
| A transport failure of the token request fails the lookup with a RemoteServiceException and sends no query | `CurlException` of citomni/infrastructure as `RemoteServiceException` itself; needs `vendor/` |
| A 401 on the query fails the lookup and discards its token, so the next lookup asks for a new one | The token is discarded |
| A 429 on the query fails the lookup with a RateLimitException and keeps its token | The token is kept |
| A token URL that is not HTTPS or an empty EJF service is rejected when the service is created | `UnexpectedValueException` from cfg |
| No BFE numbers give no entries, and invalid BFE numbers are rejected, both without a request | Empty lists; zero, a leading zero, negative, decimal, padded, too long, `null` and boolean numbers |
| getOwnerships() sends 150 distinct numbers in batches of 100 and 50, with one UTC effect time, and keys the entries by number | Batches, first-occurrence order, rows under their own number, page size, one UTC effect time |
| The next page is requested with the cursor of the page before, and a row repeated on a later page is kept once | Cursor paging, repeated rows |
| A cursor that comes back, a next page without a cursor, a row of another property and an answer without the connection are rejected | `InvalidResponseException` |
| GraphQL errors fail the lookup with a RemoteServiceException | GraphQL `errors` as `RemoteServiceException` itself |
| Ownerships come oldest first by validFrom, unknown dates first, then by id | Order; the placeholders of 1800 and 1970 as `null` |
| A person owner keeps its CPR data, the address line as CPR writes it, and its protections sorted once each | The complete record of a person ownership |
| Protection, death and emigration decide the name and addresses of a person owner | A former ownership, name and address protection, a deceased and an emigrated person, Danish dates of winter instants |
| Without a CPR address line, the formatted address starts with street and house number and names the city | The formatted address |
| A company, another owner and an ownership without an owner get their owner type and their own data | `company`, `other` with and without joined owner information, `unknown`; blank text as `null` |
| An ownership with a missing id is rejected | `id_lokalId` null |
| An ownership with a BFE number as a string is rejected | `bestemtFastEjendomBFENr` "3001" |
| An ownership with a missing status is rejected | `status` null |
| An ownership with a share that lacks one side is rejected | `tinglystEjerandel_naevner` null next to a numerator |
| An ownership with a share whose denominator is 0 is rejected | `faktiskEjerandel_naevner` 0 |
| An ownership with a negative share is rejected | `faktiskEjerandel_taeller` -1 |
| An ownership with an effect start without an offset is rejected | `virkningFra` 2019-07-01T00:00:00 |
| An ownership with an effect start that is not a time is rejected | `virkningFra` "yesterday" |
| An ownership with a missing effect start is rejected | `virkningFra` null |
| An ownership with a primary contact flag that is not a boolean is rejected | `primaerKontakt` "true" |
| An ownership with a person without a status date is rejected | `statusdato` null |
| An ownership with a person whose birth date does not exist is rejected | `foedselsdato` 1950-02-30 |
| An ownership with a person whose protections are not a list is rejected | `beskyttelser` null |
| An ownership with a person whose name is not text is rejected | `navn.navn` 42 |
| An ownership with a person whose address is not an object is rejected | `cprAdresse` as a string |
| An ownership with a CVR number that is not a number is rejected | `ejendeVirksomhedCVRNr` "12345678" |
| An ownership with owner information that is not an object is rejected | `oplysningerEjesAfEjerskab` "Boet" |
| Transfers come oldest first by takeover date, unknown dates first, and rolled-back transfers are left out | Order, rolled-back transfers |
| A transfer keeps its dates as Danish dates, and its trade lists each current property of the trade once | The complete record of a sale with trade information, object id and share transfers: ordered by id, a rolled-back one left out, a repeated one kept once |
| A transfer without an object id or share transfers has objectId null and no share transfers | `objectid` null and an empty share transfer connection |
| A transfer lookup selects the object id and the current share transfers of every transfer, up to 1000 a transfer | The selection of the transfer document |
| A placeholder takeover date becomes null, a takeover after midnight keeps its Danish date, and a transfer without trade information has trade null | Placeholder and Danish dates, `trade` null |
| A rolled-back transfer is left out before its values are read | Invalid values of a rolled-back transfer |
| A transfer with a missing takeover date is rejected | `overtagelsesdato` null |
| A transfer with a filing date without a time is rejected | `anmeldelsesdato` 2019-07-11 |
| A transfer with a conditional flag that is not a boolean is rejected | `betinget` "false" |
| A transfer with a deadline that does not exist is rejected | `fristDato` 2023-06-31 |
| A transfer with a trade price that is not a number is rejected | `samletKoebesum` "1250000" |
| A transfer with a trade without its transfers is rejected | `handelsoplysningerBeskriverEjerskifte` null |
| A transfer with a trade transfer without a BFE number is rejected | `bestemtFastEjendomBFENr` null in the trade |
| A transfer with a missing status is rejected | `status` null |
| A transfer with an object id that is not a positive integer is rejected | `objectid` "307000123" |
| A transfer with no share transfer connection is rejected | The share transfer connection null |
| A transfer with share transfers without their page information is rejected | A connection without `pageInfo` |
| A transfer with more share transfers than one page is rejected | `hasNextPage` true |
| A transfer with a share transfer without an id is rejected | `id_lokalId` null on a share transfer |
| A transfer with a share transfer without a status is rejected | `status` null on a share transfer |
| A transfer with a received share without its denominator is rejected | `modtagetAndel_naevner` null next to a numerator |
| A transfer with a giving ownership id that is not text is rejected | `afgivendeEjerobjektLokalId` 42 |
| getCompanyOwnerships() filters on the CVR number, follows the pages and keys the ownerships by BFE number in ascending order | The CVR filter, cursor paging, one effect time, ascending BFE numbers, oldest first within a property, a repeated row, the shape of `getOwnerships()` |
| getCompanyOwnerships() takes the CVR number as a string, and a company without ownerships gives no entries | A string CVR number; an empty result |
| An invalid CVR number is rejected without a request | Seven and nine digits, zero, negative, a leading zero, padded, a letter, an empty string |
| An ownership of another owner in the answer of a company lookup is rejected | Another CVR number and a person owner as `InvalidResponseException` |
| getImportedAdministrationEntries() filters on the import batches in one object, follows the pages of 500 and lists the entries by batch, then object id | The `gte` and `lt` filter in one object, pages of 500, cursor paging with one effect time, the order, an entry without an object id last, a repeated row |
| An entry keeps the import batch, the registration time in UTC, the Danish effect date and the administered party | The complete record of an entry; a registration time with an offset and microseconds; the placeholders of the year 1 and of 1800; a company, another party and no party administered; no administrator in an entry |
| An empty import batch range or one outside 0-2147483647 is rejected without a request | A negative start, an empty and a reversed range, an end above the GraphQL Int |
| getRegisteredAdministrationEntries() sends the period in UTC to the second in one object, and a period shorter than a second is rejected without a request | Danish times as UTC, cut to the second; an empty, a reversed and a sub-second period |
| getAdministrationEntries() and getAdministrations() send the object ids in batches of 100, key the answers by object id and leave out ids without an administration | Batches, first-occurrence order, ids without an administration, a string id, the administrator selection of a full lookup only |
| No object ids give no entries, and invalid object ids are rejected, both without a request | Empty lists; zero, a leading zero, negative, decimal, padded, too long, `null` and boolean ids |
| An administration of an id that was not requested, or without an object id, in the answer of a lookup by id is rejected | `InvalidResponseException` |
| A person administrator keeps its CPR data in the shape of an owner person | The person of an administration |
| A company, a production unit, an owner without CPR or CVR number and an administration without an administrator get their administrator type and their own data | The order of the types, a company that names a production unit, the owner information with its current alternative address, a rolled-back administration without an administrator |
| Owner information without an alternative address, and an alternative address that is not current, keep what EJF holds | Owner information without its id, a former alternative address |
| An administration with a missing id is rejected | `id_lokalId` null |
| An administration with an object id as a string is rejected | `objectid` "7001" |
| An administration with an import batch as a string is rejected | `datafordelerRegisterImportSequenceNumber` "454989" |
| An administration with a missing status is rejected | `status` null |
| An administration with a registration time without an offset is rejected | `registreringFra` 2026-11-05T14:09:59 |
| An administration with a missing effect start is rejected | `virkningFra` null |
| An administration with an administered person that is not an object is rejected | `administreretPersonBegraenset` "doed" |
| An administration with an administered person without a status date is rejected | `statusdato` null |
| An administration with an administered person whose birth date does not exist is rejected | `foedselsdato` 1941-02-30 |
| An administration with an administered CVR number that is not a number is rejected | `administreretVirksomCVRNr` "40477055" |
| An administration with an administrator CVR number that is not a number is rejected | `virksomhedCVRNr` "35209352" |
| An administration with an administrator P-number below 1 is rejected | `produktionsenhedPNr` 0 |
| An administration with an administrator person without a status date is rejected | `statusdato` null on `personBegraenset` |
| An administration with owner information that is not an object is rejected | `personEllerVirksomhedsadministratorErPersonEllerVirksomhedsoplysninger` "Boet" |
| An administration with an alternative address that is not an object is rejected | The alternative address as a string |
| An administration with an alternative address without a status is rejected | `status` null on the alternative address |
| Every query document sent fits the bundled flexibleCurrent v3 schema and queries only the ownership, transfer and administration roots and joins of the private-actor entities | Every document the suite sent; the three roots; the three reference joins |

`php tests/run.php` runs every suite of the package.
