<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Modules\Sync\Tests\Support\CaptureSites;

uses(RefreshDatabase::class);

// Every assertion in SyncCaptureCoverageTest starts from the merge registry, so
// a table with NO merge rules is checked by nothing: it cannot open a capture
// gap, it is on no backlog, and the excuse lists there only ever narrow. A new
// user-owned table therefore ships device-local in silence, which is how
// anomaly_suppression_rules and savings_insight_dismissals got here. This file
// starts from the live schema instead, so the default for a new table is that
// somebody has to say what it is.

// No owning module and no user column: nothing here is one reader's data.
const SYNC_COVERAGE_FRAMEWORK_TABLES = [
    'migrations',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'password_reset_tokens',
];

// The transport. Every one of these describes this device's part in the
// replication, so putting it on the wire is either circular or a lie about
// which device did what.
/**
 * @return array<string, string>
 */
function syncMachineryTables(): array
{
    return [
        'op_log_entries' => 'The op log itself; capturing it would put every op on the wire a second time.',
        'op_log_quarantine' => 'Ops this device refused. What a peer does with its own refusals is its own answer.',
        'op_log_row_aliases' => 'Which local id a peer id means HERE. The mapping is between two devices, and the peer already knows its own half.',
        'hlc_clock_state' => 'This device\'s clock. Two devices sharing one counter is the ordering bug the HLC exists to prevent.',
        'sync_encryption_state' => 'Per-device key state. The group key is established by pairing, never replayed as a row.',
        'sync_sessions' => 'One live connection, known only to the device holding it.',
        'sync_peer_catch_up_state' => 'How far THIS device has caught a peer up; replayed onto that peer it is a cursor into a log it does not have.',
        'device_registry' => 'Who this device has paired with. Trust is established by the ceremony, not by an op that arrives claiming it.',
        'device_introductions' => 'Keys a peer vouched for and this reader confirmed. Syncing the confirmation would make one reader\'s decision every reader\'s, which is the ceremony this act deliberately replaces.',
        'pairing_tokens' => 'A short-lived secret for one ceremony; a copy on a second device widens the window it exists to narrow.',
        'mobile_sync_progress' => 'The phone\'s own progress through its first sync.',
        'mobile_import_intent' => 'A handoff between two screens of this install.',
        'sync_withheld_history' => 'What each peer told THIS device it was holding back. The peer already knows its own half, and replaying it would report one device\'s narrowing as another\'s.',
        'sync_backfill_state' => 'How far THIS device has walked its own pre-sync history; a peer has its own rows and its own walk.',
        'deferred_op_captures' => 'Coordinates THIS device could not sign yet. Replaying them onto a peer would ask it to announce a change it never made.',
    ];
}

// Credentials and the state that hangs off them. Each is issued to one device
// and does nothing on another, so replication would move a secret for no gain.
/**
 * @return array<string, string>
 */
function deviceSecretTables(): array
{
    return [
        'oauth_secrets' => 'Mailbox tokens issued to this device; a peer holding them still cannot use them.',
        'sessions' => 'The framework session table. A signed-in session belongs to the browser holding its cookie.',
        'user_recovery_codes' => 'Shown once at setup. A replicated copy is a second copy of a single-use secret.',
        'user_app_lock_configs' => 'The lock on THIS screen: a phone in a pocket and a desktop at home do not want one setting.',
        'user_biometric_credentials' => 'A platform authenticator bound to this device\'s hardware.',
        'open_banking_connections' => 'The bank session lives in a chmod-600 file keyed to this device AND this reader; the row without it is a connection nobody can fetch.',
    ];
}

// The row names something only the device that wrote it has. A reader editing
// one of these is editing this device's copy, which is why the waiver list
// below exists rather than the writer being a finding.
/**
 * @return array<string, string>
 */
function deviceLocalByDesignTables(): array
{
    return [
        'inboxes' => 'The OAuth token is a per-device file on disk, so a mailbox is connected once per device and disconnecting it here cannot disconnect it there.',
        'inbox_messages' => 'Hangs off a per-device inbox id, and the message body is a file on this disk.',
        'inbox_scan_state' => 'A provider cursor into a per-device inbox; a peer\'s history id means nothing against this device\'s connection.',
        'file_imports' => 'Names a receipt file staged on this device\'s filesystem.',
        'system_alert_acknowledgements' => 'Written only for a system-wide alert, which is about the machine that noticed the fault and never leaves it.',
        'wizard_progress' => 'The phone joins by pairing rather than by signing up and seeds its own steps; a finished desktop must not skip it through a setup it never ran.',
        'mobile_notification_grant' => 'What one operating system answered about showing this install\'s notifications; a peer\'s grant permits nothing here, and a refusal there would silence a device that was allowed.',
    ];
}

