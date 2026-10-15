<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

use CitOmni\DanishPublicSectorData\Boot\Registry;

/*
 * Package cfg reads: every path the package owns is read directly and exists in
 * Registry::CFG_COMMON, and every path it does not own is read with a fallback.
 * Scans src/ with the tokenizer, so commented-out code is ignored.
 */

$root = \dirname(__DIR__);
require_once $root . '/src/Boot/Registry.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
	if (!$condition) {
		throw new \RuntimeException('FAIL: ' . $message);
	}

	++$checks;
};

/**
 * Collect the App cfg reads in package source code.
 *
 * Behavior:
 * - Follows ->cfg->... property chains, and chains through a local variable assigned
 *   from one in the same function ($c = $this->app->cfg->security->csrf), also when the
 *   assignment continues with ?? (the local then holds the cfg node or the fallback). A
 *   local assigned the cfg root ($cfg = $this->app->cfg) is followed too; the assignment
 *   itself is not a read. A local assigned anything else stops being followed.
 * - Records each read's dotted path, the file below src/, the line, and whether it uses
 *   array syntax on a Cfg node ($this->app->cfg['http'], $c['enabled']).
 * - Marks a read guarded when it is followed by ??, is an argument of isset() or empty(),
 *   or uses ?-> anywhere in its chain.
 *
 * Notes:
 * - Every ->cfg property counts as App cfg.
 * - A chain ends at a method call (->toArray()), at a dynamic property name and at array
 *   access. A named function starts a new scope; a closure body keeps the enclosing one.
 *   Variables are not followed across functions or through arrays, and a cfg node held
 *   in a property ($this->csrf = $this->app->cfg->security->csrf) is not followed at all.
 * - A ?? after a closing parenthesis (($cfg->port) ?? 1) is not seen, so that read counts
 *   as unguarded. ?: is no guard, since the read still throws on a missing key.
 *
 * @param  string  $srcDir  Absolute path to the package src/ directory.
 * @return list<array{file: string, line: int, path: string, guarded: bool, array: bool}>  One row per read.
 */
function scanCfgReads(string $srcDir): array {
	$reads = [];
	$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS));
	foreach ($files as $file) {
		if ($file->getExtension() !== 'php') {
			continue;
		}
		$relative = \str_replace('\\', '/', \substr($file->getPathname(), \strlen($srcDir) + 1));
		// TOKEN_PARSE tokenizes a method named after a keyword (list, print, default) as T_STRING, so it starts a scope.
		$tokens = \array_values(\array_filter(\token_get_all((string)\file_get_contents($file->getPathname()), \TOKEN_PARSE),
			static fn(mixed $token): bool => !\is_array($token) || !\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)));
		$is = static fn(int $i, int|string $kind, ?string $text = null): bool => isset($tokens[$i])
			&& (\is_array($tokens[$i]) ? $tokens[$i][0] === $kind && ($text === null || $tokens[$i][1] === $text) : $tokens[$i] === $kind);
		$locals = [];
		$reassigned = null;
		$realiased = false;
		for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
			// -- 1. Scope and reassignment ----------------------------------
			if ($is($i, \T_FUNCTION) && ($is($i + 1, \T_STRING) || ($is($i + 1, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG) && $is($i + 2, \T_STRING)))) {
				$locals = [];
				$reassigned = null;
				continue;
			}
			if ($is($i, ';') && $reassigned !== null) {
				if (!$realiased) {
					unset($locals[$reassigned]);
				}
				$reassigned = null;
			}
			if ($is($i, \T_VARIABLE) && $is($i + 1, '=') && isset($locals[$tokens[$i][1]])) {
				$reassigned = $tokens[$i][1];
				$realiased = false;
				continue;
			}

			// -- 2. Start of a chain -----------------------------------------
			if ($is($i, \T_STRING, 'cfg') && ($is($i - 1, \T_OBJECT_OPERATOR) || $is($i - 1, \T_NULLSAFE_OBJECT_OPERATOR)) && !$is($i + 1, '(')) {
				$path = [];
				$next = $i + 1;
				$start = $i - 1;
				$guarded = $is($start, \T_NULLSAFE_OBJECT_OPERATOR);
				while ($start > 0 && ($is($start - 1, \T_VARIABLE) || $is($start - 1, \T_STRING) || $is($start - 1, \T_OBJECT_OPERATOR) || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR))) {
					$guarded = $guarded || $is($start - 1, \T_NULLSAFE_OBJECT_OPERATOR);
					$start--;
				}
			} elseif ($is($i, \T_VARIABLE) && isset($locals[$tokens[$i][1]])
				&& ($is($i + 1, \T_OBJECT_OPERATOR) || $is($i + 1, \T_NULLSAFE_OBJECT_OPERATOR) || $is($i + 1, '['))) {
				$path = $locals[$tokens[$i][1]];
				$next = $i + 1;
				$start = $i;
				$guarded = false;
			} else {
				continue;
			}

			// -- 3. Follow the chain and classify the read -------------------
			while (($is($next, \T_OBJECT_OPERATOR) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR)) && $is($next + 1, \T_STRING) && !$is($next + 2, '(')) {
				$guarded = $guarded || $is($next, \T_NULLSAFE_OBJECT_OPERATOR);
				$path[] = $tokens[$next + 1][1];
				$next += 2;
			}
			// The innermost open bracket around the chain decides whether it is an isset() or empty() argument.
			$depth = 0;
			for ($j = $start - 1; $j >= 0; $j--) {
				if ($is($j, ')') || $is($j, ']')) {
					$depth++;
				} elseif ($is($j, '(') || $is($j, '[')) {
					if ($depth === 0) {
						break;
					}
					$depth--;
				} elseif ($depth === 0 && ($is($j, ';') || $is($j, '{') || $is($j, '}'))) {
					$j = -1;
					break;
				}
			}
			$inGuardCall = $j > 0 && $is($j, '(') && ($is($j - 1, \T_ISSET) || $is($j - 1, \T_EMPTY));
			$guarded = $guarded || $is($next, \T_COALESCE) || $is($next, \T_NULLSAFE_OBJECT_OPERATOR) || $inGuardCall;
			$array = $is($next, '[');

			// -- 4. Record ----------------------------------------------------
			if (($is($next, ';') || $is($next, \T_COALESCE)) && $is($start - 1, '=') && $is($start - 2, \T_VARIABLE)) {
				$locals[$tokens[$start - 2][1]] = $path;
				$realiased = $realiased || $tokens[$start - 2][1] === $reassigned;
			}
			if ($path !== [] || $array) {
				$reads[] = ['file' => $relative, 'line' => (int)$tokens[$i][2], 'path' => \implode('.', $path), 'guarded' => $guarded, 'array' => $array];
			}
			$i = $next - 1;
		}
	}
	\usort($reads, static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);
	return $reads;
}

