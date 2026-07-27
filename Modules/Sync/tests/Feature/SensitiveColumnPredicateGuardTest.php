<?php

declare(strict_types=1);

use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;

/**
 * D-09 durable source-scanning guard, structurally mirroring
 * OpLogRebuilderTest's "confines DROP TRIGGER..." IN-01 grep-guard
 * (RecursiveIteratorIterator scan + curated allowlist + toBe([])).
 *
 * Scans production `Modules/**\/*.php` for, per {@see SensitiveFieldRegistry::columns()}
 * bare column name: a `where`/`whereIn` predicate, an `orderBy`/`groupBy`,
 * a join `->on(...)`, a `whereRaw(...LIKE...)`, a `json_decode(...)` raw
 * read, or a raw `update`/`insert` array write — unless the same file
 * routes through `SensitiveColumnCodec`/`decryptValue`/`encryptValue`/
 * `encryptAttrs`. Offenders not on {@see sensitiveColumnGuardAllowlist()}
 * fail the build.
 *
 * A higher-precision follow-up — an AST-based custom PHPStan rule modeled
 * on `app/PhpStan/Rules/BoundaryRule.php` (matching `MethodCall` nodes with
 * a sensitive string-literal argument instead of a coarse substring scan)
 * — is deferred to a future phase; this source-scan is the pragmatic
 * backstop for now.
 *
 * To add an allowlist entry: confirm the site is genuinely safe (a
 * different, non-sensitive table sharing the bare column name; a
 * `whereNull`/`whereNotNull` presence check; or an already-decrypting
 * reader the codec-marker heuristic missed), then add
 * `'relative/path.php' => 'reason'` to
 * `sensitive-column-guard-allowlist.php`. Never allowlist a genuinely
 * broken site — fix it instead.
 *
 * @link ../../../../.docs/conventions/00-index.md
 */

/** @return list<string> bare column names derived from the registry's {table}.{column} pairs */
function sensitiveColumnGuardBareColumns(): array
{
    $bare = [];
    foreach (SensitiveFieldRegistry::columns() as $pair) {
        [, $column] = explode('.', $pair, 2);
        $bare[$column] = true;
    }

    return array_keys($bare);
}

/** @return list<string> markers proving a file already routes sensitive values through the codec */
function sensitiveColumnGuardCodecMarkers(): array
{
    return ['SensitiveColumnCodec', 'decryptValue', 'encryptValue', 'encryptAttrs'];
}

/**
 * @param  list<string>  $columns
 * @return list<string> matched offense descriptions, empty when clean
 */
function sensitiveColumnGuardScanContents(string $contents, array $columns): array
{
    $hits = [];

    foreach ($columns as $col) {
        $q = preg_quote($col, '/');

        if (preg_match('/->where(?:In)?\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: where/whereIn predicate";
        }
        if (preg_match('/->orderBy(?:Desc)?\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: orderBy";
        }
        if (preg_match('/->groupBy\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: groupBy";
        }
        if (preg_match('/->on\([^)]*'.$q.'[^)]*\)/', $contents)) {
            $hits[] = "{$col}: join ->on()";
        }
        if (preg_match('/whereRaw\([^)]*'.$q.'[^)]*LIKE/i', $contents)) {
            $hits[] = "{$col}: whereRaw(...LIKE)";
        }
        if (preg_match('/json_decode\([^)]*'.$q.'/', $contents)) {
            $hits[] = "{$col}: json_decode";
        }
        if (preg_match_all('/->(update|insert)\s*\(([\s\S]{0,600}?)\)\s*;/', $contents, $matches)) {
            foreach ($matches[2] as $i => $block) {
                if (preg_match('/[\'"]'.$q.'[\'"]\s*=>/', $block)) {
                    $hits[] = "{$col}: raw {$matches[1][$i]}()";
                }
            }
        }
    }

    return $hits;
}

/** @return list<string> absolute paths to in-scope production PHP files under Modules/ */
function sensitiveColumnGuardProductionFiles(): array
{
    $root = dirname(__DIR__, 4).'/Modules';
    $files = [];
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($rii as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/') || str_contains($path, '/Resources/')) {
            continue;
        }
        $files[] = $path;
    }

    return $files;
}

/** @return array<string, string> repo-relative path => allowlist reason */
function sensitiveColumnGuardAllowlist(): array
{
    /** @var array<string, string> $allowlist */
    $allowlist = require __DIR__.'/sensitive-column-guard-allowlist.php';

    return $allowlist;
}

it('has zero uncoded sensitive-column predicate/read/write offenders across production Modules/**/*.php (D-09)', function (): void {
    $columns = sensitiveColumnGuardBareColumns();
    $markers = sensitiveColumnGuardCodecMarkers();
    $allowlist = sensitiveColumnGuardAllowlist();
    $repoRoot = dirname(__DIR__, 4).'/';

    $offenders = [];
    foreach (sensitiveColumnGuardProductionFiles() as $path) {
        $relative = str_replace($repoRoot, '', $path);
        if (array_key_exists($relative, $allowlist)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        $hasCodec = false;
        foreach ($markers as $marker) {
            if (str_contains($contents, $marker)) {
                $hasCodec = true;
                break;
            }
        }
        if ($hasCodec) {
            continue;
        }

        $hits = sensitiveColumnGuardScanContents($contents, $columns);
        if ($hits !== []) {
            $offenders[$relative] = $hits;
        }
    }

    expect($offenders)->toBe([]);
});

it('goes RED on a deliberately introduced uncoded predicate on a sensitive column (negative probe, in-memory)', function (): void {
    $columns = sensitiveColumnGuardBareColumns();

    $violation = <<<'PHP'
        <?php

        final class ScratchProbe
        {
            public function broken(): void
            {
                DB::table('transactions')->where('counterparty_iban', request('iban'))->first();
            }
        }
        PHP;

    $hits = sensitiveColumnGuardScanContents($violation, $columns);

    expect($hits)->toContain('counterparty_iban: where/whereIn predicate');
});

it('goes RED on a scratch on-disk production-shaped file with an uncoded predicate, then cleans up (negative probe, filesystem)', function (): void {
    $columns = sensitiveColumnGuardBareColumns();
    $markers = sensitiveColumnGuardCodecMarkers();

    $scratchPath = tempnam(sys_get_temp_dir(), 'sensitive_guard_probe_');
    expect($scratchPath)->not->toBeFalse();
    rename($scratchPath, $scratchPath .= '.php');

    file_put_contents($scratchPath, <<<'PHP'
        <?php

        final class ScratchProbe
        {
            public function broken(): void
            {
                DB::table('counterparties')->where('merchant_name', request('name'))->first();
            }
        }
        PHP);

    try {
        $contents = (string) file_get_contents($scratchPath);

        $hasCodec = false;
        foreach ($markers as $marker) {
            if (str_contains($contents, $marker)) {
                $hasCodec = true;
                break;
            }
        }

        $hits = $hasCodec ? [] : sensitiveColumnGuardScanContents($contents, $columns);

        expect($hasCodec)->toBeFalse();
        expect($hits)->toContain('merchant_name: where/whereIn predicate');
    } finally {
        unlink($scratchPath);
    }
});

it('does not allowlist any site whose reason claims it is broken (allowlist honesty check)', function (): void {
    $bannedReasonTokens = ['broken', 'known-broken', 'known broken', 'unfixed', 'TODO', 'FIXME'];

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $path => $reason) {
        foreach ($bannedReasonTokens as $token) {
            if (stripos($reason, $token) !== false) {
                $offenders[] = "{$path} => {$reason}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