// A user-facing writer on a device-local table is usually this bug class: a
// decision the reader made, kept on one device. Each of these is the exception
// — the row it writes cannot exist on the other device at all — and saying so
// is the price of the writer.
/**
 * @return array<string, string>
 */
function deviceLocalWriterWaivers(): array
{
    return [
        'inboxes @ Modules/EmailScan/Internal/Http/Livewire/BackfillWindowModal.php' => 'How far back to fetch, for the mailbox this device holds a token for.',
        'inboxes @ Modules/EmailScan/Public/Actions/DisconnectInbox.php' => 'Disconnecting drops this device\'s token and its row; the peer keeps its own connection and its own token.',
        'file_imports @ Modules/Receipts/Public/Actions/RecordReceipt.php' => 'Records a file this device just staged, keyed by a path only this device has.',
        'system_alert_acknowledgements @ Modules/Core/Public/Actions/AcknowledgeSystemAlert.php' => 'Only reached for a system-wide alert; an owned alert stamps its own column and that one does travel.',
        'wizard_progress @ Modules/Onboarding/Internal/Http/Livewire/SetupWizard.php' => 'Marks the step this device finished, for a wizard the other device never runs.',
        'wizard_progress @ Modules/Onboarding/Internal/Http/Livewire/Steps/FirstImportStep.php' => 'Same wizard, closing the first-import step once this device has committed its runs.',
    ];
}

// An output, not a decision: the code that owns each of these rebuilds it on
// the device that needs it, from the transactions and the files that device
// holds. Replaying one would describe work a peer never did.
/**
 * @return array<string, string>
 */
function derivedFromSyncedInputTables(): array
{
    return [
        'anomaly_alert_transitions' => 'The audit trail behind an anomaly_alerts row; the state a screen reads travels on the alert itself.',
        'drift_alert_transitions' => 'Same shape for drift_alerts: each device\'s detector writes its own trail.',
        'recurring_series_transitions' => 'Same shape for recurring_series, whose detector runs per device.',
        'card_statements' => 'Upserted from the statement_summaries rows an import wrote, on whichever device read the statement.',
        'card_statement_credits' => 'Read out of the same statement as its card_statements parent.',
        'statement_summaries' => 'Statement-level metadata lifted from the file being imported; there is nothing to lift on a device that never read it.',
        'chain_resolution_runs' => 'A record of a job this device ran, polled by the import-results screen on that device.',
        'forecast_runs' => 'A record of a projection this device computed from transactions that do travel.',
        'forecast_shortfall_windows' => 'The output of that projection, recomputed by each device\'s own run.',
        'transaction_search_docs' => 'The search index over transactions; rebuilt locally, never a source of truth.',
        'search_index_repairs' => 'The transactions whose index body a keyless process here could not build. A peer that could read them has nothing to repair, and the coordinate is spent the moment this device rebuilds one.',
        'ledger_backfill_state' => 'How far this device has got through its own backfill.',
    ];
}

// One importer run's scratch space. The rows a run promotes into the domain are
// captured there; the staging copy is working memory, discarded after.
/**
 * @return array<string, string>
 */
function migrationStagingTables(): array
{
    return [
        'migration_runs' => 'The run record for an import this device performed; what it produced travels as domain rows.',
        'migration_staging_accounts' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_budget_assignments' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_categories' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_goals' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_payees' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_transactions' => 'Parsed rows awaiting promotion, discarded once promoted.',
        'migration_staging_unmapped_items' => 'What the run could not map, reported against that run and no other.',
    ];
}

// Shipped with the build or written by a migration, identical on every device
// that installed the same version.
/**
 * @return array<string, string>
 */
function shippedReferenceTables(): array
{
    return [
        'known_counterparty_ibans' => 'The bundled institution-IBAN corpus, seeded by migration on every install.',
        'community_merchant_mappings' => 'The shipped merchant corpus, replaced wholesale by an update rather than edited.',
    ];
}

