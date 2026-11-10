# BBR address resolution suite

Isolated checks for `resolveAddress()` of the `bbr` service, `\CitOmni\DanishPublicSectorData\Service\Bbr`: how it finds the property of a typed address through the buildings of its house number, and through the buildings that the house number gives access to when those give none, against a scripted transport. No network request is made.

## Run

```
php tests/bbr-address-resolution/run.php
```

Expected result:

```
7 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The real service and `DatafordelerClient` run on an app double with the `bbr` and `datafordeler` cfg, a secrets double that holds the API key, and a scripted curl double. Every case resolves `Eksempelvej 10L, 7200 Grindsted`, and DAR answers with one postcode, one street and one house number, with one address or two addresses on two floors.

The curl double answers each request with the first scripted answer for the root connection of its query document, such as `BBR_Bygning`, so a case does not depend on the order of requests to different connections; the answers for one connection are used in the order they are scripted. A request without a scripted answer fails the case, and every case checks that it asked for all its answers.

The curl double keeps every query document sent. The last case checks them all against the bundled `resources/bbr/FLEX_CURRENT_V003.schema.graphql` with `\CitOmni\DanishPublicSectorData\Tests\Support\GraphqlSchema`, so it covers the documents of the cases before it.

## Checks

| Check | Covers |
|---|---|
| A side entrance whose building BBR registers under another house number resolves to the SFE of that building | The building that DAR, an entrance and the unit at the address name, asked for once; the entrance filter on `adgangFraHusnummer`; the order of the requests; one effect time; `access_ground_sfe` |
| The building that DAR names, an entrance or the unit at the address each lead to the access building on their own | Each of the three links alone |
| Access buildings on two properties give ambiguous_bfe and select neither | Two access buildings in one request; both SFE as candidates; `multiple_bfe_candidates` |
| Without a selected address the owner apartments in an access building are candidates, so the SFE of its ground is not selected | The units of the access building; `access_unit_ejerlejlighed` |
| A house number whose own building has no property and that gives access to no other building stays no_bfe | The entrance request; no second request for the house number's own building |
| A house number whose own building has a property resolves through it, without asking for entrances | `selected_unit_ejerlejlighed` over `ground_sfe` with a selected address; the SFE without one; no entrance request |
| Every query document sent fits the bundled flexibleCurrent v3 schema | Every document the suite sent |

`php tests/run.php` runs every suite of the package.
