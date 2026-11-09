# EJF suite

Isolated checks for the `ejf` service, `\CitOmni\DanishPublicSectorData\Service\Ejf`, and the Shared Secret flow of `\CitOmni\DanishPublicSectorData\Support\DatafordelerClient`: the token and query requests, and the ownerships and transfers the service returns, against a scripted transport. No network request is made.

## Run

```
php tests/ejf/run.php
```

Expected result:

```
56 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

One case needs `vendor/` from `composer install` in the package root: the transport failure of the token request throws the real `\CitOmni\Infrastructure\Exception\CurlException`. Without `vendor/`, that case fails with a message that says so, and the suite ends with `55 passed, 1 failed`.

## How it works

The real service and `DatafordelerClient` run on an app double with the `datafordeler` and `ejf` cfg, a secrets double with the OAuth client id and secret, and a scripted curl double. The curl double answers each request with the next scripted answer, a status and a body, or throws the next scripted exception; a request that no answer was scripted for fails the case. A table of invalid values gives one case per row, named after the row, so a failure names the value and the other rows still run. A case that expects `RemoteServiceException` also checks the exact class, since `AuthenticationException` and `RateLimitException` extend it.

The client keeps an access token for the process, per token endpoint and client id. A case that needs a new token therefore first takes a client id of its own, and a case that does not test the token flow scripts a token answer in front of its own answers.

The curl double keeps every query document sent. The last case checks them all against the bundled `resources/bbr/FLEX_CURRENT_V003.schema.graphql` with `\CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema`, and checks that the service queries only the roots `EJFCustom_EjerskabBegraenset` and `EJF_Ejerskifte`. It therefore covers the documents of the cases before it.

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
| A transfer keeps its dates as Danish dates, and its trade lists each current property of the trade once | The complete record of a sale with trade information |
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
| Every query document sent fits the bundled flexibleCurrent v3 schema and queries only the ownership and transfer roots | Every document the suite sent; the two roots |

`php tests/run.php` runs every suite of the package.
