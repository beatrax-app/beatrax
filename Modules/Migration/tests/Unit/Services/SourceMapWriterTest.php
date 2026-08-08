<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Services\SourceMapWriter;
use Modules\Migration\Internal\ValueObjects\SourceMapKey;

uses(RefreshDatabase::class);

/*
 * Unit coverage for `SourceMapWriter` (13.5-06 Task 1). Not pinned by a
 * Plan 02 RED stub — added per this task's own acceptance criteria, mirroring
 * Plan 05's precedent of authoring dedicated coverage for classes with no
 * pre-existing RED stub of their own.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'source-map-writer-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->db = app(DatabaseManager::class);
    $this->writer = app(SourceMapWriter::class);
});

it('resolve() returns null before anything is recorded', function (): void {
    expect($this->writer->resolve($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key')))->toBeNull();
});

it('record() then resolve() round-trips the Beatrax id via source_external_id', function (): void {
    $this->writer->record($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key'), 'category', 42);

    expect($this->writer->resolve($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key')))->toBe(42);
});

it('record() called twice for the same key yields exactly one migration_source_map row (UNIQUE upsert)', function (): void {
    $this->writer->record($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key'), 'category', 42);
    $this->writer->record($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key'), 'category', 42);

    $count = $this->db->connection()->table('migration_source_map')
        ->where('user_id', $this->user->id)
        ->where('source_product', 'ynab4')
        ->where('source_entity_type', 'category')
        ->where('source_external_id', 'grocery-key')
        ->count();

    expect($count)->toBe(1);
});

it('natural-key fallback resolves an entity whose source id is null (D-10)', function (): void {
    $this->writer->record($this->user, new SourceMapKey('nynab', 'payee', null, 'albert heijn'), 'counterparty', 7);

    expect($this->writer->resolve($this->user, new SourceMapKey('nynab', 'payee', null, 'albert heijn')))->toBe(7);
    // An exact source_external_id lookup for a natural-key-only row never matches.
    expect($this->writer->resolve($this->user, new SourceMapKey('nynab', 'payee', 'albert heijn')))->toBeNull();
});

it('record() called twice with a null source_external_id (natural key path) yields exactly one row', function (): void {
    $this->writer->record($this->user, new SourceMapKey('nynab', 'payee', null, 'albert heijn'), 'counterparty', 7);
    $this->writer->record($this->user, new SourceMapKey('nynab', 'payee', null, 'albert heijn'), 'counterparty', 7);

    $count = $this->db->connection()->table('migration_source_map')
        ->where('user_id', $this->user->id)
        ->where('source_product', 'nynab')
        ->where('source_entity_type', 'payee')
        ->whereNull('source_external_id')
        ->where('natural_key', 'albert heijn')
        ->count();

    expect($count)->toBe(1);
});

it('baseline rows capture the source field values at import time (D-11)', function (): void {
    $this->writer->record($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key'), 'category', 42, [
        'name' => 'Groceries',
        'kind' => 'expense',
    ]);

    $mapId = $this->db->connection()->table('migration_source_map')
        ->where('user_id', $this->user->id)
        ->where('source_external_id', 'grocery-key')
        ->value('id');

    $baselines = $this->db->connection()->table('migration_import_baseline')
        ->where('migration_source_map_id', $mapId)
        ->orderBy('field_name')
        ->get()
        ->keyBy('field_name');

    expect($baselines)->toHaveCount(2);
    expect($baselines['name']->baseline_value)->toBe('Groceries');
    expect($baselines['kind']->baseline_value)->toBe('expense');

    // Re-recording with a changed field advances the SAME baseline row
    // rather than accumulating history.
    $this->writer->record($this->user, new SourceMapKey('ynab4', 'category', 'grocery-key'), 'category', 42, [
        'name' => 'Groceries & Household',
    ]);

    $refreshed = $this->db->connection()->table('migration_import_baseline')
        ->where('migration_source_map_id', $mapId)
        ->where('field_name', 'name')
        ->get();

    expect($refreshed)->toHaveCount(1);
    expect($refreshed->first()->baseline_value)->toBe('Groceries & Household');
});
