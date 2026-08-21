<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Builder;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->schema = $db->connection()->getSchemaBuilder();
});

it('adds the enriched_from column to transactions', function (): void {
    /** @var Builder $schema */
    $schema = $this->schema;
    expect($schema->hasColumn('transactions', 'enriched_from'))->toBeTrue();
})->group('phase-2');

it('adds the enriched_count column to import_runs', function (): void {
    /** @var Builder $schema */
    $schema = $this->schema;
    expect($schema->hasColumn('import_runs', 'enriched_count'))->toBeTrue();
})->group('phase-2');

it('replaces the composite UNIQUE index with the v3 tuple (includes booked_at, excludes source_ref)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    $row = $db->connection()
        ->selectOne("SELECT sql FROM sqlite_master WHERE type='index' AND name='transactions_fingerprint_uq'");
    expect($row)->not->toBeNull();

    $sql = $row->sql ?? null;
    expect($sql)->toBeString();
    expect($sql)->toContain('booked_at');
    expect($sql)->not->toContain('source_ref');
})->group('phase-2');

it('keeps the SHA-256 fingerprint UNIQUE index intact', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    $row = $db->connection()
        ->selectOne("SELECT sql FROM sqlite_master WHERE type='index' AND name='transactions_fingerprint_sha_uq'");
    expect($row)->not->toBeNull();

    $sql = $row->sql ?? null;
    expect($sql)->toBeString();
    expect($sql)->toContain('user_id');
    expect($sql)->toContain('fingerprint');
})->group('phase-2');

it('defaults enriched_count to 0 for new import_runs rows', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->db;
    $row = $db->connection()
        ->selectOne(
            "SELECT dflt_value FROM pragma_table_info('import_runs') WHERE name='enriched_count'"
        );
    expect($row)->not->toBeNull();
    expect((int) ($row->dflt_value ?? -1))->toBe(0);
})->group('phase-2');
