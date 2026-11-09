# CVR suite

Isolated checks for the `cvr` service, `\CitOmni\DanishPublicSectorData\Service\Cvr`: the requests it sends, and the companies, production units and employee figures it returns, against a scripted transport. No network request is made.

## Run

```
php tests/cvr/run.php
```

Expected result:

```
33 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service and `\CitOmni\DanishPublicSectorData\Support\DatafordelerClient` run on an app double with the `datafordeler` and `cvr` cfg, a secrets double that holds an API key, and a scripted curl double. The curl double answers each request with the next scripted GraphQL data object, and a request that no answer was scripted for fails the case. One case makes an answer wait for the next wall-clock second, so a lookup that read the clock again would send another effect time. A table of invalid values gives one case per row, named after the row, so a failure names the value and the other rows still run.

The curl double keeps every query document sent. The last case checks them all against the bundled `resources/bbr/FLEX_CURRENT_V003.schema.graphql` with `\CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema`: fields, arguments, filter fields and their operators, one root field per request, and the joins of each operation. It therefore covers the documents of the cases before it.

## Checks

| Check | Covers |
|---|---|
| getCompany() with production units sends one company and one unit request with one UTC effect time | Request count, the endpoint, the CVR filter, page sizes 100 and 1000, one UTC effect time |
| getCompany() normalizes the company and its production units, ordered by P-number | Key order of both records, company form, location and postal address, contact data, industries by sequence, the relations of a unit, missing relations as `null` |
| getCompany() without production units takes one request and has productionUnits null | The default without units |
| getCompany() returns null for an unknown CVR number and sends no unit request | Unknown number |
| getCompany() follows the company and unit pages by cursor, with one effect time across a second boundary | Cursor paging of both roots, units of every page in P-number order, one effect time per call |
| The first row of a company or a production unit wins over a repeated row | Repeated rows |
| getCompanies() sends 150 numbers in batches of 100 and keys the results by number in input order | Batches, unit requests only for found companies, int keys in input order, repeated and unknown numbers |
| getCompanies() and getCompany() accept CVR numbers as ints, such as the keys of a result | Int input |
| getProductionUnits() filters on the distinct P-numbers and keys the results by P-number | Distinct filter, page size, `null` for an unknown number, the first row |
| getProductionUnit() returns the same record shape and accepts an int | Single lookup, int input |
| getProductionUnits() sends 101 P-numbers in two requests | Batch boundary |
| Invalid CVR numbers and P-numbers are rejected before any request | `InvalidArgumentException` for a wrong length, a leading zero, a non-digit, a line break, a negative number or a float; empty lists |
| A company or a production unit that was not requested is rejected | `InvalidResponseException` before a further page is requested |
| A cursor that repeats, a next page without a cursor and a connection without pageInfo are rejected | A cursor repeated at once or later, a missing cursor, a missing `pageInfo` |
| getProductionUnitEmployees() takes one id request and one employment request per batch | The id request without joins, entity ids sent as strings, page sizes, one effect time |
| getProductionUnitEmployees() returns the latest figure that counts employees, keyed by P-number | Keys in input order, unknown numbers, the figure shape, full-time equivalents left out, a figure with only an interval |
| getProductionUnitEmployees() follows the pages of figures by cursor and compares the figures of every page | Paging of figures |
| A unit whose figures count no employees, or hold no number, has no figure | No figure |
| getProductionUnitEmployees() sends 101 P-numbers in two id requests, and figure requests only for a batch with a unit found | Batch boundary, no employment request for a batch without units |
| getCompanyEmployees() filters on the CVR numbers and on the entity ids of the companies | The company id and employment filters |
| Of figures that end on the same day the shorter period wins, then an exact count, then the later registration in UTC | Tie-breaking, with registration times compared in UTC |
| getCompanyEmployees() returns null for an unknown CVR number and sends no employment request | Unknown number |
| The employee lookups return nothing for no numbers and reject invalid numbers before any request | Empty lists, `InvalidArgumentException` |
| An id row or a figure that does not fit the request is rejected | An unrequested P-number, a missing id, a figure of an unrequested entity |
| A negative count in a figure that counts employees is rejected | `antal` -1 |
| A count that is not an integer in a figure that counts employees is rejected | `antal` "1.5" |
| An interval that ends before it starts in a figure that counts employees is rejected | `intervalFra` 20 and `intervalTil` 10 |
| A date that does not exist in a figure that counts employees is rejected | `datoTil` 2025-02-30 |
| A period that ends before it starts in a figure that counts employees is rejected | `datoFra` after `datoTil` |
| A registration time that is not a time in a figure that counts employees is rejected | `registreringsdato` "not a time" |
| A missing type in a figure that counts employees is rejected | `beskaeftigelsestalstype` null |
| A figure of full-time equivalents is left out without a check of its values | The values of an unused figure |
| Every query document sent fits the bundled flexibleCurrent v3 schema, with one root field and at most 17 joins and 7 in depth | Every document the suite sent; joins per operation |

`php tests/run.php` runs every suite of the package.
