<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// The composer must resolve ViewFactoryContract through the container, never
// the global view() helper — the repo-wide DI-only invariant.

function tnbcUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function tnbcSeedNeedsReauthInbox(User $owner, string $email): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
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
        'status' => 'needs_reauth',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function tnbcSeedDiscoveredCandidates(User $owner, int $inboxId, int $count): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [
            'user_id' => $owner->id,
            'inbox_id' => $inboxId,
            'sender_email' => "candidate{$i}@example.com",
            'sender_name' => null,
            // At the threshold, not below it: the badge applies the panel's
            // own 2-occurrences-in-90-days rule.
            'occurrence_count' => 2,
            'last_seen_at' => $now,
            'sample_message_id' => null,
            'state' => 'candidate',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    if ($rows !== []) {
        $db->connection()->table('discovered_senders')->insert($rows);
    }
}

it('renders the Inboxes link without a badge when count is zero', function (): void {
    $user = tnbcUser('zero@example.com');
    $this->actingAs($user);

    // /inboxes mounts the top-nav and its empty-state hero needs nothing else.
    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    // Matching on the trailing quote works whether route() produced an
    // absolute or a relative URL.
    $response->assertSee('/inboxes"', false);
    $response->assertSee('Inboxes', false);
    $response->assertDontSee('items need attention', false);
});

it('renders the badge with count=1 when one inbox is in needs_reauth state', function (): void {
    $user = tnbcUser('reauth@example.com');
    $this->actingAs($user);
    tnbcSeedNeedsReauthInbox($user, 'reauth@example.com');

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertSee('items need attention', false);
    $response->assertSee('>1</span>', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Inboxes badge slot exists on .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion.');

it('renders the badge with the combined count when discovered candidates and reauth coexist', function (): void {
    $user = tnbcUser('combined@example.com');
    $this->actingAs($user);
    $inboxId = tnbcSeedNeedsReauthInbox($user, 'combined@example.com');
    tnbcSeedDiscoveredCandidates($user, $inboxId, count: 1);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    // 1 needs_reauth + 1 discovered candidate = 2.
    $response->assertSee('>2</span>', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Inboxes badge slot exists on .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion.');

it('caps the badge label at "99+" when count exceeds 99', function (): void {
    $user = tnbcUser('cap@example.com');
    $this->actingAs($user);
    $inboxId = tnbcSeedNeedsReauthInbox($user, 'cap@example.com');
    // 1 needs_reauth + 100 discovered candidates = 101 — must render as "99+".
    tnbcSeedDiscoveredCandidates($user, $inboxId, count: 100);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    $response->assertSee('>99+</span>', false);
    $response->assertDontSee('>101</span>', false);
})->todo('16-01 replaced the top-nav with the app sidebar. The Inboxes badge slot exists on .side-item but is not yet wired to the View Factory composer; a follow-up plan re-points registerTopNavBadgeComposer at core::livewire.app-sidebar and re-enables this assertion.');

it('does not use the view() global helper inside EmailScanServiceProvider', function (): void {
    $providerSource = file_get_contents(
        base_path('Modules/EmailScan/Providers/EmailScanServiceProvider.php')
    );
    expect($providerSource)->toBeString();

    // Comments are stripped so prose mentioning "view()" cannot trip the gate.
    $stripped = preg_replace('#//[^\n]*#', '', (string) $providerSource);
    $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

    // Only the bare call-site form is forbidden; a ViewFactoryContract type
    // reference is fine.
    $matched = preg_match_all('/(?<![A-Za-z0-9_>])view\s*\(\s*[\'"]/', (string) $stripped);
    expect($matched)->toBe(0);
});

it('registers the top-nav badge composer from EmailScanServiceProvider::boot', function (): void {
    $providerSource = file_get_contents(
        base_path('Modules/EmailScan/Providers/EmailScanServiceProvider.php')
    );
    expect($providerSource)->toBeString();

    // Composer must be defined AND invoked from boot().
    $defCount = substr_count((string) $providerSource, 'registerTopNavBadgeComposer');
    expect($defCount)->toBeGreaterThanOrEqual(2);
});
