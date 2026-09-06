<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Modules\Core\Public\Support\BladePhpSource;

/**
 * @link ../../.docs/architecture/reads-bounded-by-the-user.md
 */

// The whole backend runs on the reader's phone over a SQLite file that grows
// for years, and there is no server to absorb a bad query. A read whose only
// bound is "however much history the user has" is not slow, it is the app
// dying — so every table below is one that grows with use.
const BOUNDED_READ_GROWING_TABLES = [
    'transactions', 'transaction_splits', 'transaction_search_docs', 'op_log_entries',
    'op_log_quarantine', 'notifications', 'chain_links', 'recurring_series_occurrences',
    'envelope_assignments', 'envelope_moves', 'pot_movements', 'goal_contributions',
    'tax_transaction_tags', 'inbox_messages', 'anomaly_alerts', 'drift_alerts',
    'merchant_memories', 'merchant_aliases', 'dev_mode_audit', 'relay_mailbox',
    'forecast_runs', 'forecast_shortfall_windows', 'import_runs', 'file_imports',
    'counterparties', 'categorization_rules', 'rule_conditions', 'rule_actions',
];

// The Eloquent spelling of the same read. A model name is mapped rather than
// resolved so the scanner stays a lexer: these are the growing tables above
// whose models are reachable as `Model::query()`.
const BOUNDED_READ_GROWING_MODELS = [
    'Transaction' => 'transactions',
    'TransactionSplit' => 'transaction_splits',
    'Notification' => 'notifications',
    'ChainLink' => 'chain_links',
    'AnomalyAlert' => 'anomaly_alerts',
    'DriftAlert' => 'drift_alerts',
    'Counterparty' => 'counterparties',
    'RecurringSeriesOccurrence' => 'recurring_series_occurrences',
    'MerchantAlias' => 'merchant_aliases',
    'ImportRun' => 'import_runs',
    'CategorizationRule' => 'categorization_rules',
    'RuleCondition' => 'rule_conditions',
    'RuleAction' => 'rule_actions',
    'EnvelopeAssignment' => 'envelope_assignments',
    'InboxMessage' => 'inbox_messages',
    'ForecastRun' => 'forecast_runs',
    'ForecastShortfallWindow' => 'forecast_shortfall_windows',
];

// `cursor` and the `lazy*` family are absent on purpose: they are the fix, not
// the defect, and a chain that ends in one of them hands PHP a row at a time.
const BOUNDED_READ_TERMINALS = ['get', 'pluck'];

// `groupBy` collapses the answer to one row per key and `whereIn` narrows to a
// set the caller already holds — when that set is itself unbounded, the read
// that produced it is a separate chain this scanner sees on its own.
const BOUNDED_READ_BOUNDS = [
    'limit', 'take', 'paginate', 'simplePaginate', 'cursorPaginate', 'chunk', 'chunkById',
    'lazy', 'lazyById', 'lazyByIdDesc', 'cursor', 'first', 'firstOrFail', 'value', 'exists',
    'doesntExist', 'count', 'sum', 'avg', 'min', 'max', 'groupBy', 'groupByRaw', 'find',
    'whereKey', 'whereIn', 'whereIntegerInRaw', 'insert', 'update', 'delete', 'upsert',
    'insertGetId', 'increment', 'decrement',
];

