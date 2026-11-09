# BBR schema check suite

Isolated checks for the parts of `bbr:schema-check` that run without Datafordeler: `\CitOmni\DanishPublicSectorData\Util\BbrSchemaDocument` on the bundled schemas in `resources/bbr/`, `\CitOmni\DanishPublicSectorData\Util\BbrSchemaComparator`, the schema download that `\CitOmni\DanishPublicSectorData\Operation\CheckBbrSchema` calls, and the registration of the command. No request is sent.

## Run

```
php tests/bbr-schema-check/run.php
```

Expected result:

```
5 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

`BbrSchemaDocument` reads the two bundled schema files, and `BbrSchemaComparator` compares two type maps built in the case. The download itself needs Datafordeler, so the suite checks only that `DatafordelerClient::schema()` is public. `CheckBbrSchema` and `BbrSchemaCheckCommand` do not run: the Operation downloads both schemas, and the Command runs only inside an app.

## Checks

| Check | Covers |
|---|---|
| BbrSchemaDocument reads the BBR object types of both bundled schemas with their field types | `FLEX_CURRENT_V003.schema.graphql` and `BBR_V003.schema.graphql`, with the type signature of each field |
| BbrSchemaDocument rejects an empty schema | `UnexpectedValueException` for an empty document |
| BbrSchemaComparator reports new types, added and removed fields and changed field types as drift | Each kind of drift |
| DatafordelerClient::schema() is public, so CheckBbrSchema can download a schema | The download method, through reflection |
| The Registry maps bbr:schema-check to BbrSchemaCheckCommand | `Registry::COMMANDS_CLI` |

`php tests/run.php` runs every suite of the package.
