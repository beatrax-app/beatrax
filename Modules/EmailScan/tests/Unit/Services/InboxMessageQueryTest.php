<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\EmailScan\Public\Services\InboxMessageQuery;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->db = $this->app->make(DatabaseManager::class)->connection();
    $now = '2026-05-16 12:00:00';
    $this->userId = $this->db->table('users')->insertGetId([
        'username' => 'q',
        'password' => 'hash',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $this->inboxId = $this->db->table('inboxes')->insertGetId([
        'user_id' => $this->userId,
        'provider' => 'gmail',
        'email' => 'q@example.com',
        'backfill_window_months' => 3,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    foreach (
        [
            ['m-fetched-1', 'fetched'],
            ['m-fetched-2', 'fetched'],
            ['m-fetched-3', 'fetched'],
            ['m-parsed-1', 'parsed'],
            ['m-skipped-1', 'skipped'],
        ] as $i => [$msgId, $status]
    ) {
        $this->db->table('inbox_messages')->insert([
            'user_id' => $this->userId,
            'inbox_id' => $this->inboxId,
            'provider_message_id' => $msgId,
            'internal_date' => $now,
            'sender_email' => 'sender@example.com',
            'sender_name' => 'Sender',
            'subject' => "Subj {$i}",
            'status' => $status,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
});

it('forStatus(fetched) yields exactly three DTOs', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    $rows = iterator_to_array($query->forStatus('fetched'), preserve_keys: false);
    expect(count($rows))->toBe(3);
    foreach ($rows as $row) {
        expect($row)->toBeInstanceOf(InboxMessageDto::class);
        expect($row->status)->toBe('fetched');
    }
});

it('forStatus(parsed) yields exactly one DTO', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    $rows = iterator_to_array($query->forStatus('parsed'), preserve_keys: false);
    expect(count($rows))->toBe(1);
    expect($rows[0]->status)->toBe('parsed');
});

it('forStatus(skipped) yields exactly one DTO', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    $rows = iterator_to_array($query->forStatus('skipped'), preserve_keys: false);
    expect(count($rows))->toBe(1);
});

it('forStatus(unmatched) yields no DTOs when none are seeded', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    $rows = iterator_to_array($query->forStatus('unmatched'), preserve_keys: false);
    expect($rows)->toBe([]);
});

it('forStatus rejects an unknown status value', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    expect(function () use ($query): void {
        // The throw lives in the generator body, so nothing happens until
        // something iterates.
        iterator_to_array($query->forStatus('BOGUS'), preserve_keys: false);
    })->toThrow(InvalidArgumentException::class);
});

it('is lazy: the cursor does not materialise rows before the first iteration', function (): void {
    $query = $this->app->make(InboxMessageQuery::class);
    $gen = $query->forStatus('fetched');
    expect($gen)->toBeInstanceOf(Generator::class);
    // Rows still pending after the first one is consumed means the result set
    // was not buffered into an array before yielding.
    $first = $gen->current();
    expect($first)->toBeInstanceOf(InboxMessageDto::class);
    expect($gen->valid())->toBeTrue();
    $gen->next();
    expect($gen->valid())->toBeTrue();
});