// Keyed `path::table`, carrying the number of reads admitted and WHY each is
// bounded by something real. A new read in an allowed file pushes the count
// past its entry and fails; an entry that stops matching fails too, so the
// list cannot rot into a blanket exemption.
const BOUNDED_READ_ALLOWED = [
    'Modules/Anomaly/Internal/Jobs/DetectAnomaliesJob.php::transactions' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. One import_run_id, but an import run is however many rows the reader\'s file held, and the first full-history CSV after onboarding is the whole ledger.',
    ],
    'Modules/Anomaly/Public/Services/AnomalyAlertQuery.php::anomaly_alerts' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. Every open alert\'s reasons on each dashboard paint, and nothing auto-closes an alert; openForUser() applies the same predicate with limit(26) and a keyset cursor.',
    ],
    'Modules/Budgets/Public/Services/CarryoverQuery.php::envelope_assignments' => [
        'reads' => 1,
        'why' => 'Bounded by the envelope genesis-to-target window: months since envelope_activated_at multiplied by expense categories, about 1,500 rows at five years.',
    ],
    'Modules/Budgets/Public/Services/EnvelopePeriodRekeyer.php::envelope_assignments' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. Every assignment the reader ever made, read whole when they change the budget month start day, with one sync event dispatched per row.',
    ],
    'Modules/Budgets/Public/Services/EnvelopePeriodRekeyer.php::envelope_moves' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. The whole append-only move ledger on the same settings save, then a delete, an insert and two events for every row of it.',
    ],
    'Modules/Budgets/Public/Services/EnvelopeWriter.php::envelope_assignments' => [
        'reads' => 2,
        'why' => 'Both name one budget period by period_start, so the ceiling is the reader\'s expense-category count rather than how many periods they have lived through.',
    ],
    'Modules/Calendar/Internal/Services/OccurrenceMatcher.php::recurring_series_occurrences' => [
        'reads' => 1,
        'why' => 'One calendar grid plus MatchWindow::DAYS either side, so at most 56 days wide, and the width is a constant rather than anything the reader can set.',
    ],
    'Modules/Categorization/Internal/Services/ActiveRuleSet.php::categorization_rules' => [
        'reads' => 1,
        'why' => 'The hand-authored rule book, read once for the life of the instance rather than once per transaction, which is the whole point of this collaborator.',
    ],
    'Modules/Categorization/Public/Actions/UpdateCategorizationRule.php::rule_conditions' => [
        'reads' => 1,
        'why' => 'Names the single rule being saved, so the ceiling is the repeater rows the author typed into one form.',
    ],
    'Modules/Categorization/Public/Actions/UpdateCategorizationRule.php::rule_actions' => [
        'reads' => 1,
        'why' => 'Names the single rule being saved, so the ceiling is the repeater rows the author typed into one form.',
    ],
    'Modules/Categorization/Public/Services/CategorizationRuleQuery.php::categorization_rules' => [
        'reads' => 1,
        'why' => 'The rules page shows the whole hand-authored book unpaginated; nothing creates a rule from a transaction, so the ceiling is what the reader typed.',
    ],
    'Modules/Categorization/Public/Services/MerchantMemoryQuery.php::merchant_memories' => [
        'reads' => 1,
        'why' => 'The join is a whereIn over one cluster key per recurring series, and the sole caller passes the reader\'s series list, which is tens of rows.',
    ],
    'Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php::transactions' => [
        'reads' => 3,
        'why' => 'KNOWN UNBOUNDED for two of the three: candidate transfers and unlinked card refunds carry no date predicate and run synchronously inside the import-confirm request; the third names one card statement\'s own period.',
    ],
    'Modules/Chains/Public/Actions/ConfirmChainLink.php::chain_links' => [
        'reads' => 1,
        'why' => 'Scoped by evidence signature hash to the one card statement settle group the just-confirmed link belongs to.',
    ],
    'Modules/Chains/Public/Services/ChainLinkQuery.php::chain_links' => [
        'reads' => 2,
        'why' => 'One names a single recurring series, so it is that series\' occurrence count; the other is KNOWN UNBOUNDED - hintsForReview has neither the limit nor the keyset cursor its sibling candidatesForReview carries.',
    ],
    'Modules/DriftAlerts/Internal/Jobs/RevivedExpiredDriftSnoozesJob.php::drift_alerts' => [
        'reads' => 1,
        'why' => 'Self-draining: only alerts whose snooze has elapsed, and the loop transitions each one to open, so the set is the reader\'s snoozed series count.',
    ],
    'Modules/DriftAlerts/Public/Services/DriftAlertQuery.php::drift_alerts' => [
        'reads' => 1,
        'why' => 'Plucks distinct recurring_series_id, so the ceiling is the reader\'s series count however many historical alerts each series accumulated.',
    ],
    'Modules/Forecasting/Internal/Support/ForecastChartView.php::forecast_shortfall_windows' => [
        'reads' => 1,
        'why' => 'One account, horizon and scenario slice, which ShortfallDetector deletes and re-inserts on every run, so the rows are one run\'s below-floor stretches.',
    ],
    'Modules/Forecasting/Public/Services/ForecastQuery.php::transactions' => [
        'reads' => 1,
        'why' => 'Plucks distinct posted_at inside the forecast horizon, so the row count can never exceed the horizon in days, at most one year.',
    ],
    'Modules/Goals/Public/Services/GoalContributionQuery.php::goal_contributions' => [
        'reads' => 1,
        'why' => 'Names one transaction_id, so the ceiling is how many goals the reader has active, not how many contributions they have made.',
    ],
    'Modules/Import/Internal/Services/AliasYamlExporter.php::merchant_aliases' => [
        'reads' => 1,
        'why' => 'The whole hand-authored alias list, on an explicit export press; aliases are never ledger-proportional.',
    ],
    'Modules/Import/Internal/Services/AliasYamlImporter.php::merchant_aliases' => [
        'reads' => 1,
        'why' => 'The same hand-authored set read once per YAML import to diff the upload against it.',
    ],
    'Modules/Import/Public/Services/MerchantNameResolver.php::merchant_aliases' => [
        'reads' => 1,
        'why' => 'The alias set, memoised per reader in a singleton, so a whole import pays for this read once rather than once per row.',
    ],
    'Modules/Ledger/Internal/Http/Livewire/Concerns/ManagesSplitEditor.php::transaction_splits' => [
        'reads' => 1,
        'why' => 'Names one transaction_id, and the split editor soft-caps a transaction at eighteen legs.',
    ],
    'Modules/Ledger/Public/Actions/SaveTransactionSplit.php::transaction_splits' => [
        'reads' => 2,
        'why' => 'Both read the legs of the one transaction being saved or un-split, for the identity-preserving diff.',
    ],
    'Modules/Ledger/Public/Services/TransactionStatusWriter.php::transactions' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. The cleared-rows predicate has no lower bound, so a first Complete-reconcile plucks every cleared row the account ever held and dispatches one sync op per id.',
    ],
    'Modules/Recurring/Internal/Detectors/IncomeSeriesDetector.php::transactions' => [
        'reads' => 2,
        'why' => 'One plucks distinct currency, which is a handful; the other is the detection window, user-settable up to RecurringDetectionWindow::MAXIMUM_MONTHS, where it becomes the whole ledger.',
    ],
    'Modules/Recurring/Public/Services/RecurringOccurrenceQuery.php::recurring_series_occurrences' => [
        'reads' => 1,
        'why' => 'Names one recurring_series_id, so about sixty rows for a monthly series over five years.',
    ],
    'Modules/Search/Internal/Console/ReindexSearchCommand.php::transactions' => [
        'reads' => 1,
        'why' => 'Plucks distinct user_id, and a device file holds one reader; the bulk read thirty lines above it correctly chunks in batches of five hundred.',
    ],
    'Modules/Search/Public/Services/SearchQuery.php::transactions' => [
        'reads' => 1,
        'why' => 'Fires only when the FTS branch found nothing and the text parses as money, and the predicate is an exact amount match, so the ceiling is rows sharing one figure.',
    ],
    'Modules/Sync/Internal/OpLog/OpLogRebuilder.php::op_log_entries' => [
        'reads' => 2,
        'why' => 'OpLogRebuilder::rebuild() has no production caller: no route, job, listener or command reaches it, only tests and two doc comments.',
    ],
    'Modules/Sync/Public/Services/ImportSyncCapture.php::transactions' => [
        'reads' => 1,
        'why' => 'KNOWN UNBOUNDED. Every transaction id of one import run on the confirm request, then handed to an unchunked whereIn, while ConfirmImport beside it streams the rows themselves.',
    ],
    'app/Console/Commands/DemoSeedCommand.php::import_runs' => [
        'reads' => 1,
        'why' => 'Reachable only from php artisan demo:seed, and the source_format predicate matches nothing a shipped importer ever writes.',
    ],
];

