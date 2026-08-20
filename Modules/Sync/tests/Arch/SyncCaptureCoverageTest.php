<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;

/*
 * Every table the merge registry can sync must also have somewhere that
 * WRITES it to the op log. The two lists drifted apart silently: goals had
 * merge rules, shipped in the pairing snapshot, and had no capture at all, so
 * a goal created after pairing never left the device that made it.
 *
 * A table with merge rules but no capture syncs exactly once — in the initial
 * backfill — and then diverges forever. That is worse than not syncing at all,
 * because both devices show the same history and disagree about the present.
 */

/**
 * Never put on the wire at all: not in the pairing snapshot, not
 * incrementally. They keep merge rules so a peer can still apply an op from an
 * older build, but nothing produces one. The rules screen tells the user.
 *
 * @return list<string>
 */
function deviceLocalTables(): array
{
    return ['categorization_rules', 'rule_conditions', 'rule_actions'];
}

/**
 * Reference data: written by migrations, seeders and the bundled corpus, never
 * by a user action, so every device derives the same rows from the same build.
 * That is the whole justification — no runtime writer means nothing to capture.
 * It is not that these rows never travel: the backfill excludes only the
 * device-local tables, so `categories` does reach a peer, and
 * RulesStayOnTheDeviceTest asserts exactly that.
 *
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
        // Rewritten wholesale by a migration run and never edited by hand; a
        // partial op-log replay of one would describe a state neither device
        // was ever in.
        'migration_import_baseline',
        'migration_source_map',
    ];
}

/**
 * Known gaps, still to be closed. This list may only ever SHRINK — the test
 * below fails both when a table outside it loses capture and when a table
 * inside it gains capture without being struck off, so it cannot quietly rot
 * into a permanent excuse.
 *
 * @return list<string>
 */
function uncapturedBacklog(): array
{
    return [
        // These have merge rules and travel in the pairing backfill, so a peer
        // no longer receives them empty, but no write path emits an incremental
        // op yet. All four are detector-driven and each is still waiting on an
        // identity both devices can compute — see the note below.
        'chain_links',
        'recurring_series',
        'recurring_series_occurrences',
        'drift_alerts',
    ];
}

/*
 * What holds the four above: both devices run the same detector, and each mints
 * its own local id for the same logical row. The idempotency UNIQUE then drops
 * whichever create arrives second, leaving that device's later SETs pointing at
 * a pk it does not hold.
 *
 * `anomaly_alerts` is the first table off this list. Its id is now derived from
 * (user_id, transaction_id) — the columns its own UNIQUE already names, neither
 * of which ever moves — so both devices compute the same number, the second
 * create collides harmlessly, and an edit lands on a row that exists.
 *
 * The four left are the ones whose identity is not settled yet. `chain_links`
 * has no UNIQUE at all. `recurring_series` has one built from columns the
 * detector rewrites in place, so it names nothing durable. The remaining two
 * are keyed through `recurring_series` and unblock only behind it.
 */

/**
 * Tables written to the op log: the literals in the Sync listeners, plus the
 * table named at every EntityMutated dispatch site across the modules.
 *
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
            // Bulk capture names its tables in a list rather than one call
            // each, so the whole file is scanned. Only CALLERS count — the
            // file that defines the method also names the tables it EXCLUDES.
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

            // Only a list the file actually WALKS. Counting every const array
            // meant a table struck out of the capture loop still read as
            // captured for as long as its name sat in the constant.
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

// Both excuse lists name real syncable tables. A stale entry silently widens
// the exemption, which is how a gap becomes permanent.
it('never captures a table that is meant to stay on the device', function (): void {
    $leaked = array_values(array_intersect(deviceLocalTables(), capturedTables()));

    expect($leaked)->toBe([], sprintf(
        "These are device-local and the rules screen says so, but something now writes them to the op log:\n  - %s",
        implode("\n  - ", $leaked),
    ));
});

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

        // Migrations and seeders are exactly how reference data is meant to
        // arrive; a demo seeder is not a user action either.
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
