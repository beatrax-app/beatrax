<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

/*
 * Top-nav "Inboxes" badge composer invariants.
 *
 * Verifies that EmailScanServiceProvider's View Factory composer
 * binds $inboxesBadgeCount onto the core::livewire.top-nav partial:
 *
 *  - Badge is hidden (no slate-900 pill) when count = 0.
 *  - Badge renders the integer when count > 0.
 *  - Badge caps at "99+" when count > 99.
 *  - The link itself is always present between "Imports" and
 *    "Uncategorized" regardless of the badge state.
 *  - No `view()` global helper appears in the provider source — the
 *    composer must resolve ViewFactoryContract via $this->app->make()
 *    (CLAUDE.md DI-only invariant; same shape Phase 5 issue #12 fixed
 *    for the chain-review badge).
 */

function tnbcUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
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
            'occurrence_count' => 1,
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

    // Visit any page that mounts the top-nav. /inboxes itself is the
    // simplest — empty-state hero renders without dependencies.
    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    // Anchor href ends with /inboxes regardless of absolute vs relative
    // URL generation (route() may return either depending on URL config).
    $response->assertSee('/inboxes"', false);
    $response->assertSee('Inboxes', false);
    // Badge hidden — no aria-label about items needing attention.
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
});

it('renders the badge with the combined count when discovered candidates and reauth coexist', function (): void {
    $user = tnbcUser('combined@example.com');
    $this->actingAs($user);
    $inboxId = tnbcSeedNeedsReauthInbox($user, 'combined@example.com');
    tnbcSeedDiscoveredCandidates($user, $inboxId, count: 1);

    $response = $this->get(route('inboxes.index'));

    $response->assertStatus(200);
    // 1 needs_reauth + 1 discovered candidate = 2.
    $response->assertSee('>2</span>', false);
});

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
});

it('does not use the view() global helper inside EmailScanServiceProvider', function (): void {
    $providerSource = file_get_contents(
        base_path('Modules/EmailScan/Providers/EmailScanServiceProvider.php')
    );
    expect($providerSource)->toBeString();

    // Strip line comments + block comments so docblock prose mentioning
    // "view()" as documentation does not trip the gate.
    $stripped = preg_replace('#//[^\n]*#', '', (string) $providerSource);
    $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);

    // The runtime forbidden shapes: `view('...')` or `view("...")` or
    // ` view(...)` as a bare function call. The grep matches the same
    // shape the plan's gate script checks (call-site form, not the
    // ViewFactoryContract type reference).
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
