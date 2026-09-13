<?php

declare(strict_types=1);

use Tests\Contracts\Support\MigrationRowWrites;

// A migration is a keyless process like the scheduler and the queue worker, and
// it was the one nothing covered: every announce rule in this directory reads a
// scope that declines `/Database/Migrations/`, on the reading that a migration
// declares schema rather than writing a reader's rows. Thirty-one of them write
// into a table the merge registry declares.
// @link ../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md

// Announcing is possible: `KeylessTombstone::announce()` writes the entry under
// the one author the verifier admits without a signature and queues the peer's
// copy. Declining is the other answer, and it has to name which of these five
// claims it is making, because a reason nobody can check is not a reason.
const MIGRATION_WRITE_GROUNDS = [
    'no-op-carries-it' => 'No op carries this write on any device: the registry names none of the columns it sets, '
        .'or the table is one the backfiller keeps local, or the rows carry no owner and every capture is scoped to one.',
    'same-on-every-device' => 'The change is recomputed from rows and columns every device already holds, so each of '
        .'them makes the same one and the copies agree without a message.',
    'nothing-predates-the-rows' => 'The rows written did not exist on any device before this migration ran, so no peer '
        .'holds an earlier copy of them.',
    'the-replay-cannot-land' => 'The rows go beside a constraint this same migration installs, which refuses the create '
        .'the log would otherwise replay over the hole.',
    'the-device-answers-for-itself' => 'The value is read off state that never travels, so each device computes its own '
        .'and this one may not be imposed on the peer.',
];

// Measured at 31 migrations writing into 20 of the registry's 39 tables, over
// 263 migration files. Each floor sits below that -- far enough under to
// survive an ordinary edit, far enough over zero to fail a walk that stopped.
const MIGRATION_WRITE_FILE_FLOOR = 25;

const MIGRATION_WRITE_TABLE_FLOOR = 16;

const MIGRATION_WALK_FLOOR = 200;

// Handed to the scanner as source text rather than written into the tree: a
// guard that plants a file under the root it walks reports its own plant
// everywhere else, and the plant outlives whoever left it.
const MIGRATION_WRITE_PLANTED_SILENT = <<<'PHP'
<?php
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->db()->connection()->table('goals')->where('status', 'abandoned')->delete();
    }
};
PHP;

const MIGRATION_WRITE_PLANTED_DECLINED = <<<'PHP'
<?php
return new class extends ModuleMigration
{
    private const string DOES_NOT_ANNOUNCE = 'no-op-carries-it: goals carries name, target_minor, target_currency, target_date and status, and this sets none of them.';

    public function up(): void
    {
        $this->db()->connection()->table('goals')->update(['sort_order' => 0]);
    }
};
PHP;

const MIGRATION_WRITE_PLANTED_ANNOUNCING = <<<'PHP'
<?php
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection();
        KeylessTombstone::announce($connection, 'goals', [1, 2]);
        $connection->table('goals')->whereIn('id', [1, 2])->delete();
    }
};
PHP;

/**
 * @return list<string>
 */
function migrationWritesSayingNothing(): array
{
    $silent = [];

    foreach (MigrationRowWrites::writingADeclaredTable() as $reading) {
        $offense = migrationWriteOffense($reading);

        if ($offense !== null) {
            $silent[] = $reading['path'].' -- writes '.implode(', ', $reading['tables']).': '.$offense;
        }
    }

    sort($silent);

    return $silent;
}

/**
 * @param  array{path: string, tables: list<string>, announces: bool, ground: ?string, reason: ?string}  $reading
 */
function migrationWriteOffense(array $reading): ?string
{
    if ($reading['announces']) {
        return $reading['ground'] === null
            ? null
            : 'it announces AND declines, so the two disagree about what the peer was told';
    }

    if ($reading['ground'] === null) {
        return 'it neither announces nor carries a '.MigrationRowWrites::DECLINATION.' constant';
    }

    if (! array_key_exists($reading['ground'], MIGRATION_WRITE_GROUNDS)) {
        return '"'.$reading['ground'].'" is not one of the grounds this rule admits';
    }

    return ($reading['reason'] ?? '') === ''
        ? 'the ground is named and the sentence saying why it holds here is empty'
        : null;
}

