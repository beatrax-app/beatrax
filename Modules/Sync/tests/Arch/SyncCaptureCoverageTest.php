<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

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
    return [
        // All four are detector-driven, and each device mints its own local id for
        // the same logical row: the idempotency UNIQUE drops the second create, and
        // that device's later SETs then name a pk it does not hold. Blocked until
        // each has an identity both devices compute.
        'chain_links',
        'recurring_series',
        'recurring_series_occurrences',
        'drift_alerts',
    ];
}

// `anomaly_alerts` came off that list by deriving its id from (user_id,
// transaction_id) — the columns its own UNIQUE already names, neither of which
// ever moves. The four left have no such settled identity yet.

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

            preg_match_all("/table: '([a-z_]+)'/", $source, $matches);

            foreach ($matches[1] as $table) {
                $found[$table] = true;
            }

            if (! $isBulk) {
                continue;
            }

            // Only a list the file actually walks: counting every const array meant a
            // table struck out of the capture loop still read as captured.
            preg_match_all("/const ([A-Z_]+) = \[([^\]]*)\];/", $source, $lists, PREG_SET_ORDER);

            foreach ($lists as $list) {
                if (! str_contains($source, 'foreach (self::'.$list[1])) {
                    continue;
                }

                preg_match_all("/'([a-z_]{3,})'/", $list[2], $bulk);

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
