# VAT registration suite

Isolated checks for the SKAT web flow behind the `vatRegistration` service: how `\CitOmni\DanishPublicSectorData\Support\SkatVatWebClient` finds the search form and reads a result page. No request is sent.

## Run

```
php tests/vat-registration/run.php
```

Expected result:

```
4 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The private methods of `SkatVatWebClient` run through reflection on fixtures of SKAT's pages: a search form, and the text of a positive and a negative result. The client is built on an app double with the `skat_vat_web` cfg and no curl service, so a request would fail the case.

## Checks

| Check | Covers |
|---|---|
| parseSearchForm() chooses the CVR/SE search, keeps the flow state and submits the number in the discovered field | Form method, the action without `jsessionid`, hidden flow state, the CVR/SE search mode, the number field and the submit control |
| registrationState() reads SKAT's explicit positive and negative results | `true` for the positive and `false` for the negative result |
| registrationState() is null for a page that is not decisive | Explanatory text, and a positive text without the queried number |
| verificationDate() reads the date of a result page as YYYY-MM-DD, and null without one | The date normalized to ISO format, and a result without a date |

`php tests/run.php` runs every suite of the package.
