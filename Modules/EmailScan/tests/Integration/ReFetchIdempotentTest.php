<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->db = $this->app->make(DatabaseManager::class)->connection();
    $now = '2026-05-16 12:00:00';
    $this->userId = $this->db->table('users')->insertGetId([
        'username' => 'idempotent',
        'password' => 'hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->inboxId = $this->db->table('inboxes')->insertGetId([
        'user_id' => $this->userId,
        'provider' => 'gmail',
        'email' => 'idempotent@example.com',
        'backfill_window_months' => 3,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
});

it('insertOrIgnore is a no-op when the same provider_message_id arrives twice', function (): void {
    $now = '2026-05-16 12:00:00';
    $row = [
        'user_id' => $this->userId,
        'inbox_id' => $this->inboxId,
        'provider_message_id' => 'abc',
        'internal_date' => $now,
        'sender_email' => 'sender@example.com',
        'sender_name' => 'Sender Example',
        'subject' => 'Test subject',
        'status' => 'fetched',
        'fetched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $this->db->table('inbox_messages')->insertOrIgnore($row);
    $this->db->table('inbox_messages')->insertOrIgnore($row);

    $count = $this->db
        ->table('inbox_messages')
        ->where('inbox_id', $this->inboxId)
        ->where('provider_message_id', 'abc')
        ->count();
    expect($count)->toBe(1);
});

it('raw insert of a duplicate UNIQUE pair raises the UNIQUE constraint violation', function (): void {
    $now = '2026-05-16 12:00:00';
    $row = [
        'user_id' => $this->userId,
        'inbox_id' => $this->inboxId,
        'provider_message_id' => 'def',
        'internal_date' => $now,
        'sender_email' => 'sender2@example.com',
        'sender_name' => null,
        'subject' => null,
        'status' => 'fetched',
        'fetched_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ];

    $this->db->table('inbox_messages')->insert($row);
    expect(fn () => $this->db->table('inbox_messages')->insert($row))
        ->toThrow(Exception::class);
});
