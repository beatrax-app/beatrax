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
        'accounts',
        'categories',
        'categorization_rules',
        'counterparties',
        'import_runs',
        'merchant_aliases',
        'merchant_memories',
        'merchants',
        'rule_actions',
        'rule_conditions',
    ];
}

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

            if (! $isListener && ! $isDispatch) {
                continue;
            }

            preg_match_all("/table: '([a-z_]+)'/", $source, $matches);

            foreach ($matches[1] as $table) {
                $found[$table] = true;
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
it('keeps every excuse pointing at a real syncable table', function (): void {
    $syncable = array_keys(app(MergeRulesRegistry::class)->rules());
    $excused = array_merge(snapshotOnlyTables(), uncapturedBacklog());

    expect(array_values(array_diff($excused, $syncable)))->toBe([]);
});
