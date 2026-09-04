<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// The two producers that put a row on the wire: an EntityMutated dispatch, and
// a direct OpLogWriter call from a capture listener. Both name their table as
// the first named argument, so the match IS the write site.
const CAPTURE_SITE_PATTERN = "/(?:new EntityMutated\(|->write[A-Za-z]+\()\s*table:\s*'([a-z_]+)'/";

// A table with merge rules but no capture syncs exactly once, in the initial
// backfill, and then diverges forever: both devices show the same history and
// disagree about the present. goals shipped that way until this test existed.

// Never on the wire, snapshot or incremental. They keep merge rules so a peer can
// still apply an op from an older build; the rules screen tells the user.
/**
 * @return list<string>
 */
function deviceLocalTables(): array
{
    return ['categorization_rules', 'rule_conditions', 'rule_actions'];
}

// Written only by migrations, seeders and the bundled corpus: no runtime writer
// means nothing to capture. They still travel — the backfill excludes only the
// device-local tables.
/**
 * @return list<string>
 */
function referenceDataTables(): array
{
    return ['categories'];
}

// ImportSyncCapture stopped naming its parents: it reads them off the live
// foreign keys of `transactions`, because the three it used to name by hand
// left out categories and a peer refused every transaction pointing at one.
// There is no literal left for the scan above to find, so the same derivation
// runs here — restating the list would put the drift back.
/**
 * @return list<string>
 */
function parentsCapturedByDerivation(): array
{
    $parents = array_values(app(CoveredTableOrder::class)->parentColumns('transactions'));

    // The applier seeds user_id from the local user, so ImportSyncCapture
    // deliberately does not emit the peer's users row; this must not claim it.
    return array_values(array_diff($parents, ['users']));
}

/** @return list<string> */
function snapshotOnlyTables(): array
{
    return [
        // Rewritten wholesale by a migration run; a partial op-log replay would
        // describe a state neither device was ever in.
        'migration_import_baseline',
        'migration_source_map',
    ];
}

// May only ever shrink: the tests below fail both when a table outside this list
// loses capture and when one inside it gains capture without being struck off.
/**
 * @return list<string>
 */
function uncapturedBacklog(): array
{
    return [];
}

// Empty because every detector-driven table now derives its id rather than
// minting one. `anomaly_alerts` went first, from (user_id, transaction_id) —
// the columns its own UNIQUE already names. `chain_links` followed on the
// (user, from, to, kind) tuple its insert helper always deduped on,
// `recurring_series_occurrences` and `drift_alerts` on their own UNIQUEs, and
// `savings_insight_dismissals` on (user_id, insight_key).

// `recurring_series` is the one that could not take its UNIQUE: `cluster_key`
// encodes the cadence band and SeriesRefresher rewrites it in place, so it
// derives from (user_id, direction, cluster_counterparty_key, latest_currency)
// — the tuple the detector's own cadence-flip fallback matches on.

/**
 * @return list<string>
 */
function capturedTables(): array
{
    $found = [];

    foreach ([dirname(__DIR__, 2).'/Internal/Listeners', base_path('Modules')] as $dir) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($file->getPathname(), '/tests/')) {
                continue;
            }

            $isListener = str_contains($file->getPathname(), '/Internal/Listeners/');
            $isDispatch = str_contains($source, 'new EntityMutated(');
            // Only callers count: the file defining captureRowsById() also names the
            // tables it EXCLUDES.
            $isBulk = str_contains($source, '->captureRowsById(');

            if (! $isListener && ! $isDispatch && ! $isBulk) {
                continue;
            }

            // The table name has to be the argument of an actual write, not
            // merely present somewhere in a file that also happens to contain
            // one. A bare `table: '…'` anywhere marked the whole table captured,
            // which is how merchant_aliases passed this gate with a single YAML
            // insert covering for four uncaptured user-facing writes.
            $matches = PatternScan::all(CAPTURE_SITE_PATTERN, $source);

            foreach ($matches[1] as $table) {
                $found[$table] = true;
            }

            if (! $isBulk) {
                continue;
            }

            // Only a list the file actually walks: counting every const array meant a
            // table struck out of the capture loop still read as captured.
            $lists = PatternScan::sets("/const (?:array\\s+)?([A-Z_]+) = \[([^\]]*)\];/", $source);

            foreach ($lists as $list) {
                if (! str_contains($source, 'foreach (self::'.$list[1])) {
                    continue;
                }

                $bulk = PatternScan::all("/'([a-z_]{3,})'/", $list[2]);

                foreach ($bulk[1] as $table) {
                    $found[$table] = true;
                }
            }
        }
    }

    return array_keys($found);
}

