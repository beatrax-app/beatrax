<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\UserOwnedRowReads;

/**
 * @link ../../.docs/conventions/arch-invariants.md#a-read-of-a-user-owned-row-carries-its-reader
 */

// The counterparties guard beside this one says the same thing about one table:
// an id reaching a read is not evidence that the row it names belongs to the
// reader asking. That rationale never depended on counterparties. Ids in this
// product are derived from row content, minted per device and carried across the
// wire; the foreign keys that would tie one back to an owner are deliberately
// absent, so `where('id', $id)` answers for whoever holds that row.
//
// TransactionRowDecorator::legsFor() was the live instance: it took the reader's
// id, spent it on a category join, and bounded the legs by transaction id alone.
// Nothing failed, because the id list was server-derived and the property
// holding it carries #[Locked]. One attribute on one property is not a scope.

// Which tables this covers is asked of the schema, never written down here. It
// is the same derivation UserScopedDataPurge makes when it empties an account:
// every table carrying a `user_id`, `users` itself excluded. A hand-kept list
// goes stale the first time a module adds a table, and it goes stale silently.

/**
 * Statements this rule has looked at one by one, each bounded by something a
 * statement-level reader cannot see. Keyed by file and table, counted exactly:
 * a second unscoped statement in one of these files fails here, and so does an
 * entry whose statement has gone.
 *
 * @var array<string, array{0: int, 1: string}>
 */
const OWNED_ROW_READ_TRIAGED = [
    'Modules/Anomaly/Internal/Console/SweepAnomalySafetyNetCommand.php::anomaly_backfill_state' => [1,
        'the owner column is the thing being read: the scheduled sweep enumerates the users on this device still owed the rest of their history.'],

    'Modules/Categorization/Internal/Jobs/ReapplyRulesJob.php::transaction_splits' => [1,
        'a NOT EXISTS correlated to transactions.id, inside the query three lines above it that names where(\'transactions.user_id\', $userId). It narrows that set and exposes no column of its own.'],

    'Modules/DevMode/Internal/Console/DemoSeedCommand.php::transactions' => [2,
        'the demo reset, keyed on import_runs.source_format = \'demo\' rather than on an owner so a run stranded by an interrupted reset still clears once its user is gone. It reads user_id off each row to build the tombstones it announces per user.'],

    'Modules/Ledger/Internal/Services/FingerprintRederiveService.php::transactions' => [2,
        'a repair that re-derives every fingerprint on the device, reached from two migrations and one artisan command. It has no reader by construction: its collision key is user_id|fingerprint precisely because it spans accounts.'],

    'Modules/Ledger/Public/Actions/SaveTransactionSplit.php::transaction_splits' => [1,
        'an insert whose row is built in the same method with \'user_id\' => $user->id and the parent id the action already resolved. The statement passes that array as a variable, which is all this reader sees.'],

    'Modules/Ledger/Public/Services/CounterpartyKeyBackfill.php::transactions' => [1,
        'an update by primary key inside a chunkById over a read the same method bounds with where(\'user_id\', $userId).'],

    'Modules/Ledger/Public/Services/FingerprintHealthCheck.php::transactions' => [1,
        'a device-wide integrity report: how many stored fingerprints no longer describe their row is a question about this database, not about a reader.'],

    'Modules/Ledger/Public/Services/SplitSumHealthCheck.php::transaction_splits' => [1,
        'the denominator of the same device-wide report — how many transactions on this device carry legs at all.'],

    'Modules/Ledger/Public/Services/SplitSumHealthCheck.php::transactions' => [1,
        'the numerator beside it: the transactions whose legs no longer sum to them, reported as a device health line rather than shown to anyone.'],

    'Modules/Ledger/Public/Support/SplitLegs.php::transaction_splits' => [1,
        'excludeParents() itself, a NOT EXISTS correlated to the caller\'s transactions alias. It narrows, never widens, and exposes no leg column, which is what makes it safe whatever bounds the caller.'],

    'Modules/Reports/Internal/Aggregation/CategoryAttribution.php::transaction_splits' => [1,
        'a subquery LEFT JOINed onto transactions.id. A left join adds columns and never rows, so the legs it exposes are the outer query\'s own — and all four callers bound that query with where(\'user_id\', $user->id).'],

    'Modules/Reports/Internal/Aggregation/CurrencyModeApplier.php::transaction_splits' => [1,
        'an EXISTS under an OR, which widens rather than narrows, so the outer scope is what carries it: discoverCurrencies() names where(\'user_id\', $user->id) before this branch is applied.'],

    'Modules/Search/Internal/Console/ReindexSearchCommand.php::transactions' => [1,
        'the owner column is the thing being read: it enumerates the users this device holds rows for, to partition them into rebuildable and key-blocked.'],

    'Modules/Search/Internal/Services/SearchIndexWriter.php::transactions' => [1,
        'the ownership check is present and is not a predicate. The row is read by id and the writer returns without touching the index when that row\'s user_id is not the actor\'s.'],

    'Modules/Search/Public/Services/FtsHealthCheck.php::transactions' => [1,
        'a row count of this device\'s table against its index, reported side by side. Neither number is a reader\'s.'],

    'Modules/Search/Internal/Services/SearchIndexRepairQueue.php::search_index_repairs' => [1,
        'owedTotal(), which counts the queue across every account on the device deliberately: the health probe asks about the index, and an index missing a household member\'s rows is missing rows.'],

    'Modules/Sync/Internal/OpLog/DeferredOpCaptures.php::deferred_op_captures' => [1,
        'the table is named once, in a private builder factory, so this reader sees that line and none of the seven statements that add the predicates to it. Six name user_id directly or through the coordinate array they insert and match on; forget() deletes by an id list that pending($userId) returned.'],

    'Modules/Search/Public/Services/SearchQuery.php::transaction_splits' => [1,
        'the same widening EXISTS as the report\'s, carried by the base query that names where(\'transactions.user_id\', $user->id); the category ids it matches were validated as this reader\'s by ownedIds() first.'],
];

