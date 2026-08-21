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

it('seeds known_senders with the three original system rows plus the Phase 19 icscards.nl statement-sender row', function (): void {
    $patterns = $this->db
        ->table('known_senders')
        ->where('source', 'system')
        ->pluck('email_pattern')
        ->toArray();
    expect($patterns)->toContain('paypal.com', '@ics.nl', 'googleplay-noreply@google.com');
    // '@icscards.nl' is the second ICS domain IcsReceiptMatcher already
    // claims; without the seed row, the statement-ready email is never fetched
    // for it to match.
    expect($patterns)->toContain('@icscards.nl');
    expect(count($patterns))->toBe(4);
});

it('enforces UNIQUE on inbox_messages (inbox_id, provider_message_id)', function (): void {
    // Schema::getIndexes is unavailable on this Laravel version.
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

it('enforces UNIQUE on known_senders (user_id, email_pattern)', function (): void {
    $indexes = $this->db
        ->table('sqlite_master')
        ->where('type', 'index')
        ->where('tbl_name', 'known_senders')
        ->pluck('sql')
        ->filter()
        ->toArray();
    $combined = implode("\n", array_map('strval', $indexes));
    expect(stripos($combined, 'unique') !== false)->toBeTrue();
    expect(str_contains($combined, 'user_id') && str_contains($combined, 'email_pattern'))->toBeTrue();
});

it('UNIQUE on known_senders permits (NULL, "paypal.com") to coexist with (1, "paypal.com")', function (): void {
    // NULL != NULL is what lets the discovery loop promote a sender into a
    // user-scoped row alongside the system seed.
    $now = '2026-05-17 00:00:00';

    // The 'paypal.com' system seed is already present from the migration.
    $userId = (int) $this->db->table('users')->insertGetId([
        'username' => 'known-unique',
        'password' => 'hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->db->table('known_senders')->insert([
        'user_id' => $userId,
        'email_pattern' => 'paypal.com',
        'label' => 'PayPal (custom)',
        'source' => 'user',
        'added_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $count = $this->db
        ->table('known_senders')
        ->where('email_pattern', 'paypal.com')
        ->count();
    expect($count)->toBe(2);
});

it('UNIQUE on known_senders rejects a duplicate (user_id, email_pattern) within the same user', function (): void {
    $now = '2026-05-17 00:00:00';
    $userId = (int) $this->db->table('users')->insertGetId([
        'username' => 'known-dup',
        'password' => 'hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $row = [
        'user_id' => $userId,
        'email_pattern' => 'custom.example.com',
        'label' => 'Custom',
        'source' => 'user',
        'added_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $this->db->table('known_senders')->insert($row);
    expect(fn () => $this->db->table('known_senders')->insert($row))
        ->toThrow(Exception::class);
});
