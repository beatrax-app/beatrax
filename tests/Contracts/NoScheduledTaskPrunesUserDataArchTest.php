<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Public\Support\PatternScan;

// Retention is indefinite by default, and the exceptions are operational
// artefacts. A daily sweep deleted counterparties on a 365-day window and NULLed
// transactions.counterparty_id on every ledger row that named one — on a replica
// holding 35 of the household's 140 transactions, so "nothing points at this"
// meant "the rows that point at this have not arrived yet". Seventeen payees
// went; the peer still held all of them, and 52 of its transactions named them.

/** @return list<string> the tables the product promises to keep for good */
function tablesHoldingUserData(): array
{
    return [
        'accounts',
        'categories',
        'categorization_rules',
        'counterparties',
        'envelope_assignments',
        'goal_contributions',
        'goals',
        'merchant_aliases',
        'notifications',
        'pot_movements',
        'pots',
        'tax_transaction_tags',
        'transaction_splits',
        'transactions',
    ];
}

/**
 * The exceptions, each an operational artefact rather than something the reader
 * authored, and each carrying the page that documents it.
 *
 * @return array<string, string> table => where the window is written down
 */
function tablesAnAutomaticSweepMayPrune(): array
{
    return [
        'notifications' => 'C8 — the notification inbox, pruned after a long window',
    ];
}

/** @return list<string> every artisan command string the scheduler runs */
function scheduledCommandStrings(): array
{
    $commands = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (! $event instanceof Event) {
            continue;
        }
        $command = (string) $event->command;
        if ($command === '') {
            continue;
        }
        $commands[] = $command;
    }

    sort($commands);

    return array_values(array_unique($commands));
}

/** @return list<string> absolute paths of the command class and the jobs it hands work to */
function filesAScheduledCommandReaches(string $commandString): array
{
    $name = null;
    foreach (array_keys(Artisan::all()) as $registered) {
        if (PatternScan::matches('/\b'.preg_quote((string) $registered, '/').'\b/', $commandString)) {
            $name = (string) $registered;
            break;
        }
    }

    if ($name === null) {
        return [];
    }

    $reflected = new ReflectionClass(Artisan::all()[$name]);
    $path = (string) $reflected->getFileName();
    if ($path === '' || ! is_file($path)) {
        return [];
    }

    $files = [$path];
    $source = (string) file_get_contents($path);

    // One hop is the whole architecture: a scheduled command resolves users and
    // dispatches a job per user, and the job is what writes.
    $imports = PatternScan::all('/^use (Modules\\\\[A-Za-z0-9_\\\\]+);$/m', $source);
    foreach ($imports[1] as $imported) {
        if (! class_exists($imported)) {
            continue;
        }
        $importedFile = (string) (new ReflectionClass($imported))->getFileName();
        if ($importedFile !== '' && is_file($importedFile) && ! in_array($importedFile, $files, true)) {
            $files[] = $importedFile;
        }
    }

    return $files;
}

// Every `->table('x')` opens a chain, and the chain belongs to that table until
// the next one opens. Reading only the first occurrence missed the notification
// sweep, whose delete is on its second: it plucks the ids, then deletes them.
//
// A query-builder chain and nothing else. `Model::query()->…->delete()` names
// no table in the source, so a sweep written that way is invisible here --
// PruneDevAuditCommand is one, on a table this rule does not guard. The
// description and the message below say "builds no query" for that reason.
/**
 * @param  list<string>  $guarded
 * @return list<string> the guarded tables this source deletes from or empties a column of
 */
function guardedTablesWrittenBy(string $source, array $guarded): array
{
    $found = PatternScan::allWithOffsets('/->table\(\s*[\'"]([a-z_]+)[\'"]\s*\)/', $source);
    $chains = $found[0];
    $names = $found[1];

    $hits = [];
    foreach ($chains as $index => $chain) {
        $table = (string) $names[$index][0];
        if (! in_array($table, $guarded, true)) {
            continue;
        }

        $from = (int) $chain[1] + strlen((string) $chain[0]);
        $to = isset($chains[$index + 1]) ? (int) $chains[$index + 1][1] : strlen($source);
        $window = substr($source, $from, $to - $from);

        if (str_contains($window, '->delete()')) {
            $hits[] = $table.' delete';

            continue;
        }

        // An update is only a retention concern when it erases a value: a status
        // flip is a sweep doing its job, a column set back to null is the sweep
        // unwriting something the reader has.
        if (PatternScan::matches('/->update\(\[[^\]]*=>\s*null/s', $window)) {
            $hits[] = $table.' update-to-null';
        }
    }

    sort($hits);

    return array_values(array_unique($hits));
}

/**
 * @param  list<string>  $guarded
 * @return list<string>
 */
function scheduledOffendersAgainst(array $guarded): array
{
    $offenders = [];

    foreach (scheduledCommandStrings() as $command) {
        foreach (filesAScheduledCommandReaches($command) as $file) {
            foreach (guardedTablesWrittenBy((string) file_get_contents($file), $guarded) as $hit) {
                $offenders[] = $command.' → '.str_replace(base_path().'/', '', $file).' '.$hit;
            }
        }
    }

    sort($offenders);

    return array_values(array_unique($offenders));
}

