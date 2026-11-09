# Soil contamination suite

Isolated checks for the `soilContamination` service, `\CitOmni\DanishPublicSectorData\Service\SoilContamination`: how it reads the DKjord locality layer into parcel statuses, picks the current Matriklen object, and builds its WFS projection and Matriklen queries. No request is sent.

## Run

```
php tests/soil-contamination/run.php
```

Expected result:

```
17 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The private methods of the service run through reflection on one service, built on an app double with the cfg it reads (`soil_contamination`, `datafordeler` and `dkjord_wfs`) and no curl service, so a request would fail the case. The DKjord localities are GeoJSON features built in the suite. Most parcel cases share the localities of one cadastral district, in which one locality arrives in two features with the same list and a parcel is listed by several localities.

## Checks

| Check | Covers |
|---|---|
| A listed parcel gets the status that DKjord registers on it, its text and its locality once | Parcel identity, the `v2` status, the status text, the locality |
| A parcel in several localities has one status and lists each locality once, ordered by number | One `v1_v2` status, each locality once, a locality without a name |
| Each DKjord status text becomes its status, also in a trimmed entry and for a numeric matrikelnummer | Every status text, trimmed and empty entries, a numeric matrikelnummer |
| A parcel that no locality lists has no match, no status and no locations; 311 does not match 311a | Exact parcel matching, a parcel without status |
| A parcel with two statuses or an unknown status text is rejected, and the other parcels keep theirs | Conflicting and unknown statuses |
| parseLocalities() rejects a malformed entry, a locality outside the ejerlav and a feature without properties | Malformed input; a locality without parcels adds nothing |
| Every status text seen in DKjord data is known, and every SoilContaminationStatus has a text | The status vocabulary in both directions |
| orderedStatuses() follows the SoilContaminationStatus case order | Status order |
| splitSemicolonList() trims each value, skips empty ones and turns null into an empty list | Semicolon lists |
| isCurrentStatus() accepts Gældende in any letter case and rejects Foreløbig | The Matriklen status Gældende |
| currentNode() ignores a preliminary version next to the current one and counts identical nodes once | A preliminary version, identical nodes, a node without status |
| currentNode() rejects two different current nodes and names the lookup and the statuses of all nodes | `InvalidResponseException` and its message |
| getParcelsByBfe() is public, so a BFE number resolves to parcels without DKjord | BFE-to-parcel resolution as public API |
| The locality request names only the properties that the lookup reads | The WFS property names |
| The WFS projection joins the property names in order and rejects an empty list or an invalid name | The WFS projection and its guards |
| The BFE lookups of Matriklen are exact, carry the effect time and select the status of each node | The SFE, building-on-foreign-ground and owner-apartment queries |
| The parcel lookup of Matriklen filters on the exact SFE id with the page size | The Matriklen parcel query |

`php tests/run.php` runs every suite of the package.