/**
 * Whether the package owns a cfg path.
 *
 * Notes:
 * - A root in CFG_COMMON belongs to the package; danish_public_sector_data is the only one.
 *   The package ships no CFG_HTTP or CFG_CLI.
 *
 * @param  string  $path  Dotted cfg path.
 * @return bool
 */
function ownedPath(string $path): bool {
	return \array_key_exists(\explode('.', $path)[0], Registry::CFG_COMMON);
}

/** True when $path (dot-separated) names a key in $cfg. */
function cfgHasPath(array $cfg, string $path): bool {
	foreach (\explode('.', $path) as $key) {
		if (!\is_array($cfg) || !\array_key_exists($key, $cfg)) {
			return false;
		}
		$cfg = $cfg[$key];
	}
	return true;
}

/** Format a read as file:line path for a report. */
function describe(array $read): string {
	return $read['file'] . ':' . $read['line'] . ' ' . ($read['path'] !== '' ? $read['path'] : '(cfg root)');
}

$reads = scanCfgReads($root . '/src');
$paths = \array_column($reads, 'path');
foreach (['danish_public_sector_data.bbr.current_service', 'danish_public_sector_data.datafordeler.base_url', 'danish_public_sector_data.skat_vat_web.connect_timeout'] as $known) {
	$check(\in_array($known, $paths, true), "The scan finds {$known}; otherwise its patterns are stale.");
}

$list = static fn(callable $filter): string => \implode('; ', \array_map('describe', \array_values(\array_filter($reads, $filter))));

$array = $list(static fn(array $read): bool => $read['array']);
$check($array === '', 'Cfg is read with property syntax, which the scan can follow: ' . $array);

$guarded = $list(static fn(array $read): bool => !$read['array'] && $read['guarded'] && ownedPath($read['path']));
$check($guarded === '', 'Package-owned cfg is read without ??, isset(), empty() or ?->: ' . $guarded);

$missing = $list(static fn(array $read): bool => !$read['array'] && ownedPath($read['path']) && !cfgHasPath(Registry::CFG_COMMON, $read['path']));
$check($missing === '', 'Every package-owned path read in src/ exists in CFG_COMMON: ' . $missing);

$direct = $list(static fn(array $read): bool => !$read['array'] && !$read['guarded'] && !ownedPath($read['path']));
$check($direct === '', 'Cfg the package does not own is read with a fallback: ' . $direct);

\fwrite(
	STDOUT,
	'PASS ' . $checks . ' checks. Package cfg reads against the Registry baseline. PHP '
	. \PHP_VERSION . '.' . \PHP_EOL
);
