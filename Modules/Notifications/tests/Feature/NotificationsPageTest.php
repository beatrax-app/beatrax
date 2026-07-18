<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Internal\Support\DeterministicKeyDeriver;

uses(RefreshDatabase::class);

/*
 * /notifications page tests — Req 2's stated acceptance criterion (renders
 * at 0/1/50+ notifications; marking read flips state and persists across
 * reload), D-01's route/vocabulary independence from EmailScan's inbox
 * route, D-04's whitelist-validated tab, and D-10's reversible dismiss.
 */

function npUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function npInsertNotification(DatabaseManager $db, int $userId, string $id, array $overrides = []): void
{
    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '3 transactions imported.',
        'params' => json_encode(['target_kind' => 'dashboard'], JSON_THROW_ON_ERROR),
        'trigger_type' => DeterministicKeyDeriver::TRIGGER_IMPORT_FINISHED,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ], $overrides));
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('redirects to /login when unauthenticated', function (): void {
    $this->get('/notifications')->assertRedirect('/login');
});

it('renders without error with zero notifications', function (): void {
    $user = npUser('np-zero');

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertSeeText('Notifications')
        ->assertSeeText("You're all caught up");
});

it('renders without error with exactly one notification', function (): void {
    $user = npUser('np-one');
    npInsertNotification($this->db, $user->id, str_repeat('1', 64));

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertSeeText('Import finished');
});

it('renders without error with 50+ notifications and exercises Load more', function (): void {
    $user = npUser('np-fifty');
    for ($i = 0; $i < 55; $i++) {
        npInsertNotification($this->db, $user->id, hash('sha256', 'np-fifty-'.$i), [
            'created_at' => '2026-07-18 09:00:00',
            'updated_at' => '2026-07-18 09:00:00',
        ]);
    }

    $this->actingAs($user)
        ->get('/notifications')
        ->assertOk()
        ->assertSeeText('Load more');
});

it('resolves notifications.index and inboxes.index as distinct, independently-resolving routes (D-01)', function (): void {
    $notificationsUrl = Route::has('notifications.index') ? route('notifications.index') : null;
    $inboxesUrl = Route::has('inboxes.index') ? route('inboxes.index') : null;

    expect($notificationsUrl)->not->toBeNull();
    expect($inboxesUrl)->not->toBeNull();
    expect($notificationsUrl)->not->toBe($inboxesUrl);

    $user = npUser('np-route-distinct');
    $this->actingAs($user)->get($notificationsUrl)->assertOk();
    $this->actingAs($user)->get($inboxesUrl)->assertOk();
});

it('renders the D-44 empty-state heading for each tab', function (): void {
    $user = npUser('np-empty-states');

    $this->actingAs($user)->get('/notifications?tab=unread')
        ->assertOk()->assertSeeText("You're all caught up");
    $this->actingAs($user)->get('/notifications?tab=all')
        ->assertOk()->assertSeeText('Nothing yet');
    $this->actingAs($user)->get('/notifications?tab=dismissed')
        ->assertOk()->assertSeeText('Nothing dismissed');
});

it('falls back to unread for a tab value outside the whitelist without throwing', function (): void {
    $user = npUser('np-bad-tab');

    $this->actingAs($user)
        ->get('/notifications?tab=bogus')
        ->assertOk()
        ->assertSeeText("You're all caught up");
});

it('flips read state via markRead and persists it across a fresh component mount', function (): void {
    $user = npUser('np-mark-read');
    $id = str_repeat('2', 64);
    npInsertNotification($this->db, $user->id, $id);

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->call('markRead', $id);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();

    // A fresh mount (simulating a page reload) must still see the read state.
    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->set('tab', 'all')
        ->assertSeeText('Import finished');

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('read_at'))->not->toBeNull();
});

it('moves a row out of Unread/All into Dismissed via dismiss, and back via undoDismiss (D-10)', function (): void {
    $user = npUser('np-dismiss-roundtrip');
    $id = str_repeat('3', 64);
    npInsertNotification($this->db, $user->id, $id);

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->call('dismiss', $id);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('dismissed_at'))->not->toBeNull();

    $this->actingAs($user)->get('/notifications?tab=unread')->assertOk()->assertDontSeeText('Import finished');
    $this->actingAs($user)->get('/notifications?tab=all')->assertOk()->assertDontSeeText('Import finished');
    $this->actingAs($user)->get('/notifications?tab=dismissed')->assertOk()->assertSeeText('Import finished');

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->call('undoDismiss', $id);

    expect($this->db->connection()->table('notifications')->where('id', $id)->value('dismissed_at'))->toBeNull();
    $this->actingAs($user)->get('/notifications?tab=unread')->assertOk()->assertSeeText('Import finished');
});

it('does not let user A see user B rows', function (): void {
    $userA = npUser('np-user-a');
    $userB = npUser('np-user-b');
    npInsertNotification($this->db, $userA->id, str_repeat('4', 64));
    npInsertNotification($this->db, $userB->id, str_repeat('5', 64), [
        'title' => 'Cash-flow shortfall ahead',
    ]);

    $this->actingAs($userA)
        ->get('/notifications?tab=all')
        ->assertOk()
        ->assertSeeText('Import finished')
        ->assertDontSeeText('Cash-flow shortfall ahead');
});
