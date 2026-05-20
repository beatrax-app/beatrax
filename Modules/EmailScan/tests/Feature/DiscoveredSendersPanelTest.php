<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\InboxesBadgeCount;

/*
 * /inboxes Discovered senders panel + Promote / Dismiss action invariants.
 *
 * Plan 09:
 *  - Panel renders ONLY candidate rows with occurrence_count >= 2 AND
 *    last_seen_at within last 90 days (DiscoveredSenderQuery threshold).
 *  - Panel rows sort by occurrence_count DESC then last_seen_at DESC.
 *  - promoteSender inserts a user-sourced known_senders row + transitions
 *    discovered_senders.state to 'added'.
 *  - dismissSender transitions state to 'dismissed' (no known_senders write).
 *  - Both actions are cross-user 404 + idempotent on non-candidate state.
 */

function dspUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function dspSeedInbox(User $owner): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->username.'@example.com',
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
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $inboxId;
}

function dspSeedDiscovered(
    User $owner,
    int $inboxId,
    string $sender,
    int $count,
    string $lastSeen,
    string $state = 'candidate',
    ?string $name = null,
): int {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    return (int) $db->connection()->table('discovered_senders')->insertGetId([
        'user_id' => $owner->id,
        'inbox_id' => $inboxId,
        'sender_email' => $sender,
        'sender_name' => $name,
        'occurrence_count' => $count,
        'last_seen_at' => $lastSeen,
        'state' => $state,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('renders only rows with occurrence_count >= 2 within 90 days, sorted by count DESC', function (): void {
    $user = dspUser('panel@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(3)->toDateTimeString();

    // Below threshold (count=1) — should NOT render.
    $singleshotId = dspSeedDiscovered($user, $inboxId, 'rare@example.com', 1, $recent);
    // Threshold (count=2) — should render second in the list.
    $twoId = dspSeedDiscovered($user, $inboxId, 'orders@bol.com', 2, $recent, name: 'Bol.com');
    // Highest count (count=5) — should render first.
    $fiveId = dspSeedDiscovered($user, $inboxId, 'noreply@coolblue.nl', 5, $recent, name: 'Coolblue');

    $test = Livewire::test(InboxesPage::class);

    // The two above-threshold rows render.
    $test->assertSee('orders@bol.com', false);
    $test->assertSee('noreply@coolblue.nl', false);
    $test->assertSee('Seen 5 times', false);
    $test->assertSee('Seen 2 times', false);
    $test->assertSee('Discovered senders', false);
    $test->assertSee("Senders that look like they send receipts but aren't on your known-receipts list yet", false);

    // The single-shot row does NOT render.
    $test->assertDontSee('rare@example.com', false);

    // Order verification: the Coolblue row appears before the Bol row
    // in the rendered HTML (count=5 > count=2 → DESC sort).
    $html = (string) $test->html();
    $coolbluePos = strpos($html, 'noreply@coolblue.nl');
    $bolPos = strpos($html, 'orders@bol.com');
    expect($coolbluePos)->not->toBeFalse();
    expect($bolPos)->not->toBeFalse();
    expect($coolbluePos)->toBeLessThan($bolPos);

    // Touch the id helpers so PHPStan does not flag unused.
    unset($singleshotId, $twoId, $fiveId);
});

it('excludes rows older than 90 days even when occurrence_count >= 2', function (): void {
    $user = dspUser('stale@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    // 100 days ago — past the 90d window cap.
    $tooOld = CarbonImmutable::now()->subDays(100)->toDateTimeString();
    dspSeedDiscovered($user, $inboxId, 'stale-recurring@example.com', 4, $tooOld);

    $test = Livewire::test(InboxesPage::class);
    $test->assertDontSee('stale-recurring@example.com', false);
    // The panel section itself does not render when count = 0.
    $test->assertDontSee('Discovered senders', false);
});

it('promoteSender inserts a user-sourced known_senders row and flips state to added', function (): void {
    $user = dspUser('promote@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $id = dspSeedDiscovered($user, $inboxId, 'orders@coolblue.nl', 4, $recent, name: 'Coolblue');

    Livewire::test(InboxesPage::class)
        ->call('promoteSender', $id)
        ->assertDispatched('toast', message: 'Sender added.');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $discovered = $db->connection()->table('discovered_senders')->where('id', $id)->first();
    expect($discovered)->not->toBeNull();
    expect($discovered->state)->toBe('added');

    $known = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)
        ->where('email_pattern', 'orders@coolblue.nl')
        ->first();
    expect($known)->not->toBeNull();
    expect($known->source)->toBe('user');
    expect($known->label)->toBe('Coolblue');

    // Refresh — the promoted row no longer appears in the panel feed.
    $refreshed = Livewire::test(InboxesPage::class);
    $refreshed->assertDontSee('orders@coolblue.nl', false);
});

it('promoteSender uses sender_email as label when sender_name is null', function (): void {
    $user = dspUser('label-fallback@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $id = dspSeedDiscovered($user, $inboxId, 'no-name@example.com', 3, $recent, name: null);

    Livewire::test(InboxesPage::class)->call('promoteSender', $id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $known = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)
        ->where('email_pattern', 'no-name@example.com')
        ->first();
    expect($known)->not->toBeNull();
    expect($known->label)->toBe('no-name@example.com');
});

it('dismissSender transitions state to dismissed and writes no known_senders row', function (): void {
    $user = dspUser('dismiss@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(5)->toDateTimeString();
    $id = dspSeedDiscovered($user, $inboxId, 'spam@example.com', 3, $recent);

    Livewire::test(InboxesPage::class)
        ->call('dismissSender', $id)
        ->assertDispatched('toast', message: 'Sender dismissed.');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('discovered_senders')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->state)->toBe('dismissed');

    $known = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)
        ->where('email_pattern', 'spam@example.com')
        ->first();
    expect($known)->toBeNull();

    // Refresh — dismissed row no longer appears.
    Livewire::test(InboxesPage::class)->assertDontSee('spam@example.com', false);
});

it('promoteSender on a foreign user row raises cross-user 404', function (): void {
    $alice = dspUser('alice@example.com');
    $bob = dspUser('bob@example.com');
    $bobInbox = dspSeedInbox($bob);
    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $bobsRowId = dspSeedDiscovered($bob, $bobInbox, 'bobs-sender@example.com', 4, $recent);

    $this->actingAs($alice);

    Livewire::test(InboxesPage::class)
        ->call('promoteSender', $bobsRowId)
        ->assertStatus(404);

    // Bob's row was untouched.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $bobsRow = $db->connection()->table('discovered_senders')->where('id', $bobsRowId)->first();
    expect($bobsRow)->not->toBeNull();
    expect($bobsRow->state)->toBe('candidate');
});

it('dismissSender on a foreign user row raises cross-user 404', function (): void {
    $alice = dspUser('alice2@example.com');
    $bob = dspUser('bob2@example.com');
    $bobInbox = dspSeedInbox($bob);
    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $bobsRowId = dspSeedDiscovered($bob, $bobInbox, 'foo@example.com', 4, $recent);

    $this->actingAs($alice);

    Livewire::test(InboxesPage::class)
        ->call('dismissSender', $bobsRowId)
        ->assertStatus(404);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $bobsRow = $db->connection()->table('discovered_senders')->where('id', $bobsRowId)->first();
    expect($bobsRow)->not->toBeNull();
    expect($bobsRow->state)->toBe('candidate');
});

it('re-promoting an already-promoted row is a silent no-op (idempotent)', function (): void {
    $user = dspUser('idempotent@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $id = dspSeedDiscovered($user, $inboxId, 'already@example.com', 3, $recent, state: 'added');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $knownCountBefore = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)->count();

    // Should NOT throw — re-promote on state='added' is a no-op.
    Livewire::test(InboxesPage::class)->call('promoteSender', $id);

    $knownCountAfter = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)->count();
    expect($knownCountAfter)->toBe($knownCountBefore);

    // State stays 'added'.
    $row = $db->connection()->table('discovered_senders')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->state)->toBe('added');
});

it('re-dismissing an already-dismissed row is a silent no-op', function (): void {
    $user = dspUser('idempotent-dismiss@example.com');
    $inboxId = dspSeedInbox($user);
    $this->actingAs($user);

    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $id = dspSeedDiscovered($user, $inboxId, 'dismissed@example.com', 3, $recent, state: 'dismissed');

    Livewire::test(InboxesPage::class)->call('dismissSender', $id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('discovered_senders')->where('id', $id)->first();
    expect($row)->not->toBeNull();
    expect($row->state)->toBe('dismissed');
});

it('candidatesForUser drops rows whose inbox belongs to a different user (defence-in-depth JOIN guard)', function (): void {
    // Two users, two inboxes. Seed a discovered_senders row whose
    // denormalised user_id is User A but whose inbox_id points at
    // User B's inbox. The defensive JOIN to inboxes (on inbox_id AND
    // user_id) must drop the row from User A's panel rather than
    // surface it.
    $alice = dspUser('alice-join@example.com');
    $bob = dspUser('bob-join@example.com');
    $bobInboxId = dspSeedInbox($bob);
    $this->actingAs($alice);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $recent = CarbonImmutable::now()->subDays(2)->toDateTimeString();
    $db->connection()->table('discovered_senders')->insert([
        'user_id' => $alice->id,             // denormalised user_id = Alice
        'inbox_id' => $bobInboxId,           // but inbox belongs to Bob
        'sender_email' => 'mismatch@example.com',
        'sender_name' => null,
        'occurrence_count' => 5,
        'last_seen_at' => $recent,
        'state' => 'candidate',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $query = app(DiscoveredSenderQuery::class);
    $rows = $query->candidatesForUser($alice);

    expect($rows)->toBeArray();
    expect($rows)->toHaveCount(0);
});

it('top-nav badge counts only above-threshold candidates', function (): void {
    // Reaches into InboxesBadgeCount directly to confirm the Plan 09
    // threshold tightening. The badge must mirror the panel — a count
    // > 0 means at least one row appears on the panel.
    $user = dspUser('badge-threshold@example.com');
    $inboxId = dspSeedInbox($user);

    $recent = CarbonImmutable::now()->subDays(1)->toDateTimeString();
    $tooOld = CarbonImmutable::now()->subDays(95)->toDateTimeString();

    // 1 above-threshold candidate.
    dspSeedDiscovered($user, $inboxId, 'recent-recurring@example.com', 3, $recent);
    // 1 single-shot below threshold — must NOT count.
    dspSeedDiscovered($user, $inboxId, 'single-shot@example.com', 1, $recent);
    // 1 old (outside 90d window) — must NOT count even with count >= 2.
    dspSeedDiscovered($user, $inboxId, 'old-recurring@example.com', 5, $tooOld);
    // 1 dismissed — must NOT count.
    dspSeedDiscovered($user, $inboxId, 'dismissed@example.com', 9, $recent, state: 'dismissed');

    $badgeCount = app(InboxesBadgeCount::class)
        ->forCurrentUser($user);

    expect($badgeCount)->toBe(1);
});
