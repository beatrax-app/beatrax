<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

function bppUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('renders the backfill progress strip when an inbox has an active backfill_progress payload', function (): void {
    $user = bppUser('progress@example.com');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'progress@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => json_encode([
            'fetched_count' => 100,
            'total_estimated' => 300,
            'last_message_date' => null,
        ], JSON_THROW_ON_ERROR),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'backfilling',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertSee('Backfilling Gmail (progress@example.com):', false);
    $response->assertSee('100 / ~300', false);
    $response->assertSee('messages', false);
    $response->assertSee('wire:poll.2s="refreshBackfillProgress"', false);
});

it('hides the backfill progress strip once backfill_progress returns to NULL', function (): void {
    $user = bppUser('hide@example.com');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'hide@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $user->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertDontSee('Backfilling Gmail', false);
    $response->assertDontSee('wire:poll.2s="refreshBackfillProgress"', false);
});

it('stacks one line per active backfill when multiple inboxes are mid-backfill', function (): void {
    $user = bppUser('stacked@example.com');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    foreach (
        [
            ['gmail', 'gmail-a@example.com', 12, 100],
            ['microsoft', 'm365-b@example.com', 7, 50],
        ] as [$provider, $email, $fetched, $estimated]
    ) {
        $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
            'user_id' => $user->id,
            'provider' => $provider,
            'email' => $email,
            'backfill_window_months' => 3,
            'backfill_progress' => json_encode([
                'fetched_count' => $fetched,
                'total_estimated' => $estimated,
                'last_message_date' => null,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $db->connection()->table('inbox_scan_state')->insert([
            'user_id' => $user->id,
            'inbox_id' => $inboxId,
            'folder' => 'INBOX',
            'status' => 'backfilling',
            'retry_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertSee('Backfilling Gmail (gmail-a@example.com):', false);
    $response->assertSee('12 / ~100', false);
    $response->assertSee('Backfilling Microsoft 365 (m365-b@example.com):', false);
    $response->assertSee('7 / ~50', false);
});

it('refreshBackfillProgress() is a no-op poll target that returns null', function (): void {
    // The method is empty on purpose: the re-render Livewire does on each
    // poll tick is what re-queries the payload.
    $user = bppUser('poll@example.com');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('refreshBackfillProgress')
        ->assertOk();
});
