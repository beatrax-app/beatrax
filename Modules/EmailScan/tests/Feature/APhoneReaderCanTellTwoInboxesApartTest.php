<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// The action column never shrank and the address column took whatever was left:
// 72px of 358 at 390px wide. Two accounts on one screen both drew as
// "demo-1+..." with a Disconnect beside each, and nothing on the row said which
// was which. The badge, Scan now and Disconnect measured 236px of the 358.

function twoInboxesReader(): User
{
    return User::query()->create([
        'username' => 'two-inboxes',
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
}

function twoInboxesSeed(User $owner, string $provider, string $email): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => $provider,
        'email' => $email,
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'last_scan_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('stacks an inbox row until there is width for the address and its actions both', function (): void {
    $reader = twoInboxesReader();
    twoInboxesSeed($reader, 'gmail', 'reader+gmail@beatrax.local');
    twoInboxesSeed($reader, 'microsoft', 'reader+microsoft@beatrax.local');

    $content = (string) $this->actingAs($reader)->get('/inboxes')->assertOk()->getContent();

    // Both halves are needed, the way the drift row already has them: a row that
    // only wraps still lets the rigid action column take the line first.
    expect($content)
        ->toContain('sm:flex-row sm:items-center sm:justify-between sm:gap-4')
        ->toContain('flex flex-wrap items-center gap-2 sm:shrink-0')
        ->not->toContain('flex shrink-0 items-center gap-2');

    // The addresses are what the reader tells the two rows apart by, so they are
    // rendered whole rather than left for the truncation to shorten.
    expect($content)
        ->toContain('reader+gmail@beatrax.local')
        ->toContain('reader+microsoft@beatrax.local');
});