// Should travel and does not. May only ever shrink: an entry here is a gap
// somebody has looked at and could not close yet, never a decision to leave it.
/**
 * @return array<string, string>
 */
function syncCoverageBacklogTables(): array
{
    return [
        'discovered_senders' => 'Dismissing a sender is the reader\'s decision and DiscoveryScanJob already excludes per user rather than per inbox, so only the row\'s identity blocks it: inbox_id is NOT NULL against a table that exists once per device, so the create names a parent the peer does not hold. Blocked until the dismissal is stored against the address rather than against one device\'s inbox.',
        'pending_enrichment_conflicts' => 'A receipt-versus-statement question the reader has to answer, raised on the device that ran the import; answering it on the desktop leaves the phone still asking. Needs merge rules plus an id from its own UNIQUE(user_id, transaction_id, field_name).',
    ];
}

/**
 * @return array<string, array<string, string>>
 */
function syncCoverageBuckets(): array
{
    return [
        'syncMachineryTables' => syncMachineryTables(),
        'deviceSecretTables' => deviceSecretTables(),
        'deviceLocalByDesignTables' => deviceLocalByDesignTables(),
        'derivedFromSyncedInputTables' => derivedFromSyncedInputTables(),
        'migrationStagingTables' => migrationStagingTables(),
        'shippedReferenceTables' => shippedReferenceTables(),
        'syncCoverageBacklogTables' => syncCoverageBacklogTables(),
    ];
}

/**
 * @return list<string>
 */
function syncCoverageDeclaredExemptions(): array
{
    $declared = [];

    foreach (syncCoverageBuckets() as $tables) {
        foreach (array_keys($tables) as $table) {
            $declared[] = $table;
        }
    }

    return $declared;
}

// One reader's data, read off the schema rather than off a list somebody
// maintains: a user_id column, `users` itself, and the tables the backfiller
// scopes through a parent because they carry no user_id of their own.
/**
 * @return list<string>
 */
function syncCoverageUserOwnedTables(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $parentScope = (new ReflectionClass(OpLogBackfiller::class))->getReflectionConstant('PARENT_SCOPE');

    if ($parentScope === false) {
        // Silently reading [] here would drop rule_conditions and rule_actions
        // out of scope, and the guard would go green over a hole it opened.
        throw new RuntimeException('OpLogBackfiller::PARENT_SCOPE is gone; this test scopes ownership through it.');
    }

    /** @var array<string, array{0: string, 1: string}> $parentScoped */
    $parentScoped = $parentScope->getValue();

    $owned = [];

    foreach ($schema->getTables() as $table) {
        $name = $table['name'];

        if (str_starts_with($name, 'sqlite_') || in_array($name, SYNC_COVERAGE_FRAMEWORK_TABLES, true)) {
            continue;
        }

        $columns = array_column($schema->getColumns($name), 'name');

        if (in_array('user_id', $columns, true) || $name === 'users' || isset($parentScoped[$name])) {
            $owned[] = $name;
        }
    }

    sort($owned);

    return array_values($owned);
}

// A write is only a write where the chain that names the table ends in one. The
// statement is read to its terminating semicolon rather than one line at a
// time, or every `->table('x')` followed by a `->where()` reads as a lookup.
/**
 * @return list<string> repo-relative paths, one per writing file
 */
function syncCoverageUserFacingWritersOf(string $table): array
{
    $writers = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('#/(Public/Actions|Internal/Http/Livewire)/#', $path) !== 1) {
            continue;
        }

        $source = (string) file_get_contents($path);

        $statements = PatternScan::all("/table\('".$table."'\)(.*?);/s", $source);

        foreach ($statements[1] as $tail) {
            if (preg_match('/->\s*(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|forceDelete|truncate|increment|decrement)\s*\(/', $tail) !== 1) {
                continue;
            }

            $writers[] = str_replace(base_path().'/', '', $path);
        }
    }

    return array_values(array_unique($writers));
}

it('declares every user-owned table as either syncable or exempt', function (): void {
    $undeclared = array_values(array_diff(
        syncCoverageUserOwnedTables(),
        array_keys(app(MergeRulesRegistry::class)->rules()),
        syncCoverageDeclaredExemptions(),
    ));

    expect($undeclared)->toBe([], sprintf(
        "These tables hold one reader's data and nothing says what happens to them on a second device, "
        ."so they ship device-local without anything noticing:\n  - %s\n"
        .'Give the table merge rules, or declare it in one of the named buckets in this file with the reason it stays here.',
        implode("\n  - ", $undeclared),
    ));
});