/**
 * The rest of the tree, frozen at today's count rather than triaged. Each entry
 * names the shape its statements have; none of them has been verified one at a
 * time, and that is exactly what distinguishes this list from the one above.
 * The count is the ratchet: a new unscoped statement on any of these tables
 * fails here, and so does one that has been scoped since without the count
 * moving with it.
 *
 * @var array<string, array{0: int, 1: string}>
 */
const OWNED_ROW_READ_FROZEN = [
    'anomaly_alerts' => [3, 'the evaluator inserts an alert per run, reads one back by its derived id, and sweeps snoozes correlated to transactions'],
    'anomaly_suppression_rules' => [1, 'an existence check passed a builder and the reader id as separate arguments'],
    'card_statement_credits' => [1, 'a chunked insert of rows the resolver built from an already-scoped statement'],
    'card_statements' => [1, 'an upsert keyed on UNIQUE(user_id, account_id, period_start, period_end)'],
    'chain_links' => [4, 'two chunked inserts, a promotion by link id, and a signature rewrite inside an already-scoped chunk walk'],
    'chain_resolution_runs' => [1, 'a completion stamp on run ids the job itself minted'],
    'community_merchant_mappings' => [3, 'the shipped corpus seeding itself into the device, which belongs to no reader'],
    'device_registry' => [1, 'reads the owner column off the self row to discover who this device belongs to'],
    'discovered_senders' => [1, 'an occurrence counter bumped by the row id the scan just matched'],
    'drift_alerts' => [2, 'an insert per evaluation and a snooze sweep, mirroring anomaly_alerts'],
    'envelope_assignments' => [2, 'a delete and an update addressed by the derived assignment id'],
    'envelope_moves' => [4, 'the period rekeyer re-minting ids, plus a paired delete on both halves of a move'],
    'file_imports' => [1, 'a receipt stamping the file import row it was handed'],
    'forecast_runs' => [3, 'a run addressed by the id that opened it — the state machine under a row lock, and the pipeline writing the result back'],
    'goal_contributions' => [1, 'a delete addressed by the derived contribution id'],
    'import_runs' => [5, 'the demo reset reading and clearing runs by source_format, the preview wizard checking its own run, and the migration pipeline inserting the run it mints'],
    'inbox_messages' => [4, 'reads and writes bounded by inbox_id, where the owner sits on the inbox'],
    'inbox_scan_state' => [12, 'a scan state machine bounded by inbox_id and folder, where the owner sits on the inbox'],
    'inboxes' => [7, 'the mailbox row addressed by id, twice to read the owner off it'],
    'merchants' => [1, 'a blind-index rewrite by row id inside an already-scoped chunk walk'],
    'migration_import_baseline' => [1, 'an update by the id of the row the lookup through its migration_source_map parent just returned'],
    'migration_source_map' => [1, 'a map row addressed by the id the pipeline minted for it'],
    'migration_staging_transactions' => [2, 'chunked inserts into the staging table a migration run owns'],
    'notifications' => [2, 'an insertOrIgnore of already-encrypted rows and an existence check by id'],
    'op_log_quarantine' => [1, 'a chunked delete of quarantine rows the reprojector just resolved'],
    'open_banking_connections' => [2, 'the scheduler enumerating enabled connections on this device, and the job reading the one it was dispatched for'],
    'pairing_tokens' => [1, 'a token looked up by its hash and the initiating device id'],
    'pot_movements' => [1, 'an insert of a movement the pot writer built'],
    'recurring_series' => [5, 'two detector inserts, a rename and a refresh addressed by the derived series id, and a blind-index rewrite inside an already-scoped chunk walk'],
    'recurring_series_occurrences' => [3, 'occurrence writes bounded by recurring_series_id and transaction id'],
    'saved_reports' => [1, 'a pin-order compaction addressed by report id'],
    'savings_insight_dismissals' => [1, 'an insertOrIgnore of a dismissal keyed by its derived id'],
    'sync_sessions' => [4, 'a transport session addressed by the session row id this process opened'],
    'system_alerts' => [6, 'device-level alerts — a backup overdue, a crashed worker, an update available — plus one read back by alert id'],
    'transaction_search_docs' => [5, 'index docs addressed by transaction_id, a bulk reindex insert, and a device-wide count beside the table\'s own'],
    'user_biometric_credentials' => [3, 'a failure counter and a signature counter on the credential id being asserted'],
    'user_preferences' => [1, 'a preference row read back by the id the writer just used'],
    'user_recovery_codes' => [1, 'a code consumed by the id the authenticator matched under a row lock'],
];

