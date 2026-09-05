<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;

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

/**
 * @return list<string>
 */
function deleteWriterFiles(): array
{
    $files = [];
    /** @var iterable<SplFileInfo> $walk */
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS));

    foreach ($walk as $entry) {
        $path = (string) $entry;
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        foreach (['/tests/', '/Migrations/', '/Seeders/', '/Factories/'] as $excluded) {
            if (str_contains($path, $excluded)) {
                continue 2;
            }
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

// The chain has to be rooted at this table: a bare `->delete()` anywhere in a
// file that also names the table matched a purge of an unrelated one.
function deletesFromTable(string $table, string $source): bool
{
    return preg_match("/table\(\s*'".$table."'\s*\)(?:\s*->[a-zA-Z]+\([^;]*?\))*?\s*->delete\(\)/s", $source) === 1;
}

// DependentRowCascade is the seam for a parent's children, and it announces
// each row it takes, so a caller reaching for it is already covered.
function announcesADelete(string $source): bool
{
    return preg_match('/EntityMutated\(|[A-Za-z]+Mutated\(|->writeDelete\(|DependentRowCascade/', $source) === 1;
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

        foreach ($tables as $table) {
            if (deletesFromTable($table, $source)) {
                $offenders[] = str_replace(base_path().'/', '', $file).' deletes from '.$table.' and announces nothing';
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

    expect(tablesThatTravel())->toContain('notifications')
        ->not->toContain('rule_conditions', 'rule_actions', 'categorization_rules');
});