it('keeps every exemption pointing at a table the schema still has', function (): void {
    $stale = array_values(array_diff(syncCoverageDeclaredExemptions(), syncCoverageUserOwnedTables()));

    expect($stale)->toBe([], sprintf(
        'These are excused from capture and the live schema has no such user-owned table, so the excuse '
        ."is widening the list rather than narrowing it:\n  - %s",
        implode("\n  - ", $stale),
    ));
});

it('drops an exemption the merge registry has since covered', function (): void {
    $covered = array_values(array_intersect(
        syncCoverageDeclaredExemptions(),
        array_keys(app(MergeRulesRegistry::class)->rules()),
    ));

    expect($covered)->toBe([], sprintf(
        'These have merge rules now, so the sentence excusing them here is describing a decision that was '
        ."reversed. Delete the line; SyncCaptureCoverageTest owns them from here:\n  - %s",
        implode("\n  - ", $covered),
    ));
});

it('keeps the exemption buckets disjoint', function (): void {
    $seen = [];
    $twice = [];

    foreach (syncCoverageBuckets() as $bucket => $tables) {
        foreach (array_keys($tables) as $table) {
            if (isset($seen[$table])) {
                $twice[] = $table.' — '.$seen[$table].' and '.$bucket;
            }

            $seen[$table] = $bucket;
        }
    }

    expect($twice)->toBe([], sprintf(
        "A table excused twice has two reasons, and removing one of them leaves it excused by the other:\n  - %s",
        implode("\n  - ", $twice),
    ));
});

it('gives every exemption a reason', function (): void {
    $unreasoned = [];

    foreach (syncCoverageBuckets() as $bucket => $tables) {
        foreach ($tables as $table => $reason) {
            if (trim($reason) === '') {
                $unreasoned[] = $bucket.'.'.$table;
            }
        }
    }

    foreach (deviceLocalWriterWaivers() as $writer => $reason) {
        if (trim($reason) === '') {
            $unreasoned[] = $writer;
        }
    }

    expect($unreasoned)->toBe([], sprintf(
        "A bare entry is a rubber stamp: the next reader cannot tell an argument from an oversight.\n  - %s",
        implode("\n  - ", $unreasoned),
    ));
});

it('never declares a table device-local that something already writes to the op log', function (): void {
    $leaked = array_values(array_intersect(array_keys(deviceLocalByDesignTables()), CaptureSites::tables()));

    expect($leaked)->toBe([], sprintf(
        'These are declared device-local here and something now captures them, so the two files disagree '
        ."about where the row lives:\n  - %s",
        implode("\n  - ", $leaked),
    ));
});

// The tell that a "device-local" table is really a decision kept on one device
// is a reader editing it. Each writer that exists has to be named with the
// reason its row cannot exist on the other device at all.
it('lets a reader edit a device-local table only where the waiver says why', function (string $table): void {
    $undeclared = [];

    foreach (syncCoverageUserFacingWritersOf($table) as $writer) {
        if (! array_key_exists($table.' @ '.$writer, deviceLocalWriterWaivers())) {
            $undeclared[] = $table.' @ '.$writer;
        }
    }

    expect($undeclared)->toBe([], sprintf(
        '%s is declared device-local, and these write it from a screen or an action the reader reaches. '
        .'Either the write is a decision that should travel, or add the line to deviceLocalWriterWaivers() '
        ."with the reason the row cannot exist on the other device:\n  - %s",
        $table,
        implode("\n  - ", $undeclared),
    ));
})->with(array_keys(deviceLocalByDesignTables()));

it('keeps every writer waiver pointing at a write that is still there', function (): void {
    $found = [];

    foreach (array_keys(deviceLocalByDesignTables()) as $table) {
        foreach (syncCoverageUserFacingWritersOf($table) as $writer) {
            $found[] = $table.' @ '.$writer;
        }
    }

    $stale = array_values(array_diff(array_keys(deviceLocalWriterWaivers()), $found));

    expect($stale)->toBe([], sprintf(
        'These waivers name a write that no longer exists, so the next one added to that file would be '
        ."waved through by a sentence written about something else:\n  - %s",
        implode("\n  - ", $stale),
    ));
});