it('names tables that exist, so a rename cannot empty this guard', function (): void {
    $guarded = tablesHoldingUserData();

    expect($guarded)->toHaveCount(
        14,
        'tablesHoldingUserData() names '.count($guarded).' tables. The list is pinned so that adding or '
        .'removing one is a visible diff a reviewer has to agree with, rather than a rule quietly '
        .'covering less than it did.'
    );

    $missing = array_values(array_filter(
        $guarded,
        static fn (string $table): bool => ! Schema::hasTable($table),
    ));

    expect($missing)->toBe([], 'These tables no longer exist, so the guard below silently checks nothing: '.implode(', ', $missing));
});

it('finds the scheduler, so an empty schedule cannot pass this guard', function (): void {
    $commands = scheduledCommandStrings();

    expect(count($commands))->toBeGreaterThanOrEqual(
        8,
        'the scheduler resolved '.count($commands).' commands, which is too few to be this application.'
    );

    $unreachable = array_values(array_filter(
        $commands,
        static fn (string $command): bool => filesAScheduledCommandReaches($command) === [],
    ));

    expect($unreachable)->toBe([], implode("\n", [
        'These scheduled commands resolve to no source file, so the guard below never reads them:',
        '  '.implode("\n  ", $unreachable),
    ]));
});

it('exempts only tables an exception is written down for', function (): void {
    $exceptions = tablesAnAutomaticSweepMayPrune();

    expect($exceptions)->toHaveCount(
        1,
        'tablesAnAutomaticSweepMayPrune() holds '.count($exceptions).' exemptions. Each one is a table the '
        .'product stops promising to keep, so growing the list is a product decision and pinning the count '
        .'is what makes it one somebody signs off.'
    );

    $unguarded = array_values(array_diff(array_keys($exceptions), tablesHoldingUserData()));

    expect($unguarded)->toBe([], implode("\n", [
        'These tables are exempted from a guard that was never going to look at them,',
        'so the exemption reads as a decision and does nothing:',
        '  '.implode("\n  ", $unguarded),
    ]));

    // Without the exemption the notification sweep is an offender. That is what
    // makes deleting the exemption fail rather than quietly widen the rule.
    $offenders = scheduledOffendersAgainst(array_keys($exceptions));

    expect($offenders)->not->toBe([], 'No scheduled task prunes an exempted table, so every exemption here is dead.');
});

it('builds no query in a scheduled task that deletes a row of user data or erases a column of it', function (): void {
    $guarded = array_values(array_diff(tablesHoldingUserData(), array_keys(tablesAnAutomaticSweepMayPrune())));

    // Read here as well as in the case above, because this is where the verdict
    // is: an empty offender list means the same thing over a broken scheduler
    // as over a well-behaved one, and the two cases can fail independently.
    expect(count(scheduledCommandStrings()))->toBeGreaterThanOrEqual(
        8,
        'the scheduler resolved '.count(scheduledCommandStrings()).' commands, which is too few to be this '
        .'application — the verdict below would be read off a schedule nobody registered.'
    );

    expect($guarded)->not->toBe([], 'every table is exempted, so this rule guards nothing.');

    $offenders = scheduledOffendersAgainst($guarded);

    expect($offenders)->toBe([], implode("\n", [
        'These run on a timer and unwrite a table the product promises to keep:',
        '  '.implode("\n  ", $offenders),
        '',
        'Retention is indefinite for anything the reader authored. A sweep decides on',
        'one replica, and a local-first device holds a partial one — "no row points at',
        'this" is indistinguishable from "those rows have not synced yet", so the sweep',
        'deletes what the peer is still using. An operational artefact with a written',
        'window belongs in tablesAnAutomaticSweepMayPrune(), naming the page that',
        'documents it.',
        '',
        'This reads query-builder chains: an Eloquent Model::query()->delete() names no table in the',
        'source and is not seen here. A sweep written that way is still the same defect.',
    ]));
});

it('reports a sweep that deletes user data, so the scan above can fail', function (): void {
    // Assembled at runtime so the guard scanning its own tree cannot read these
    // fixtures as real offenders.
    $delete = '->delete'.'()';
    $planted = "<?php \$db->table('counterparties')->where('user_id', \$id)".$delete.';';

    expect(guardedTablesWrittenBy($planted, ['counterparties']))->toBe(['counterparties delete']);
});

it('reports a sweep that erases a column of user data, and ignores one that flips a status', function (): void {
    $update = '->update'.'(';
    $erases = "<?php \$db->table('transactions')->whereIn('counterparty_id', \$o)".$update."['counterparty_id' => null]);";
    $flips = "<?php \$db->table('transactions')->whereIn('id', \$o)".$update."['status' => 'cleared']);";

    expect(guardedTablesWrittenBy($erases, ['transactions']))->toBe(['transactions update-to-null'])
        ->and(guardedTablesWrittenBy($flips, ['transactions']))->toBe([]);
});
