<?php

declare(strict_types=1);

use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Sync\Public\Services\BlindIndexCodec;

// `transactions.counterparty_normalized` and `merchants.normalized_name` are
// compared against each other and sit inside `transactions_fingerprint_uq`. A
// second producer that derived a value some other way — or skipped the key
// entirely — would store two forms of one merchant inside that index, and
// re-importing the statement that made the first would insert the second. The
// failure is silent: no exception, just a doubled ledger.
//
// So the rule is that every production file supplying a value for either column
// either goes through CounterpartyKey, or is pinned below as a pass-through
// that copies a value some other file already produced.
//
// @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md

/**
 * @return array<string, string> repo-relative path => why it may write without CounterpartyKey
 */
function counterpartyKeyPassThroughs(): array
{
    return [
        'Modules/Ledger/Public/Dto/CanonicalTransaction.php' => 'The DTO copies its own already-produced value into each wither and into toAttributes().',
        'Modules/Ledger/Internal/Services/FingerprintRederiveService.php' => 'Reads the stored value back and echoes it into a canonical to recompose the fingerprint over it.',
        'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php' => 'The enable-time sweep, which derives under a key handed to it rather than resolved from a session.',
        'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php' => 'Echoes the stored value back to recompose a fingerprint; never re-derives from a name.',
        'Modules/Counterparties/Database/Seeders/Demo/DemoCounterpartiesSeeder.php' => 'Copies the value off an existing transactions row.',
        'Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php' => 'Re-publishes an existing merchants row to the op-log verbatim.',
        'Modules/Recurring/Internal/Detectors/ExpenseSeriesDetector.php' => 'Keys an in-memory grouping array by the stored value; writes no column.',
        'Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php' => 'Keys an in-memory grouping array by the stored value; writes no column.',
        'Modules/Migration/Internal/Pipeline/StagingWriter.php' => 'migration_staging_payees.normalized_name is a different column holding the raw payee name.',
        'Modules/Sync/Internal/Config/MergeRulesRegistry.php' => 'Names the column in a merge rule; supplies no value.',
        'Modules/Recurring/Internal/Detectors/SeriesRefresher.php' => 'Writes back the cluster key its caller produced; never derives one.',
    ];
}

// Derived from the registry rather than spelled out here, so a fourth
// blind-index column is guarded the moment it is declared.
/**
 * @return list<string>
 */
function counterpartyKeyWriteMarkers(): array
{
    $markers = [];

    foreach (array_keys(BlindIndexCodec::indexedColumns()) as $qualified) {
        $column = substr($qualified, (int) strrpos($qualified, '.') + 1);
        $markers[] = "'".$column."' =>";
        $markers[] = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $column)))).':';
    }

    return $markers;
}

