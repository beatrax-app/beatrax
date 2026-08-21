<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Http\Livewire\InboxesPage;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\InboxesBadgeCount;

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

    $singleshotId = dspSeedDiscovered($user, $inboxId, 'rare@example.com', 1, $recent);
    $twoId = dspSeedDiscovered($user, $inboxId, 'orders@bol.com', 2, $recent, name: 'Bol.com');
    $fiveId = dspSeedDiscovered($user, $inboxId, 'noreply@coolblue.nl', 5, $recent, name: 'Coolblue');

    $test = Livewire::test(InboxesPage::class);

    $test->assertSee('orders@bol.com', false);
    $test->assertSee('noreply@coolblue.nl', false);
    $test->assertSee('Seen 5 times', false);
    $test->assertSee('Seen 2 times', false);
    $test->assertSee('Discovered senders', false);
    $test->assertSee("Senders that look like they send receipts but aren't on your known-receipts list yet", false);

    $test->assertDontSee('rare@example.com', false);

    // count=5 sorts ahead of count=2, so Coolblue precedes Bol in the HTML.
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

    Livewire::test(InboxesPage::class)->call('promoteSender', $id);

    $knownCountAfter = $db->connection()->table('known_senders')
        ->where('user_id', $user->id)->count();
    expect($knownCountAfter)->toBe($knownCountBefore);

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
    // The seeded row's denormalised user_id says Alice while its inbox_id
    // points at Bob's inbox. The JOIN on both columns is what drops it.
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
    // The badge has to mirror the panel: a count above zero means at least
    // one row is actually on it.
    $user = dspUser('badge-threshold@example.com');
    $inboxId = dspSeedInbox($user);

    $recent = CarbonImmutable::now()->subDays(1)->toDateTimeString();
    $tooOld = CarbonImmutable::now()->subDays(95)->toDateTimeString();

    dspSeedDiscovered($user, $inboxId, 'recent-recurring@example.com', 3, $recent);
    dspSeedDiscovered($user, $inboxId, 'single-shot@example.com', 1, $recent);
    dspSeedDiscovered($user, $inboxId, 'old-recurring@example.com', 5, $tooOld);
    dspSeedDiscovered($user, $inboxId, 'dismissed@example.com', 9, $recent, state: 'dismissed');

    $badgeCount = app(InboxesBadgeCount::class)
        ->forCurrentUser($user);

    expect($badgeCount)->toBe(1);
});
