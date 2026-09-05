<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use Tests\Contracts\Support\RepoTree;
use Tests\Contracts\Support\SyncedColumnWrites;

// Capture is explicit here: a write reaches the op log because its writer
// dispatched an event, never because the row changed. A delete that skips that
// is not merely unreplicated but undone, because the peer's own history of the
// row is then the only account of it anybody has.

/**
 * @return list<string>
 */
function tablesThatTravel(): array
{
    // Read off the backfiller's own list rather than restated: a table struck
    // off there and not here would be reported for a delete it must not
    // announce.
    $reflected = new ReflectionClass(OpLogBackfiller::class);
    /** @var list<string> $deviceLocal */
    $deviceLocal = $reflected->getConstant('DEVICE_LOCAL_TABLES');

    $tables = [];
    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        if (! in_array((string) $table, $deviceLocal, true)) {
            $tables[] = (string) $table;
        }
    }

    return $tables;
}

// Modules/ alone for as long as this rule existed, so app/ was structurally
// invisible to it — and a console command that purges `transactions` and
// `import_runs` and announces nothing lived there the whole time. The walk is
// shared now, and it states which roots it does not read.
/**
 * @return list<string>
 */
function deleteWriterFiles(): array
{
    return SyncedColumnWrites::writerFiles();
}

// The chain has to be rooted at this table: a bare `->delete()` anywhere in a
// file that also names the table matched a purge of an unrelated one.
function deletesFromTable(string $table, string $source): bool
{
    return preg_match("/table\(\s*'".$table."'\s*\)(?:\s*->[a-zA-Z]+\([^;]*?\))*?\s*->delete\(\)/s", $source) === 1;
}

// DependentRowCascade builds the tombstone for each child it takes, but it
// HANDS THEM BACK: naming the class was enough to pass this rule, and
// DemoSeedCommand called it, threw the events away and then raw-deleted both
// parents. The seam only counts where the caller dispatches what it got.
function announcesADelete(string $source): bool
{
    return PatternScan::matches('/new\s+[A-Za-z]*Mutated\(|->\s*writeDelete\(/', $source)
        || (str_contains($source, 'DependentRowCascade') && PatternScan::matches('/->\s*dispatch\(/', $source));
}

// The one delete that must NOT be announced. A `users` tombstone is refused by
// the applier outright — a peer may edit the reader's settings, never remove
// the reader — so an op for it would be written, sent and dropped.
/**
 * @return array<string, string>
 */
function deletesNoPeerMayApply(): array
{
    return ['Modules/Auth/Internal/Account/UserScopedDataPurge.php' => 'users'];
}

it('announces every row it deletes from a table that travels', function (): void {
    $tables = tablesThatTravel();
    expect($tables)->not->toBeEmpty();

    $offenders = [];
    foreach (deleteWriterFiles() as $file) {
        $source = (string) file_get_contents($file);

        if (announcesADelete($source)) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file);

        foreach ($tables as $table) {
            if ((deletesNoPeerMayApply()[$relative] ?? null) === $table) {
                continue;
            }

            if (deletesFromTable($table, $source)) {
                $offenders[] = $relative.' deletes from '.$table.' and announces nothing';
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'These writers delete a row from a table that travels between devices and',
        'tell no peer, so the row lives on there and any later replay of that',
        "peer's history can hand it back:",
        ...$offenders,
        '',
        "Dispatch the table's mutation event with mutationType: 'delete' after the",
        'write — PruneNotificationsJob::announce() is the retention sweep that',
        'does it. A table that must NOT travel belongs in',
        'OpLogBackfiller::DEVICE_LOCAL_TABLES, not in an exception here.',
    ]));
});

it('reports a delete that leaves a travelling table unannounced', function (): void {
    // Assembled at runtime so the guard scanning its own tree cannot read this
    // fixture as a real offender.
    $delete = '->delete'.'()';
    $planted = "<?php \$db->table('notifications')->where('user_id', \$id)".$delete.';';

    expect(deletesFromTable('notifications', $planted))->toBeTrue()
        ->and(announcesADelete($planted))->toBeFalse();

    $announced = $planted.' $events->dispatch(new NotificationMutated(1, 1, \'delete\'));';
    expect(announcesADelete($announced))->toBeTrue();

    $elsewhere = "<?php \$db->table('op_log_quarantine')->where('user_id', \$id)".$delete.';';
    expect(deletesFromTable('notifications', $elsewhere))->toBeFalse();

    // Naming the cascade is not announcing what it handed back. This is the
    // exact shape DemoSeedCommand shipped: the call, and no dispatch.
    $discarded = "<?php \$c = new DependentRowCascade; \$c->deleteAll('transactions', \$ids, \$uid);".$planted;
    expect(announcesADelete($discarded))->toBeFalse()
        ->and(announcesADelete($discarded.' $events->dispatch($event);'))->toBeTrue();

    expect(tablesThatTravel())->toContain('notifications')
        ->not->toContain('rule_conditions', 'rule_actions', 'categorization_rules');

    // The walk has to reach both roots, and say so: this rule read no file of
    // app/ at all while claiming to hold the codebase.
    expect(deleteWriterFiles())->not->toBeEmpty()
        ->and(RepoTree::accountOf(RepoTree::RUNTIME_DOMAIN_PHP))
        ->toBe(['unaccounted' => [], 'stale' => [], 'silent' => []]);

    $roots = [];
    foreach (deleteWriterFiles() as $file) {
        $relative = str_replace(base_path().'/', '', $file);
        $roots[substr($relative, 0, (int) strpos($relative, '/'))] = true;
    }

    expect(array_keys($roots))->toEqualCanonicalizing(RepoTree::scope(RepoTree::RUNTIME_DOMAIN_PHP)['covers']);
});
