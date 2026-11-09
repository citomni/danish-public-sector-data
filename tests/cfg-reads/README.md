# Cfg reads suite

Isolated checks of how `src/` reads cfg: a path of the package's own cfg is read directly and exists in `\CitOmni\DanishPublicSectorData\Boot\Registry::CFG_COMMON`, and a path that the package does not own is read with a fallback, as the [Cfg access reference](https://github.com/citomni/docs/blob/main/reference/cfg-access.md) requires. No code of `src/` runs, no request is sent and nothing is written.

## Run

```
php tests/cfg-reads/run.php
```

Expected result:

```
5 passed, 0 failed
```

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The suite reads `src/` with PHP's tokenizer, so commented-out code is ignored. It follows `->cfg->...` property chains, also through a local variable assigned from one in the same function, and marks a read guarded when `??`, `isset()`, `empty()` or `?->` protects it. A path whose root is a key of `Registry::CFG_COMMON` belongs to the package; `danish_public_sector_data` is the only one. A failing case lists the reads at fault as `file:line path`.

The first case checks that the scan still finds three reads that `src/` is known to have, so a scan that finds nothing cannot pass the other cases.

## Checks

| Check | Covers |
|---|---|
| The scan finds cfg reads that src/ is known to have | Three known paths; a miss means the scan patterns are stale |
| src/ reads cfg with property syntax, which the scan can follow | No array syntax on a Cfg node, which the scan cannot follow |
| src/ reads its own cfg directly, without ??, isset(), empty() or ?-> | Own paths without a fallback |
| Every path of its own cfg that src/ reads exists in CFG_COMMON | Every own path that `src/` reads is in the Registry baseline |
| src/ reads cfg that the package does not own with a fallback | Paths of other packages with a fallback |

`php tests/run.php` runs every suite of the package.