it('opens no new capture gap', function (): void {
    $syncable = array_keys(app(MergeRulesRegistry::class)->rules());

    $uncaptured = array_values(array_diff(
        $syncable,
        capturedTables(),
        parentsCapturedByDerivation(),
        snapshotOnlyTables(),
        deviceLocalTables(),
        referenceDataTables(),
        uncapturedBacklog(),
    ));

    expect($uncaptured)->toBe([], sprintf(
        "These tables have merge rules but nothing writes them to the op log, so an edit to one never leaves the device:\n  - %s",
        implode("\n  - ", $uncaptured),
    ));
});

it('strikes a table off the backlog as soon as it is captured', function (): void {
    $closed = array_values(array_intersect(uncapturedBacklog(), capturedTables()));

    expect($closed)->toBe([], sprintf(
        "These are captured now — remove them from uncapturedBacklog():\n  - %s",
        implode("\n  - ", $closed),
    ));
});

it('does not capture a table the merge registry cannot merge', function (): void {
    $syncable = array_keys(app(MergeRulesRegistry::class)->rules());

    // The reverse gap: ops the peer has no rules for are quarantined on
    // arrival, so capture and merge have to agree in both directions.
    expect(array_values(array_diff(capturedTables(), $syncable)))->toBe([]);
});

it('never captures a table that is meant to stay on the device', function (): void {
    $leaked = array_values(array_intersect(deviceLocalTables(), capturedTables()));

    expect($leaked)->toBe([], sprintf(
        "These are device-local and the rules screen says so, but something now writes them to the op log:\n  - %s",
        implode("\n  - ", $leaked),
    ));
});

// The tightening itself. A table used to count as captured because SOME file
// containing `new EntityMutated(` also contained its name somewhere — one line
// satisfying the gate for every write site in the codebase.
it('counts a table as captured only where a write actually names it', function (string $source, array $expected): void {
    $matches = PatternScan::all(CAPTURE_SITE_PATTERN, $source);

    expect(array_values(array_unique($matches[1])))->toBe($expected);
})->with([
    'an EntityMutated dispatch' => ["new EntityMutated(\n    table: 'merchant_aliases',\n    pk: 1,\n)", ['merchant_aliases']],
    'an OpLogWriter call' => ["\$writer->writeSet(\n    table: 'goals',\n    pk: 1,\n)", ['goals']],
    'a name mentioned beside a write of another table' => [
        "// Unlike table: 'merchant_aliases', this one is captured.\nnew EntityMutated(\n    table: 'goals',\n);",
        ['goals'],
    ],
    'a named argument to something that is not a write' => [
        "new EntityMutated(\n    table: 'goals',\n);\n\$this->preview(table: 'merchant_aliases');",
        ['goals'],
    ],
]);

// A stale entry on an excuse list silently widens the exemption, which is how a
// gap becomes permanent.
it('keeps every excuse pointing at a real syncable table', function (): void {
    $syncable = array_keys(app(MergeRulesRegistry::class)->rules());
    $excused = array_merge(snapshotOnlyTables(), deviceLocalTables(), referenceDataTables(), uncapturedBacklog());

    expect(array_values(array_diff($excused, $syncable)))->toBe([]);
});

// The reference-data excuse only holds while nothing writes these at runtime.
// A user-facing writer would make them per-device data that silently diverges.
it('keeps reference data free of any runtime writer', function (string $table): void {
    $writers = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // Migrations and seeders are how reference data is meant to arrive.
        if (preg_match('#/(tests|Database/Migrations|Database/Seeders)/#', $path) === 1) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (preg_match("/table\('{$table}'\)\s*->\s*(insert|update|delete|upsert)/", $source) === 1) {
            $writers[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($writers)->toBe([], sprintf(
        "%s is excused from capture as reference data, but these write it at runtime:\n  - %s",
        $table,
        implode("\n  - ", $writers),
    ));
})->with(['categories']);
