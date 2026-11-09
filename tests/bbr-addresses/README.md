# BBR address suite

Isolated checks for `getAddressesByBfe()` of the `bbr` service, `\CitOmni\DanishPublicSectorData\Service\Bbr`: the requests it sends for many BFE numbers and the addresses it returns, against a scripted transport. No network request is made.

## Run

```
php tests/bbr-addresses/run.php
```

Expected result:

```
5 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service and `DatafordelerClient` run on an app double with the `bbr` and `datafordeler` cfg, a secrets double that holds the API key, and a scripted curl double. The curl double answers each request with the next scripted answer; a request that no answer was scripted for fails the case. The cases script the answers in the order the service asks: the properties, the grounds, the building relations and buildings, the unit relations and units, then the DAR house numbers and addresses. A case that sends something else fails.

The curl double keeps every query document sent. The last case checks them all against the bundled `resources/bbr/FLEX_CURRENT_V003.schema.graphql` with `\CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema`, so it covers the documents of the cases before it.

## Checks

| Check | Covers |
|---|---|
| Each kind of property gets the designations of its own objects, each once and in natural order | The grounds of an SFE, the buildings of a BPFG and the units of an owner apartment; the filters and the order of the requests; one effect time; a ground without a house number; a house number of two grounds; a number without a property |
| A number with several current properties has no type and the addresses of all of them, and no address ids send no DAR address request | `propertyType` null with the addresses of both properties; no requests for kinds of objects that the properties lack |
| No numbers send nothing, invalid numbers are rejected without a request, and repeated numbers are looked up once in batches of 100 | An empty list; zero, negative, a leading zero, decimal, too long, `null` and boolean numbers; batches of 100 and 50; a padded string read as its number; first-occurrence order |
| A property of a number that was not requested, or of an unknown type, is rejected | `InvalidResponseException` |
| Every query document sent fits the bundled flexibleCurrent v3 schema | Every document the suite sent |

`php tests/run.php` runs every suite of the package.