/**
 * @return list<string>
 */
function migrationWriteStaleDeclinations(): array
{
    $stale = [];

    foreach (MigrationRowWrites::all() as $reading) {
        if ($reading['ground'] !== null && $reading['tables'] === []) {
            $stale[] = $reading['path'].' -- declines on "'.$reading['ground'].'"';
        }
    }

    sort($stale);

    return $stale;
}

it('reads the migrations that write rows into a table the merge registry declares', function (): void {
    $writing = MigrationRowWrites::writingADeclaredTable();

    $tables = [];
    foreach ($writing as $reading) {
        foreach ($reading['tables'] as $table) {
            $tables[$table] = true;
        }
    }

    expect(count(MigrationRowWrites::all()))->toBeGreaterThanOrEqual(
        MIGRATION_WALK_FLOOR,
        'The walk reached almost no migrations at all, which a narrowed path filter and a clean tree both look like.',
    );
    expect(count($writing))->toBeGreaterThanOrEqual(
        MIGRATION_WRITE_FILE_FLOOR,
        'The scan found almost no migration writing into a declared table, and a scan that matched nothing reports '
        .'exactly what a clean tree reports.',
    );
    expect(count($tables))->toBeGreaterThanOrEqual(MIGRATION_WRITE_TABLE_FLOOR, 'The same, for the tables they write into.');

    // The registry is read rather than restated, so a table struck off there is
    // struck off here: `goals` is one of the thirty-nine and the plants below
    // are written against it.
    expect(MigrationRowWrites::tables())->toContain('goals')->toContain('anomaly_alerts');
});

it('reports a migration that says nothing and clears one that declines or announces', function (): void {
    $tables = MigrationRowWrites::tables();

    $silent = MigrationRowWrites::in('planted-silent.php', MIGRATION_WRITE_PLANTED_SILENT, $tables);
    $declined = MigrationRowWrites::in('planted-declined.php', MIGRATION_WRITE_PLANTED_DECLINED, $tables);
    $announcing = MigrationRowWrites::in('planted-announcing.php', MIGRATION_WRITE_PLANTED_ANNOUNCING, $tables);

    expect([$silent['tables'], $declined['tables'], $announcing['tables']])
        ->toBe([['goals'], ['goals'], ['goals']], 'The walk missed one of the three writes it was handed, so a tree '
            .'full of them would report clean for the same reason.');

    expect(migrationWriteOffense($silent))
        ->toBe('it neither announces nor carries a '.MigrationRowWrites::DECLINATION.' constant');
    expect(migrationWriteOffense($declined))->toBeNull('A declination naming an admitted ground clears the write.');
    expect(migrationWriteOffense($announcing))->toBeNull('An announced delete is not a declined one.');

    // The delete that announces has to be told from the delete that does not,
    // or a tree where nothing announces reads the same as this one.
    expect([$silent['announces'], $declined['announces'], $announcing['announces']])->toBe([false, false, true]);
});

it('announces a row write into a declared table or says on what ground it must not', function (): void {
    expect(migrationWritesSayingNothing())->toBe([], sprintf(
        'These write row data into a table MergeRulesRegistry declares, from a process that holds no key. A peer is '
        ."told by nothing, and a rebuild replays the create the log still holds over a row that went.\n  %s\n"
        ."Announce through %s, or state the ground with:\n  private const string %s = '<ground>: <why it holds here>';\n"
        ."The grounds:\n  %s",
        implode("\n  ", migrationWritesSayingNothing()),
        MigrationRowWrites::announcement().')',
        MigrationRowWrites::DECLINATION,
        implode("\n  ", array_map(
            static fn (string $ground, string $claim): string => $ground.' -- '.$claim,
            array_keys(MIGRATION_WRITE_GROUNDS),
            MIGRATION_WRITE_GROUNDS,
        )),
    ));
});

it('keeps no declination on a migration that no longer writes a declared row', function (): void {
    expect(migrationWriteStaleDeclinations())->toBe([], sprintf(
        'These carry a %s and write into no table the registry declares. An exemption has outlived what earned it, '
        ."and the next reader takes it for a considered refusal.\n  %s",
        MigrationRowWrites::DECLINATION,
        implode("\n  ", migrationWriteStaleDeclinations()),
    ));
});
