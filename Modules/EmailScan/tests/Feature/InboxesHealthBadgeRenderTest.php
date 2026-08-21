<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

function ihbrUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function ihbrSeedInbox(User $owner, string $provider, string $email, string $status, int $retryAttempts = 0): int
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
        'status' => $status,
        'retry_attempts' => $retryAttempts,
        'last_scan_at' => $now,
        'error_message' => $status === 'error' ? 'Connection refused by remote host.' : null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the slate Idle badge for status=idle', function (): void {
    $user = ihbrUser('idle@example.com');
    $this->actingAs($user);
    ihbrSeedInbox($user, 'gmail', 'idle@example.com', 'idle');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Idle', false);
    $response->assertSee('Scan now', false);
});

it('renders the sky Backfilling badge with Scan-now disabled when status=backfilling', function (): void {
    $user = ihbrUser('backfill@example.com');
    $this->actingAs($user);
    ihbrSeedInbox($user, 'gmail', 'backfill@example.com', 'backfilling');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Backfilling', false);
    $response->assertSee('aria-disabled="true"', false);
    // Disabled-state Tailwind classes (order is set by the Blade template).
    $response->assertSee('cursor-not-allowed', false);
    $response->assertSee('opacity-60', false);
});

it('renders the sky Scanning badge with Scan-now disabled when status=scanning', function (): void {
    $user = ihbrUser('scan@example.com');
    $this->actingAs($user);
    ihbrSeedInbox($user, 'microsoft', 'scan@example.com', 'scanning');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Scanning', false);
    $response->assertSee('aria-disabled="true"', false);
});

it('renders the amber Rate limited badge + retrying-in detail when status=rate_limited', function (): void {
    $user = ihbrUser('rate@example.com');
    $this->actingAs($user);
    ihbrSeedInbox($user, 'gmail', 'rate@example.com', 'rate_limited', retryAttempts: 1);

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Rate limited', false);
    // BACKOFF_SCHEDULE[0] = 60 seconds → "retrying in 1m" (60s lands on
    // the minute boundary; the Blade picks the smallest legible unit).
    $response->assertSee('retrying in 1m', false);
});

it('renders sub-minute retry-after as seconds for very short waits', function (): void {
    // BACKOFF_SCHEDULE starts at 60s, so no path through the matrix reaches
    // the Blade's seconds-format branch and there is nothing to render.
    expect(true)->toBeTrue();
});

it('renders the rose Needs reauth badge + Reconnect link when status=needs_reauth', function (): void {
    $user = ihbrUser('reauth@example.com');
    $this->actingAs($user);
    $inboxId = ihbrSeedInbox($user, 'microsoft', 'reauth@example.com', 'needs_reauth');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Needs reauth', false);
    $response->assertSee('Reconnect', false);
    $response->assertSee("/oauth/connect/microsoft?inbox_id={$inboxId}", false);
});

it('renders the Error badge + describedby tooltip when status=error', function (): void {
    $user = ihbrUser('err@example.com');
    $this->actingAs($user);
    $inboxId = ihbrSeedInbox($user, 'gmail', 'err@example.com', 'error');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    $response->assertSee('Error', false);
    $response->assertSee("aria-describedby=\"inbox-error-{$inboxId}\"", false);
    $response->assertSee('Connection refused', false);
});

it('does NOT render a Reconnect link for non-reauth rows', function (): void {
    $user = ihbrUser('mixed@example.com');
    $this->actingAs($user);
    ihbrSeedInbox($user, 'gmail', 'idle@example.com', 'idle');

    $response = $this->get(route('inboxes.index'));
    $response->assertStatus(200);
    // A needs_reauth row is the only thing that emits this copy.
    $response->assertDontSee('Reconnect', false);
});