/**
 * @return list<array{line: int, table: string, chain: string}>
 */
function boundedReadScan(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $hits = [];

    for ($i = 0; $i < $count; $i++) {
        $hit = boundedReadChainAt($tokens, $count, $i);

        if ($hit !== null) {
            $hits[] = $hit;
        }
    }

    return $hits;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array{line: int, table: string, chain: string}|null
 */
function boundedReadChainAt(array $tokens, int $count, int $index): ?array
{
    $table = boundedReadTableNamedAt($tokens, $count, $index);

    if ($table === null) {
        return null;
    }

    $methods = boundedReadChainMethods($tokens, $count, $index);

    $ends = array_intersect($methods, BOUNDED_READ_TERMINALS) !== [];
    $bounded = array_intersect($methods, BOUNDED_READ_BOUNDS) !== [];

    if (! $ends || $bounded) {
        return null;
    }

    $token = $tokens[$index];

    return [
        'line' => is_array($token) ? $token[2] : 0,
        'table' => $table,
        'chain' => implode('->', $methods),
    ];
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function boundedReadTableNamedAt(array $tokens, int $count, int $index): ?string
{
    $token = $tokens[$index];

    if (! is_array($token) || $token[0] !== T_STRING) {
        return null;
    }

    if ($token[1] === 'query') {
        return boundedReadModelNamedAt($tokens, $count, $index);
    }

    if (! in_array($token[1], ['table', 'from'], true)) {
        return null;
    }

    $before = boundedReadSkipSpace($tokens, $count, $index - 1, -1);

    if (! is_array($tokens[$before] ?? null) || $tokens[$before][0] !== T_OBJECT_OPERATOR) {
        return null;
    }

    $open = boundedReadSkipSpace($tokens, $count, $index + 1, 1);

    if (($tokens[$open] ?? null) !== '(') {
        return null;
    }

    $argument = boundedReadSkipSpace($tokens, $count, $open + 1, 1);
    $literal = $tokens[$argument] ?? null;

    if (! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }

    $named = explode(' ', trim($literal[1], "'\""))[0];

    return in_array($named, BOUNDED_READ_GROWING_TABLES, true) ? $named : null;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function boundedReadModelNamedAt(array $tokens, int $count, int $index): ?string
{
    $colon = boundedReadSkipSpace($tokens, $count, $index - 1, -1);

    if (! is_array($tokens[$colon] ?? null) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
        return null;
    }

    $model = boundedReadSkipSpace($tokens, $count, $colon - 1, -1);
    $named = $tokens[$model] ?? null;

    if (! is_array($named) || ! in_array($named[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        return null;
    }

    $short = (string) (array_slice(explode('\\', $named[1]), -1)[0] ?? '');

    return BOUNDED_READ_GROWING_MODELS[$short] ?? null;
}

/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 */
function boundedReadSkipSpace(array $tokens, int $count, int $from, int $step): int
{
    $at = $from;

    while ($at >= 0 && $at < $count && is_array($tokens[$at]) && $tokens[$at][0] === T_WHITESPACE) {
        $at += $step;
    }

    return $at;
}

// Every `->method` reached at nesting depth zero after `table('x')` closes,
// stopping at the statement's end. A chain assembled across two variables is
// invisible here, which is the scanner's one honest blind spot.
/**
 * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
 * @return list<string>
 */
function boundedReadChainMethods(array $tokens, int $count, int $index): array
{
    $at = boundedReadSkipSpace($tokens, $count, $index + 1, 1);
    $depth = 1;

    while ($at < $count && $depth > 0) {
        $at++;
        $depth += match ($tokens[$at] ?? null) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };
    }

    $methods = [];
    $depth = 0;

    for ($at++; $at < $count; $at++) {
        $token = $tokens[$at];

        if (in_array($token, ['(', '[', '{'], true)) {
            $depth++;

            continue;
        }

        if (in_array($token, [')', ']', '}'], true)) {
            $depth--;

            if ($depth < 0) {
                break;
            }

            continue;
        }

        if ($depth !== 0) {
            continue;
        }

        if (in_array($token, [';', ','], true)) {
            break;
        }

        if (is_array($token) && $token[0] === T_OBJECT_OPERATOR) {
            $name = boundedReadSkipSpace($tokens, $count, $at + 1, 1);

            if (is_array($tokens[$name] ?? null) && $tokens[$name][0] === T_STRING) {
                $methods[] = $tokens[$name][1];
            }
        }
    }

    return $methods;
}

/**
 * @return list<string>
 */
function boundedReadSourceFiles(): array
{
    $files = [];

    foreach (['Modules', 'app'] as $root) {
        $files = array_merge($files, boundedReadWalk(base_path($root)));
    }

    sort($files);

    return $files;
}

// Symlinks are skipped because mobile-app/ is a second Composer root whose
// Modules/ and app/ point back here: resolving them reports every shared file
// a second time under a second spelling. Migrations, Seeders and tests are
// skipped because each writes rows rather than reading them for a screen: a
// seeder reading its own table whole is the shape those files exist to have.
/**
 * @return list<string>
 */
function boundedReadWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            if (! in_array($entry, ['Migrations', 'Seeders', 'tests'], true)) {
                $files = array_merge($files, boundedReadWalk($path));
            }

            continue;
        }

        if (str_ends_with($entry, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * @return array<string, int>
 */
function boundedReadCountsByKey(): array
{
    static $memo = null;

    if (is_array($memo)) {
        return $memo;
    }

    $counts = [];

    foreach (boundedReadSourceFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (boundedReadScan(BladePhpSource::forPath($path, (string) file_get_contents($path))) as $hit) {
            $key = $relative.'::'.$hit['table'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    }

    $memo = $counts;

    return $counts;
}

it('sees a chain that reads a growing table whole', function (): void {
    $source = <<<'PHP'
    <?php
    $rows = $db->connection()->table('transactions')->where('user_id', 1)->get();
    PHP;

    expect(boundedReadScan($source))->toHaveCount(1)
        ->and(boundedReadScan($source)[0]['table'])->toBe('transactions', 'the reader must name the table the chain opened on');
});

// Every verdict below is read off one walk, and a walk that opened nothing
// answers "no unbounded read" in the same words a bounded tree does.
it('walks the tree it is about to read four verdicts off', function (): void {
    expect(count(boundedReadSourceFiles()))->toBeGreaterThan(
        2_000,
        'The walk read almost none of Modules/ and app/, so every verdict in this file is about a tree nobody opened.',
    );

    expect(array_sum(boundedReadCountsByKey()))->toBeGreaterThan(
        20,
        'The scanner found almost no whole-table read on a growing table. There are dozens; a count this '
        .'low means the token walk stopped rather than that the tree stopped reading.',
    );
});

// The Eloquent half of the subject is a hand-written map, and a hand-written map
// cannot see a model somebody adds tomorrow. Every growing table whose model
// exists has to be in it, or `Model::query()->get()` on that table is invisible
// while the table-name half of the same rule reports the raw builder form.
it('maps every growing table that has a model of its own', function (): void {
    $models = [];

    foreach (glob(base_path('Modules/*/Models/*.php')) ?: [] as $path) {
        $name = basename($path, '.php');
        $models[Str::snake(Str::plural($name))] = $name;
    }

    expect(count($models))->toBeGreaterThan(
        20,
        'Almost no Eloquent model was found, so the comparison below is about a tree nobody read.',
    );

    $unmapped = [];

    foreach (BOUNDED_READ_GROWING_TABLES as $table) {
        if (isset($models[$table]) && ! isset(BOUNDED_READ_GROWING_MODELS[$models[$table]])) {
            $unmapped[] = $models[$table].' => \''.$table.'\'';
        }
    }

    sort($unmapped);

    expect($unmapped)->toBe([], implode("\n  ", [
        'These growing tables have an Eloquent model and no entry in BOUNDED_READ_GROWING_MODELS, so a',
        'Model::query()->get() on them reads the whole table and this rule cannot see it — while the raw',
        'builder spelling of the identical read is reported. Add the entry:',
        ...$unmapped,
    ]));

    $stale = array_values(array_diff(array_keys(BOUNDED_READ_GROWING_MODELS), array_values($models)));

    expect($stale)->toBe([], implode("\n  ", [
        'These models are mapped and no longer exist under any module\'s Models/ directory, so the entry',
        'names a spelling nothing can write. Delete it:',
        ...$stale,
    ]));

    expect(array_values(array_diff(BOUNDED_READ_GROWING_MODELS, BOUNDED_READ_GROWING_TABLES)))->toBe([], implode("\n  ", [
        'These models are mapped to a table the growing list does not name, so the entry widens the',
        'subject of this rule without saying so.',
    ]));
});

it('sees the Eloquent spelling of the same read', function (): void {
    $source = <<<'PHP'
    <?php
    $rows = Transaction::query()->where('user_id', 1)->get();
    $safe = Transaction::query()->where('user_id', 1)->limit(10)->get();
    $other = Category::query()->where('user_id', 1)->get();
    PHP;

    expect(boundedReadScan($source))->toHaveCount(1)
        ->and(boundedReadScan($source)[0]['table'])->toBe('transactions');
});

it('leaves a chain alone once it carries a bound', function (): void {
    $limited = <<<'PHP'
    <?php
    $rows = $db->connection()->table('transactions')->where('user_id', 1)->limit(50)->get();
    PHP;

    $streamed = <<<'PHP'
    <?php
    foreach ($db->connection()->table('op_log_entries')->where('user_id', 1)->cursor() as $row) {
    }
    PHP;

    $bounded = <<<'PHP'
    <?php
    $rows = $db->connection()->table('categories')->where('user_id', 1)->get();
    PHP;

    expect(boundedReadScan($limited))->toBe([])
        ->and(boundedReadScan($streamed))->toBe([])
        ->and(boundedReadScan($bounded))->toBe([]);
});

it('admits no unbounded read on a growing table outside the allow-list', function (): void {
    $offenders = [];

    foreach (boundedReadCountsByKey() as $key => $found) {
        $allowed = BOUNDED_READ_ALLOWED[$key]['reads'] ?? 0;

        if ($found > $allowed) {
            $offenders[] = $key.' ('.$found.' found, '.$allowed.' allowed)';
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These read a table that grows with use, whole, on a phone inside a 128 MB ceiling. An exhausted',
        'heap is E_ERROR: no exception to catch, no log line and no retry. Bound the read — limit(),',
        'a keyset cursor, chunkById() or lazy() — or, where the read genuinely is bounded by something',
        'real, add it to BOUNDED_READ_ALLOWED with the count and the reason:',
        ...$offenders,
    ]));
});

it('carries no allow-list entry that has stopped matching', function (): void {
    $counts = boundedReadCountsByKey();
    $stale = [];

    foreach (BOUNDED_READ_ALLOWED as $key => $entry) {
        $found = $counts[$key] ?? 0;

        if ($found !== $entry['reads']) {
            $stale[] = $key.' (allows '.$entry['reads'].', found '.$found.')';
        }
    }

    expect($stale)->toBe([], implode("\n  ", [
        'An entry admits a fixed number of reads, in both directions: a read added to an allowed file',
        'pushes the count past its entry, and a read that went away leaves the entry excusing something',
        'that is not there. Update the count, or delete the entry:',
        ...$stale,
    ]));
});

// The entries that are not bounded by anything are a baseline, not a licence:
// they are named in .docs/architecture/reads-bounded-by-the-user.md with what a
// five-year ledger costs each one. The count may fall. It may not rise.
it('does not let the known-unbounded baseline grow', function (): void {
    $known = array_keys(array_filter(
        BOUNDED_READ_ALLOWED,
        static fn (array $entry): bool => str_contains($entry['why'], 'KNOWN UNBOUNDED'),
    ));

    expect(count($known))->toBe(
        8,
        'The known-unbounded baseline is a ratchet: eight reads on this tree are bounded by nothing but '
        .'how much history the reader has, each costed in reads-bounded-by-the-user.md. The count may '
        .'fall — delete a line here when one is fixed. It may not rise: a ninth is a new way for the '
        ."app to die on a five-year ledger.\n  ".implode("\n  ", $known),
    );
});

it('gives every allow-list entry a reason somebody can act on', function (): void {
    $thin = [];

    foreach (BOUNDED_READ_ALLOWED as $key => $entry) {
        if (strlen($entry['why']) < 40) {
            $thin[] = $key;
        }
    }

    expect($thin)->toBe([], implode("\n  ", [
        'An allow-list entry is a claim under review, and a claim nobody can act on is a waiver. Say what',
        'bounds the read — a predicate, a window, a count somebody can picture — or say KNOWN UNBOUNDED',
        'and add it to the baseline. Too thin to act on:',
        ...$thin,
    ]));
});
