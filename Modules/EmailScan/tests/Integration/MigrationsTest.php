<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->db = $this->app->make(DatabaseManager::class)->connection();
});

it('creates the five Phase 6 tables on a fresh migrate', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['inboxes', 'inbox_scan_state', 'inbox_messages', 'known_senders', 'discovered_senders'] as $table) {
        expect($schema->hasTable($table))->toBeTrue("table {$table} missing");
    }
});

it('inboxes has the expected columns', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['id', 'user_id', 'provider', 'email', 'backfill_window_months', 'backfill_progress', 'created_at', 'updated_at'] as $col) {
        expect($schema->hasColumn('inboxes', $col))->toBeTrue("inboxes.{$col} missing");
    }
});

it('inbox_scan_state has the expected columns', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['id', 'user_id', 'inbox_id', 'folder', 'last_history_id', 'last_delta_link', 'last_scan_at', 'status', 'error_message', 'retry_attempts'] as $col) {
        expect($schema->hasColumn('inbox_scan_state', $col))->toBeTrue("inbox_scan_state.{$col} missing");
    }
});

it('inbox_messages has the expected columns', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['id', 'user_id', 'inbox_id', 'provider_message_id', 'internal_date', 'sender_email', 'sender_name', 'subject', 'status', 'fetched_at'] as $col) {
        expect($schema->hasColumn('inbox_messages', $col))->toBeTrue("inbox_messages.{$col} missing");
    }
});

it('known_senders has the expected columns', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['id', 'user_id', 'email_pattern', 'label', 'source', 'added_at'] as $col) {
        expect($schema->hasColumn('known_senders', $col))->toBeTrue("known_senders.{$col} missing");
    }
});

it('discovered_senders has the expected columns', function (): void {
    $schema = $this->db->getSchemaBuilder();
    foreach (['id', 'user_id', 'inbox_id', 'sender_email', 'sender_name', 'occurrence_count', 'last_seen_at', 'sample_message_id', 'state'] as $col) {
        expect($schema->hasColumn('discovered_senders', $col))->toBeTrue("discovered_senders.{$col} missing");
    }
});

it('seeds known_senders with the three system rows', function (): void {
    $patterns = $this->db
        ->table('known_senders')
        ->where('source', 'system')
        ->pluck('email_pattern')
        ->toArray();
    expect($patterns)->toContain('paypal.com', '@ics.nl', 'googleplay-noreply@google.com');
    expect(count($patterns))->toBe(3);
});

it('enforces UNIQUE on inbox_messages (inbox_id, provider_message_id)', function (): void {
    // sqlite_master is the canonical introspection for indexes when
    // Schema::getIndexes isn't available in this Laravel version.
    $indexes = $this->db
        ->table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'inbox_messages')
        ->pluck('sql')
        ->filter()
        ->toArray();
    $combined = implode("\n", array_map('strval', $indexes));
    expect(stripos($combined, 'unique') !== false)->toBeTrue();
    expect(str_contains($combined, 'inbox_id') && str_contains($combined, 'provider_message_id'))->toBeTrue();
});

it('enforces UNIQUE on inbox_scan_state (inbox_id, folder)', function (): void {
    $indexes = $this->db
        ->table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'inbox_scan_state')
        ->pluck('sql')
        ->filter()
        ->toArray();
    $combined = implode("\n", array_map('strval', $indexes));
    expect(stripos($combined, 'unique') !== false)->toBeTrue();
    expect(str_contains($combined, 'inbox_id') && str_contains($combined, 'folder'))->toBeTrue();
});

it('enforces UNIQUE on discovered_senders (user_id, inbox_id, sender_email)', function (): void {
    $indexes = $this->db
        ->table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'discovered_senders')
        ->pluck('sql')
        ->filter()
        ->toArray();
    $combined = implode("\n", array_map('strval', $indexes));
    expect(stripos($combined, 'unique') !== false)->toBeTrue();
    expect(str_contains($combined, 'user_id') && str_contains($combined, 'inbox_id') && str_contains($combined, 'sender_email'))->toBeTrue();
});