/**
 * The tables this rule is about, asked of the live schema.
 *
 * @return list<string>
 */
function ownedRowTables(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return UserOwnedRowReads::ownedTables($db->connection());
}

/**
 * Every statement in the shipped tree naming one of those tables.
 *
 * @return list<array{key: string, line: int, scoped: bool}>
 */
function ownedRowStatements(): array
{
    static $census = null;

    if (is_array($census)) {
        return $census;
    }

    $tables = ownedRowTables();
    $root = RepoTree::root().'/';
    $census = [];

    foreach (RepoTree::files(RepoTree::RUNTIME_DOMAIN_PHP) as $path) {
        $source = (string) file_get_contents($path);

        // Nothing to tokenise in a file that names no table at all, which is
        // most of six thousand.
        if (! str_contains($source, 'table(') && ! str_contains($source, 'from(')) {
            continue;
        }

        $relative = str_replace($root, '', $path);
        $php = BladePhpSource::forPath($path, $source);

        foreach (UserOwnedRowReads::statementsIn($php, $tables) as $statement) {
            $census[] = [
                'key' => $relative.'::'.$statement['table'],
                'line' => $statement['line'],
                'scoped' => $statement['scoped'],
            ];
        }
    }

    return $census;
}

/** @return array<string, int> */
function ownedRowUnscopedByKey(): array
{
    $counts = [];

    foreach (ownedRowStatements() as $statement) {
        if (! $statement['scoped']) {
            $counts[$statement['key']] = ($counts[$statement['key']] ?? 0) + 1;
        }
    }

    ksort($counts);

    return $counts;
}

// The derivation is the whole rule's reach, so it is checked by membership
// rather than by a count: a schema read that silently returned less would leave
// the guard green over tables nobody looked at.
it('derives the covered tables from the schema, and leaves the users table out of them', function (): void {
    $owned = ownedRowTables();

    expect($owned)
        ->toContain('transactions', 'transaction_splits', 'accounts', 'categories', 'counterparties')
        ->toContain('goals', 'pots', 'saved_reports', 'op_log_entries', 'sessions')
        ->and($owned)->not->toContain(
            'users',
            'users answers for itself by primary key, and sweeping it by user_id is how a purge deletes the account it is emptying around',
        );
});

// Two real statements, both of which the reader has to resolve correctly: one
// scoped in the statement AFTER the one that names the table, one scoped by the
// ownership helper. If the walk stops reading, or either resolution breaks,
// they leave the census and this fails before the rule below reports a clean
// tree over code nobody opened.
it('reads the tree, and resolves a scope named past the statement that names the table', function (): void {
    $byKey = [];

    foreach (ownedRowStatements() as $statement) {
        $byKey[$statement['key']][] = $statement['scoped'];
    }

    $listQuery = array_unique($byKey['Modules/Ledger/Public/Services/TransactionListQuery.php::transactions'] ?? []);
    $decorator = array_unique($byKey['Modules/Ledger/Internal/Services/TransactionRowDecorator.php::transaction_splits'] ?? []);

    expect($byKey)
        ->toHaveKey('Modules/Ledger/Public/Services/TransactionListQuery.php::transactions')
        ->toHaveKey('Modules/Ledger/Internal/Services/TransactionRowDecorator.php::transaction_splits')
        ->and($listQuery)->toBe(
            [true],
            'baseQuery() builds the query in one statement and names transactions.user_id in the next, so a reader that stopped at the semicolon would report it here — and would go on reporting the four siblings shaped the same way, hiding the one that really never scopes',
        )
        ->and($decorator)->toBe(
            [true],
            'the leg read that shipped unscoped, which reaches its owner through SplitLegs::ownedBy',
        );
});

