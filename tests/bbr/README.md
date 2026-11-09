# BBR suite

Isolated checks for the `bbr` service, `\CitOmni\DanishPublicSectorData\Service\Bbr`: how it reads typed auction addresses and chooses streets and DAR addresses, how it labels and normalizes BBR nodes, the bundled code lists of `\CitOmni\DanishPublicSectorData\Util\BbrCodeLists`, and that its queries select every BBR v3 field, each kept under a key of its own or by `\CitOmni\DanishPublicSectorData\Util\BbrFieldCatalog`. No request is sent.

## Run

```
php tests/bbr/run.php
```

Expected result:

```
21 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The private methods of the service run through reflection on one service, built on an app double with the cfg it reads (`bbr` and `datafordeler`) and no curl service, so a request would fail the case.

The field coverage cases read `src/Service/Bbr.php` as text. They take the fields that each selection method selects and the node fields that each normalizer reads, and compare them with the BBR types of the bundled `resources/bbr/BBR_V003.schema.graphql` and `resources/bbr/FLEX_CURRENT_V003.schema.graphql`. A selection method must return a `GRAPHQL` nowdoc, and a normalizer must be followed by the PHPDoc of the next method; a method in another layout fails the case that reads it.

## Checks

| Check | Covers |
|---|---|
| parseAuctionAddress() removes a trailing m.fl. or mfl. from the lookup input and keeps it as a hint | Both spellings; street, house number and postal code |
| parseAuctionAddress() reads the floor and door of an owner apartment | Floor and door normalization |
| parseAuctionAddress() keeps a street name with a space and a house number with a letter | Street names with a space, house-number letters, a special postal code |
| chooseStreet() prefers the exact name and corrects a one-letter typo when one street is clearly closest | Exact and fuzzy matches, with the official name |
| selectAddress() selects the one DAR address of the typed floor and door | Unit address selection |
| normalizeGround() labels the municipality and wastewater codes and keeps the order deadline | Ground labels and order deadline |
| normalizeBuilding() labels the wastewater codes and keeps the order deadline | Building labels and order deadline |
| normalizeUnit() keeps the flush-toilet and bathroom counts | Unit counts |
| The ground, building and unit queries select the wastewater and sanitation fields | Selected fields |
| normalizeFloor() and normalizeEntrance() label the floor type, the elevator and the house-number role | Floor and entrance labels |
| BbrCodeLists names the dates of its three sources | The three source dates |
| BbrCodeLists::label() resolves codes of all three sources, deprecated codes included | Codes of the primary snapshot, the verified lists and the supplement |
| BbrCodeLists::label() returns null for an unknown list, an unknown code and a null code | `null` results |
| Each list/code pair of the bundled code lists is held by one source only | No list/code pair in two sources |
| The bundled code lists hold the expected numbers of lists and codes | 6 verified lists with 16 pairs, 45 supplemented lists with 363 pairs, 78 lists and 954 pairs in all |
| Every code list that Bbr and BbrFieldCatalog reference is bundled | Code-list names in `src/Service/Bbr.php` and `src/Util/BbrFieldCatalog.php` |
| Each BBR entity query selects every field of its BBR v3 type once, apart from the internal ones | Field coverage against `BBR_V003.schema.graphql` |
| Each BBR entity query selects only fields that the flexibleCurrent v3 type has | Selections against `FLEX_CURRENT_V003.schema.graphql` |
| Each BBR domain field is read by its normalizer or kept by BbrFieldCatalog, never both | No unaccounted and no duplicated field |
| BbrFieldCatalog keeps 0, labels a coded value, keeps a coordinate and leaves out null | Additional-field normalization |
| BbrFieldCatalog rejects an unknown entity | Argument guard |

`php tests/run.php` runs every suite of the package.
