<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Public\Support\QueryFailure;

// SQLite answers every constraint it can break with SQLSTATE 23000 and driver
// code 19. Reading the SQLSTATE alone therefore called a foreign-key or
// NOT NULL failure a duplicate, and every caller acts on that answer by
// carrying on as if the row were already there — a write that did not happen,
// reported as an idempotent no-op.

function queryFailureTables(DatabaseManager $db): void
{
    $connection = $db->connection();
    $connection->statement('PRAGMA foreign_keys = ON');

    $schema = $connection->getSchemaBuilder();

    $schema->create('query_failure_parents', static function (Blueprint $table): void {
        $table->id();
    });

    $schema->create('query_failure_children', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('parent_id')->constrained('query_failure_parents');
        $table->string('slug')->unique();
        $table->string('note');
    });

    $connection->table('query_failure_parents')->insert(['id' => 1]);
    $connection->table('query_failure_children')->insert([
        'id' => 1, 'parent_id' => 1, 'slug' => 'taken', 'note' => 'first',
    ]);
}

/**
 * @param  array<string, mixed>  $row
 */
function queryFailureFrom(DatabaseManager $db, array $row): QueryException
{
    try {
        $db->connection()->table('query_failure_children')->insert($row);
    } catch (QueryException $e) {
        return $e;
    }

    throw new RuntimeException('The write was expected to fail and did not.');
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    queryFailureTables($db);
});

it('calls a unique collision a duplicate', function (): void {
    $e = queryFailureFrom($this->db, ['id' => 2, 'parent_id' => 1, 'slug' => 'taken', 'note' => 'second']);

    expect((string) $e->getCode())->toBe('23000')
        ->and(QueryFailure::isUniqueViolation($e))->toBeTrue();
});

it('refuses to call a foreign-key failure a duplicate', function (): void {
    $e = queryFailureFrom($this->db, ['id' => 3, 'parent_id' => 404, 'slug' => 'free', 'note' => 'third']);

    expect((string) $e->getCode())->toBe('23000')
        ->and(QueryFailure::isUniqueViolation($e))->toBeFalse();
});

it('refuses to call a NOT NULL failure a duplicate', function (): void {
    $e = queryFailureFrom($this->db, ['id' => 4, 'parent_id' => 1, 'slug' => 'also-free', 'note' => null]);

    expect((string) $e->getCode())->toBe('23000')
        ->and(QueryFailure::isUniqueViolation($e))->toBeFalse();
});

it('reads the driver sentence, not a binding that happens to quote one', function (): void {
    // Laravel appends the statement and its bindings to the exception message,
    // so a stored value quoting the driver would otherwise classify its own
    // failed write as a duplicate.
    $e = queryFailureFrom($this->db, [
        'id' => 5,
        'parent_id' => 404,
        'slug' => 'UNIQUE constraint failed: query_failure_children.slug',
        'note' => 'fifth',
    ]);

    expect($e->getMessage())->toContain('UNIQUE constraint failed')
        ->and(QueryFailure::isUniqueViolation($e))->toBeFalse();
});