it('names a reader on every statement that touches a user-owned row', function (): void {
    $unaccounted = [];
    $pinned = array_map(static fn (array $entry): int => $entry[0], OWNED_ROW_READ_TRIAGED);
    $frozen = array_map(static fn (array $entry): int => $entry[0], OWNED_ROW_READ_FROZEN);
    $seenFrozen = [];

    foreach (ownedRowUnscopedByKey() as $key => $count) {
        if (array_key_exists($key, $pinned)) {
            if ($pinned[$key] !== $count) {
                $unaccounted[] = $key.': '.$count.' unscoped, pinned as '.$pinned[$key];
            }

            unset($pinned[$key]);

            continue;
        }

        $table = (string) substr($key, (int) strrpos($key, '::') + 2);

        if (array_key_exists($table, $frozen)) {
            $seenFrozen[$table] = ($seenFrozen[$table] ?? 0) + $count;

            continue;
        }

        $unaccounted[] = $key.': '.$count.' unscoped';
    }

    foreach ($pinned as $key => $count) {
        $unaccounted[] = $key.': pinned as '.$count.' and no longer there';
    }

    foreach ($frozen as $table => $count) {
        $found = $seenFrozen[$table] ?? 0;

        if ($found !== $count) {
            $unaccounted[] = $table.': '.$found.' unscoped, frozen at '.$count;
        }
    }

    sort($unaccounted);

    expect($unaccounted)->toBe([], implode("\n  ", [
        'These statements read or write a user-owned table without saying whose row it is:',
        ...$unaccounted,
        '',
        'A primary key is not proof of ownership here. Ids are derived from row content and',
        'travel between devices, and the foreign keys that would tie one back to an owner are',
        'deliberately absent, so where(\'id\', $id) answers for whoever holds that row.',
        '',
        'Add ->where(\'user_id\', $userId), or SplitLegs::ownedBy() where the owner sits on a',
        'parent. If the statement is genuinely bounded somewhere this reader cannot see, pin it',
        'in OWNED_ROW_READ_TRIAGED with the mechanism that bounds it — not with the fact that it',
        'has always been this way.',
    ]));
});

// A frozen table that no longer exists, or never did, is a line that reads as a
// considered decision and guards nothing.
it('freezes no table the schema does not own', function (): void {
    $owned = ownedRowTables();
    $stale = array_values(array_diff(array_keys(OWNED_ROW_READ_FROZEN), $owned));

    expect($stale)->toBe([], 'Frozen tables that carry no user_id today: '.implode(', ', $stale));
});

// Every pinned exemption carries its reason, because a pin without one is the
// same silence as no pin at all.
it('gives every pinned statement a reason', function (): void {
    $empty = [];

    foreach ([...OWNED_ROW_READ_TRIAGED, ...OWNED_ROW_READ_FROZEN] as $key => [$count, $reason]) {
        if ($count < 1 || trim($reason) === '') {
            $empty[] = (string) $key;
        }
    }

    expect($empty)->toBe([], 'These are pinned with no reason or a count of zero: '.implode(', ', $empty));
});

// The planted half. Each of these is a spelling the reader has to get right,
// and the ones it must NOT accept are the reason the rule was tightened from
// "mentions user_id" to "is bounded by it".
it('reports a read bounded by an id alone, and clears the three spellings of a scope', function (): void {
    $unscoped = static fn (string $php): array => array_map(
        static fn (array $found): int => $found['line'],
        UserOwnedRowReads::unscopedIn($php, ['transactions', 'transaction_splits', 'goals', 'op_log_entries']),
    );

    expect($unscoped('<?php $db->table(\'transactions\')->where(\'id\', $id)->first();'))
        ->toBe([1], 'an id is the shape this rule exists for');

    expect($unscoped('<?php $db->table(\'transactions\')->where(\'id\', $id)->where(\'user_id\', $u)->first();'))
        ->toBe([], 'the predicate spelling');

    expect($unscoped('<?php $db->table(\'transactions as t\')->join(\'x\', \'a\', \'=\', \'b\')->where(\'t.user_id\', $u)->get();'))
        ->toBe([], 'the aliased-and-qualified spelling, with the alias stripped off the table name');

    expect($unscoped('<?php $db->table(\'goals\')->insert([\'user_id\' => $u, \'name\' => $n]);'))
        ->toBe([], 'the written-key spelling, which is how an insert and an updateOrInsert identity name an owner');

    expect($unscoped('<?php $db->table(\'transactions\')->whereRaw(\'user_id = ? AND posted_at > ?\', [$u, $d])->get();'))
        ->toBe([], 'the raw-fragment spelling');

    expect($unscoped('<?php $db->table(\'transaction_splits\')->tap(static fn ($q) => SplitLegs::ownedBy($q, $u))->get();'))
        ->toBe([], 'the helper that reaches the owner through the parent transaction');
});

