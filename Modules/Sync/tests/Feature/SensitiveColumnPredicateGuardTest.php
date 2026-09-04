<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;

/** @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md */

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

        if (PatternScan::matches('/->where(?:In)?\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: where/whereIn predicate";
        }
        if (PatternScan::matches('/->orderBy(?:Desc)?\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: orderBy";
        }
        if (PatternScan::matches('/->groupBy\(\s*[\'"]'.$q.'[\'"]/', $contents)) {
            $hits[] = "{$col}: groupBy";
        }
        if (PatternScan::matches('/->on\([^)]*'.$q.'[^)]*\)/', $contents)) {
            $hits[] = "{$col}: join ->on()";
        }
        if (PatternScan::matches('/whereRaw\([^)]*'.$q.'[^)]*LIKE/i', $contents)) {
            $hits[] = "{$col}: whereRaw(...LIKE)";
        }
        if (PatternScan::matches('/json_decode\([^)]*'.$q.'/', $contents)) {
            $hits[] = "{$col}: json_decode";
        }

        $writes = PatternScan::all('/->(update|insert)\s*\(([\s\S]{0,600}?)\)\s*;/', $contents);
        foreach ($writes[2] as $i => $block) {
            if (PatternScan::matches('/[\'"]'.$q.'[\'"]\s*=>/', $block)) {
                $hits[] = "{$col}: raw {$writes[1][$i]}()";
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

/**
 * The allowlist exempts a file by asserting that its bare-column hit belongs to
 * a DIFFERENT, plaintext column than the registry-listed one that triggered the
 * scan — `accounts.iban` where the registry lists `counterparties.iban`. That
 * claim is only checkable if the reason names the column, so pull it back out.
 *
 * @return list<string> the {table}.{column} pairs an allowlist reason cites
 */
function sensitiveColumnGuardReasonColumns(string $reason): array
{
    $matches = PatternScan::all('/\b[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\b/', $reason);

    return array_values(array_unique($matches[0]));
}

/** @return array<string, string> repo-relative path => allowlist reason */
function sensitiveColumnGuardAllowlist(): array
{
    /** @var array<string, string> $allowlist */
    $allowlist = require __DIR__.'/sensitive-column-guard-allowlist.php';

    return $allowlist;
}

it('has zero uncoded sensitive-column predicate/read/write offenders across production Modules/**/*.php', function (): void {
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

// The landmine this pair of lists exists to disarm. The guard skips an
// allowlisted file BEFORE scanning it, so the six `accounts.iban` exemptions
// would silently cover the six most dangerous predicates in the codebase the
// moment `accounts.iban` joined the registry — and the honesty check above
// cannot see it, because it only greps reasons for broken/TODO/FIXME.

it('never allowlists a file whose stated reason cites a column the registry has since started encrypting', function (): void {
    $encrypted = SensitiveFieldRegistry::columns();

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $path => $reason) {
        foreach (sensitiveColumnGuardReasonColumns($reason) as $cited) {
            if (in_array($cited, $encrypted, true)) {
                $offenders[] = "{$path} rests on {$cited} being plaintext, and SensitiveFieldRegistry now encrypts it";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('only lets an allowlist reason rest on a column whose plaintext status is a recorded decision', function (): void {
    $decided = array_keys(SensitiveFieldRegistry::knowinglyPlaintext());

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $path => $reason) {
        foreach (sensitiveColumnGuardReasonColumns($reason) as $cited) {
            if (! in_array($cited, $decided, true)) {
                $offenders[] = "{$path} rests on {$cited}, which SensitiveFieldRegistry::knowinglyPlaintext() does not record";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps the encrypted list and the knowingly-plaintext list disjoint', function (): void {
    $overlap = array_intersect(
        SensitiveFieldRegistry::columns(),
        array_keys(SensitiveFieldRegistry::knowinglyPlaintext()),
    );

    expect(array_values($overlap))->toBe([]);
});

it('goes RED when a knowingly-plaintext column joins the registry while its exemptions stand (negative probe, in-memory)', function (): void {
    // The exact regression: `accounts.iban` promoted to the encrypted list
    // without the allowlist entries that rest on it being deleted.
    $encrypted = [...SensitiveFieldRegistry::columns(), 'accounts.iban'];

    $offenders = [];
    foreach (sensitiveColumnGuardAllowlist() as $path => $reason) {
        foreach (sensitiveColumnGuardReasonColumns($reason) as $cited) {
            if (in_array($cited, $encrypted, true)) {
                $offenders[] = $path;
            }
        }
    }

    // Named rather than counted: every entry resting on the promoted column has
    // to be the one reported, and a count says nothing about which. It also
    // stops a seventh exemption failing this probe for having been added.
    $restingOnPromoted = array_keys(array_filter(
        sensitiveColumnGuardAllowlist(),
        static fn (string $reason): bool => in_array('accounts.iban', sensitiveColumnGuardReasonColumns($reason), true),
    ));

    expect($restingOnPromoted)->not->toBe([])
        ->and($offenders)->toBe($restingOnPromoted);
});
