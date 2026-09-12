<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Public\Services\SchemaShapeHealthCheck;
use Modules\Core\Public\Support\SchemaShape;
use Tests\Helpers\LiveSqliteConnection;

// The invariants are read off a database the migrations actually built, never
// off a list written beside them. A hand-kept list of what the schema should
// hold rots exactly where nobody is looking, which is the failure this whole
// file exists to make impossible.
const SCHEMA_DRIFT_REPAIR = 'database/migrations/2026_09_12_000020_repair_a_schema_the_migrations_table_vouched_for.php';

beforeEach(function (): void {
    $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-schemashape-'.bin2hex(random_bytes(8));
    mkdir($this->tmpRoot, 0o755, true);

    $this->builtPath = $this->tmpRoot.DIRECTORY_SEPARATOR.'built.sqlite';
    touch($this->builtPath);

    LiveSqliteConnection::pointAt($this->app, $this->builtPath);

    $this->artisan('migrate:fresh', ['--database' => LiveSqliteConnection::NAME])->assertSuccessful();
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;
    foreach (glob($tmpRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $entry) {
        @unlink($entry);
    }
    @rmdir($tmpRoot);
});

function schemaShapeConnection(): Connection
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection(LiveSqliteConnection::NAME);
}

// SQLite stores a trigger body as it was written, so the comparison is on the
// tokens rather than on the indentation the migration happened to use.
function schemaShapeSquashed(string $sql): string
{
    return trim((string) preg_replace('/\s+/', ' ', $sql));
}

/**
 * @return array<string, string>
 */
function schemaShapeStoredTriggers(Connection $connection): array
{
    $stored = [];

    foreach ($connection->select("select name, sql from sqlite_master where type = 'trigger'") as $row) {
        $name = is_object($row) ? ($row->name ?? null) : null;
        $sql = is_object($row) ? ($row->sql ?? null) : null;

        if (is_string($name) && is_string($sql)) {
            $stored[$name] = $sql;
        }
    }

    return $stored;
}

function schemaShapeDriftTheBuiltSchema(Connection $connection): void
{
    $connection->statement(
        'create table drifted_children (
            id integer primary key,
            owner_id integer null,
            foreign key(owner_id) references users(id) on delete cascade
        )',
    );

    foreach (array_keys(SchemaShape::enumGuardTriggers()) as $name) {
        $connection->statement('DROP TRIGGER '.$name);
    }
}

function schemaShapeRunRepair(): void
{
    /** @var object{up: callable} $migration */
    $migration = require base_path(SCHEMA_DRIFT_REPAIR);

    $migration->up();
}

it('builds a schema carrying no cascade and both enum guards, from the migrations alone', function (): void {
    $connection = schemaShapeConnection();

    expect(SchemaShape::cascadingTables($connection))->toBe([]);
    expect(SchemaShape::missingTriggers($connection))->toBe([]);

    // Without this the two assertions above pass on a database with no tables
    // in it at all, which is what a migrate:fresh that quietly did nothing
    // would leave behind.
    expect(schemaShapeStoredTriggers($connection))->not->toBeEmpty();
});

it('restores the trigger bodies the migrations themselves wrote, not an approximation of them', function (): void {
    $stored = schemaShapeStoredTriggers(schemaShapeConnection());

    foreach (SchemaShape::enumGuardTriggers() as $name => $definition) {
        expect($stored)->toHaveKey($name);
        expect(schemaShapeSquashed($definition))->toBe(
            schemaShapeSquashed($stored[$name]),
            $name.' is declared differently here than the migration that creates it writes it.',
        );
    }
});

it('names both faults on a database that drifted out from under its migration rows', function (): void {
    $connection = schemaShapeConnection();

    schemaShapeDriftTheBuiltSchema($connection);

    expect(SchemaShape::cascadingTables($connection))->toBe(['drifted_children']);
    expect(SchemaShape::missingTriggers($connection))->toBe(array_keys(SchemaShape::enumGuardTriggers()));

    /** @var SchemaShapeHealthCheck $health */
    $health = app(SchemaShapeHealthCheck::class);

    expect($health->severity())->toBe('warning');
    expect($health->message())->toContain('drifted_children');
    expect($health->message())->toContain('users_receipt_conflict_resolution_check_insert');
    expect($health->label())->toBe('schema shape');
});

it('repairs a drifted database whose migration rows say both repairs already ran', function (): void {
    $connection = schemaShapeConnection();

    // The rows the repair must not consult: both are recorded as run on the
    // database it is about to fix, which is the whole reason it exists.
    expect($connection->table('migrations')->where('migration', 'like', '%let_the_application_decide%')->exists())->toBeTrue();
    expect($connection->table('migrations')->where('migration', 'like', '%restore_users_receipt_conflict%')->exists())->toBeTrue();

    schemaShapeDriftTheBuiltSchema($connection);

    schemaShapeRunRepair();

    expect(SchemaShape::cascadingTables($connection))->toBe([]);
    expect(SchemaShape::missingTriggers($connection))->toBe([]);

    // The table keeps its foreign key; only the clause that deletes behind the
    // application's back is taken off it.
    $foreignKeys = $connection->select('PRAGMA foreign_key_list(drifted_children)');
    expect($foreignKeys)->toHaveCount(1);
    expect(schemaShapeStoredTriggers($connection))->toHaveKeys(array_keys(SchemaShape::enumGuardTriggers()));
});

it('puts back the guard that refuses a value no reader could render', function (): void {
    $connection = schemaShapeConnection();

    schemaShapeDriftTheBuiltSchema($connection);
    schemaShapeRunRepair();

    $connection->table('users')->insert(['username' => 'drift', 'password' => 'x']);

    expect(fn () => $connection->table('users')->update(['receipt_conflict_resolution' => 'whatever_the_peer_sent']))
        ->toThrow(QueryException::class);
});

it('changes nothing on a database already the shape the migrations declare', function (): void {
    $connection = schemaShapeConnection();

    $before = schemaShapeStoredTriggers($connection);

    schemaShapeRunRepair();
    schemaShapeRunRepair();

    expect(schemaShapeStoredTriggers($connection))->toBe($before);
    expect(SchemaShape::cascadingTables($connection))->toBe([]);
});

it('hands foreign-key enforcement back exactly as it found it', function (bool $enforced): void {
    $connection = schemaShapeConnection();

    schemaShapeDriftTheBuiltSchema($connection);
    $connection->statement('PRAGMA foreign_keys='.($enforced ? 'ON' : 'OFF'));

    schemaShapeRunRepair();

    $row = $connection->selectOne('PRAGMA foreign_keys');
    $after = is_object($row) && (int) ($row->foreign_keys ?? 1) === 1;

    // Leaving enforcement off outlives the migration on that connection, and a
    // `set null` key that silently never fires looks nothing like a migration
    // fault.
    expect($after)->toBe($enforced);
})->with([true, false]);