it('refuses a mention of the column that bounds nothing', function (): void {
    $unscoped = static fn (string $php): array => array_map(
        static fn (array $found): int => $found['line'],
        UserOwnedRowReads::unscopedIn($php, ['transactions', 'op_log_entries']),
    );

    expect($unscoped('<?php $db->table(\'transactions\')->where(\'id\', $id)->select([\'id\', \'user_id\'])->first();'))
        ->toBe([1], 'selecting the owner column is the opposite of bounding the read by it');

    expect($unscoped('<?php $db->table(\'transactions\')->where(\'id\', $id)->value(\'user_id\');'))
        ->toBe([1], 'reading the owner off a row says nothing about who asked for the row');

    expect($unscoped('<?php $db->table(\'op_log_entries\')->where(\'origin_user_id\', $u)->get();'))
        ->toBe([1], 'origin_user_id is provenance on another account\'s replicated entry, not this row\'s owner');
});

it('bounds the scope to the statement and the builder it was assigned to', function (): void {
    $unscoped = static fn (string $php): array => array_map(
        static fn (array $found): int => $found['line'],
        UserOwnedRowReads::unscopedIn($php, ['transactions']),
    );

    $chained = '<?php $joined = $db->table(\'transactions\')->leftJoin(\'categories\', \'a\', \'=\', \'b\');'
        ."\n".'$rows = Path::joinParent($joined, $u)->where(\'transactions.user_id\', $u)->get();';
    expect($unscoped($chained))->toBe([], 'the scope named in the next statement, on the builder this one was assigned to');

    $unchained = '<?php $joined = $db->table(\'transactions\')->leftJoin(\'categories\', \'a\', \'=\', \'b\');'
        ."\n".'$rows = Path::joinParent($joined, $u)->whereIn(\'transactions.id\', $ids)->get();';
    expect($unscoped($unchained))->toBe([1], 'the same two statements with the scope missing, which is the defect this rule was written from');

    $otherVariable = '<?php $joined = $db->table(\'transactions\')->leftJoin(\'categories\', \'a\', \'=\', \'b\');'
        ."\n".'$other = $db->table(\'x\')->where(\'user_id\', $u)->get();';
    expect($unscoped($otherVariable))->toBe([1], 'a later statement that never names this builder cannot answer for it');

    $twoStatements = '<?php $a = $db->table(\'transactions\')->first(); $b = $db->table(\'y\')->where(\'user_id\', $u)->get();';
    expect($unscoped($twoStatements))->toBe([1], 'and neither can the next statement on its own');

    // The bound the chase needs most: $query is the commonest variable name in
    // this tree, and a walk that ran past the closing brace would let the next
    // method's scope answer for this one wherever the two happened to agree.
    $nextMethod = "<?php\nclass A {\n"
        ."    public function a(\$db, \$id) { \$q = \$db->table('transactions')->where('id', \$id); return \$q->get(); }\n"
        ."    public function b(\$db, \$u) { \$q = \$db->table('y')->where('user_id', \$u); return \$q->get(); }\n"
        .'}';
    expect($unscoped($nextMethod))->toBe([3], 'the scope in the method below it belongs to a different query');
});

it('reads a table literal only where the whole name is the sole argument', function (): void {
    $unscoped = static fn (string $php): array => UserOwnedRowReads::unscopedIn($php, ['transactions']);

    expect($unscoped('<?php $db->table(\'transactions_archive\')->where(\'id\', $id)->get();'))
        ->toBe([], 'a different table whose name merely starts with this one');

    expect($unscoped('<?php $db->table($name)->where(\'id\', $id)->get();'))
        ->toBe([], 'a table named by a variable is a table this reader cannot identify');

    expect($unscoped('<?php $report->tableOfContents();'))
        ->toBe([], 'a method whose name merely starts with table names nothing');
});
