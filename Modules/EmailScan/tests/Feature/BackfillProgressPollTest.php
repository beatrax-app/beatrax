<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;

/*
 * Backfill progress strip rendering invariant.
 *
 * The /inboxes page renders the backfill progress strip while
 * any inbox has a non-null backfill_progress payload. The strip
 * carries a `wire:poll.2s="refreshBackfillProgress"` attribute
 * so a live count climbs without a full page reload. When every
 * inbox's payload returns to NULL the strip disappears
 * entirely (the @if guard short-circuits).
 *
 * The InboxQuery::forCurrentUser hydration projects the JSON
 * payload into fetched_count + total_estimated DTO fields; this
 * test verifies the Blade renders both numbers verbatim,
 * including the leading `~` on the estimate.
 */

function bppUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
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
    // The poll method is intentionally empty; Livewire re-renders
    // the component on every poll tick, which re-queries the
    // backfill_progress payload via InboxQuery and surfaces the
    // latest counters.
    $user = bppUser('poll@example.com');
    $this->actingAs($user);

    Livewire::test(InboxesPage::class)
        ->call('refreshBackfillProgress')
        ->assertOk();
});