function counterpartyKeyIsWritten(string $source): bool
{
    foreach (counterpartyKeyWriteMarkers() as $marker) {
        if (str_contains($source, $marker)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function counterpartyKeyProductionFiles(): array
{
    $root = dirname((string) realpath(base_path('Modules')));
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/Modules', RecursiveDirectoryIterator::SKIP_DOTS),
    );

    $files = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace($root.'/', '', $file->getPathname());
        if (str_contains($relative, '/tests/') || str_contains($relative, '/Database/Migrations/')) {
            continue;
        }
        $files[] = $relative;
    }

    sort($files);

    return $files;
}

it('routes every production write of a counterparty matching key through CounterpartyKey', function (): void {
    $root = dirname((string) realpath(base_path('Modules')));
    $passThroughs = counterpartyKeyPassThroughs();

    $unrouted = [];
    foreach (counterpartyKeyProductionFiles() as $relative) {
        $source = (string) file_get_contents($root.'/'.$relative);

        if (! counterpartyKeyIsWritten($source) || str_contains($source, 'CounterpartyKey')) {
            continue;
        }

        if (! array_key_exists($relative, $passThroughs)) {
            $unrouted[] = $relative;
        }
    }

    sort($unrouted);

    expect($unrouted)->toBe(
        [],
        'A production file supplies a counterparty matching key without going through '
        .CounterpartyKey::class.'. Route it through the producer, or — if it only copies a value '
        ."another file already produced — pin it in counterpartyKeyPassThroughs() with the reason.\n  "
        .implode("\n  ", $unrouted),
    );
});

it('keeps the pass-through list honest: every pinned file still exists and still writes one', function (): void {
    $root = dirname((string) realpath(base_path('Modules')));

    $stale = [];
    foreach (counterpartyKeyPassThroughs() as $relative => $reason) {
        $path = $root.'/'.$relative;
        if (! is_file($path)) {
            $stale[] = $relative.' (file is gone)';

            continue;
        }

        if (! counterpartyKeyIsWritten((string) file_get_contents($path))) {
            $stale[] = $relative.' (no longer writes a matching key)';
        }

        if (trim($reason) === '') {
            $stale[] = $relative.' (no reason given)';
        }
    }

    expect($stale)->toBe([], "A pinned pass-through no longer describes reality. The list only shrinks.\n  ".implode("\n  ", $stale));
});

// A blind-index column compares and groups exactly as the plaintext did, so an
// equality predicate on one is correct and is the point. What silently stops
// working is anything that reads INSIDE the value: a LIKE, a substring, an
// alphabetical sort. None of those fail — they just return nothing, or order
// rows by a digest, forever.
//
// The registry's own list drives this, so a fourth column is covered the day
// it is declared.
/**
 * @return array<string, string> repo-relative path => why it may read inside one
 */
function counterpartyKeyOpaqueReadExemptions(): array
{
    return [
        'Modules/Sync/Public/Services/BlindIndexCodec.php' => 'Probes length() to ask whether any row is keyed at all; never reads the value.',
        'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php' => 'The sweep that converts these columns, which by definition reads the value it is replacing.',
    ];
}

it('never reads inside a blind-index column, because there is nothing in there to read', function (): void {
    $root = dirname((string) realpath(base_path('Modules')));
    $exempt = counterpartyKeyOpaqueReadExemptions();

    $columns = [];
    foreach (array_keys(BlindIndexCodec::indexedColumns()) as $qualified) {
        $columns[] = substr($qualified, (int) strrpos($qualified, '.') + 1);
    }

    $hits = [];
    foreach (counterpartyKeyProductionFiles() as $relative) {
        if (array_key_exists($relative, $exempt)) {
            continue;
        }

        $source = (string) file_get_contents($root.'/'.$relative);

        foreach ($columns as $column) {
            foreach (counterpartyKeyOpaqueReadShapes($column) as $label => $pattern) {
                if (preg_match($pattern, $source) === 1) {
                    $hits[] = $relative.' — '.$label.' on '.$column;
                }
            }
        }
    }

    sort($hits);

    expect($hits)->toBe(
        [],
        'A blind-index column holds a keyed one-way digest. Reading inside it does not fail loudly; '
        .'it returns nothing, or sorts by hex. Compare it whole, or read the plaintext from the '
        ."column that still has it.\n  ".implode("\n  ", $hits),
    );
});

/**
 * @return array<string, string> label => pattern
 */
function counterpartyKeyOpaqueReadShapes(string $column): array
{
    $quoted = preg_quote($column, '/');

    return [
        'LIKE' => '/[\'"]'.$quoted.'[\'"]\s*,\s*[\'"]like[\'"]/i',
        'raw LIKE' => '/whereRaw\([^)]*'.$quoted.'[^)]*\bLIKE\b/i',
        'orderBy' => '/->orderBy(?:Desc)?\(\s*[\'"](?:[a-z_]+\.)?'.$quoted.'[\'"]/i',
        'groupBy' => '/->groupBy\(\s*[\'"](?:[a-z_]+\.)?'.$quoted.'[\'"]/i',
        'substring' => '/(?:substr|mb_substr|str_starts_with|str_contains)\([^)]*'.$quoted.'/i',
    ];
}

it('keeps the two blind-index domains spelled the way stored digests were keyed', function (): void {
    // The domain is an input to the digest, so respelling one silently orphans
    // every value already stored under it: the column still reads, and every
    // comparison against it quietly stops matching.
    expect(CounterpartyKey::DOMAIN)->toBe('counterparty-normalized')
        ->and(CounterpartyKey::DOMAIN_IBAN)->toBe('counterparty-iban')
        ->and(CounterpartyKey::DOMAIN)->toBe(BlindIndexCodec::DOMAIN_COUNTERPARTY_NORMALIZED)
        ->and(CounterpartyKey::DOMAIN_IBAN)->toBe(BlindIndexCodec::DOMAIN_COUNTERPARTY_IBAN);
});
