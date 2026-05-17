<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\Dashboard;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\InboxScanStateMachine;

/*
 * Reauth-detected toast invariants (UI-SPEC § Reauth-detected toast).
 *
 *  - Renders when at least one inbox is in needs_reauth state.
 *  - Dismiss writes a session-scoped timestamp; the toast hides for
 *    the rest of the session.
 *  - Once every needs_reauth inbox returns to a non-reauth state, the
 *    toast hides on the next render even without an explicit dismiss
 *    (the @if guard short-circuits on reauthInboxCount = 0).
 */

function igtUser(string $email): User
{
    return User::query()->create([
        'email' => $email,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function igtSeedReauthInbox(User $owner): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $owner->id,
        'provider' => 'gmail',
        'email' => $owner->email,
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

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-17 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders the reauth toast when an inbox is in needs_reauth', function (): void {
    $user = igtUser('reauth@example.com');
    $this->actingAs($user);
    igtSeedReauthInbox($user);

    // assertSee defaults to escape=true which HTML-encodes the apostrophe
    // ("can't" → "can&#039;t"); pass escape=false to compare against the
    // literal source string. (Livewire test rig strips initial-data JSON
    // and matches against the rendered fragment.)
    Livewire::test(Dashboard::class)
        ->assertSee('An inbox needs reconnecting.')
        ->assertSee('One or more inboxes were signed out', escape: false)
        ->assertSee("can't scan them until you reconnect", escape: false)
        ->assertSee('Go to Inboxes');
});

it('hides the reauth toast after dismissReauthToast is called', function (): void {
    $user = igtUser('dismiss@example.com');
    $this->actingAs($user);
    igtSeedReauthInbox($user);

    Livewire::test(Dashboard::class)
        ->assertSee('An inbox needs reconnecting.')
        ->call('dismissReauthToast')
        ->assertDontSee('An inbox needs reconnecting.');
});

it('hides the reauth toast automatically when every inbox returns to a non-reauth state', function (): void {
    $user = igtUser('cleared@example.com');
    $this->actingAs($user);
    $inboxId = igtSeedReauthInbox($user);

    Livewire::test(Dashboard::class)
        ->assertSee('An inbox needs reconnecting.');

    // Flip the inbox back to idle via the state machine (the only
    // legal mutator of inbox_scan_state.status). The toast hides on
    // next render even without an explicit dismiss.
    /** @var InboxScanStateMachine $sm */
    $sm = $this->app->make(InboxScanStateMachine::class);
    $sm->applyStatus($inboxId, 'idle');

    Livewire::test(Dashboard::class)
        ->assertDontSee('An inbox needs reconnecting.');
});

it('does NOT render the reauth toast when no inbox is in needs_reauth', function (): void {
    $user = igtUser('clean@example.com');
    $this->actingAs($user);
    // Seed one inbox in idle state.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();
    $inboxId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'email' => 'clean@example.com',
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

    Livewire::test(Dashboard::class)
        ->assertDontSee('An inbox needs reconnecting.');
});
