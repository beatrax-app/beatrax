<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\NavBadgeEvents;
use Modules\Core\Public\Support\PatternScan;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Public\Enums\NotificationTrigger;

uses(RefreshDatabase::class);

// dismiss() and undoDismiss() were implemented, registered and tested through
// ->call(), and no rendered control reached either: the row was one <a> with no
// siblings, so the Dismissed tab filtered to a state the reader could not
// produce.

function inboxActionsUser(): User
{
    return User::query()->create([
        'username' => 'inbox-actions-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function inboxActionsRow(DatabaseManager $db, int $userId, string $seed, array $overrides = []): string
{
    $id = hash('sha256', 'inbox-actions-'.$userId.'-'.$seed);

    $db->connection()->table('notifications')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '3 transactions imported.',
        'params' => json_encode(['target_kind' => 'import'], JSON_THROW_ON_ERROR),
        'trigger_type' => NotificationTrigger::ImportFinished,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ], $overrides));

    return $id;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('draws a dismiss control on a row the reader can still dismiss', function (): void {
    $user = inboxActionsUser();
    $id = inboxActionsRow($this->db, $user->id, 'dismissable');

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->assertSeeHtml('wire:click="dismiss(\''.$id.'\')"');
});

it('draws a restore control on a dismissed row, and no second dismiss beside it', function (): void {
    $user = inboxActionsUser();
    $id = inboxActionsRow($this->db, $user->id, 'dismissed', ['dismissed_at' => '2026-07-19 09:00:00']);

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->call('setTab', 'dismissed')
        ->assertSeeHtml('wire:click="undoDismiss(\''.$id.'\')"')
        ->assertDontSeeHtml('wire:click="dismiss(\''.$id.'\')"');
});

// Opening the row already marks it read, but that call rides a full page
// navigation that tears the document down under it, and clearing an inbox that
// way means visiting every target and coming back.
it('draws a mark-read control that does not need the row opened', function (): void {
    $user = inboxActionsUser();
    $id = inboxActionsRow($this->db, $user->id, 'unread');

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->assertSeeHtml('wire:click="markRead(\''.$id.'\')"');
});

it('leaves the mark-read control off a row that is already read', function (): void {
    $user = inboxActionsUser();
    $id = inboxActionsRow($this->db, $user->id, 'read', ['read_at' => '2026-07-19 09:00:00']);

    Livewire::actingAs($user)
        ->test(NotificationsPage::class)
        ->call('setTab', 'all')
        ->assertSeeHtml('wire:click="dismiss(\''.$id.'\')"')
        ->assertDontSeeHtml('wire:click="markRead(\''.$id.'\')"');
});

// Without a key, Livewire's morph matches the surviving rows against the wrong
// snapshot children and the row that stayed inherits the dismissed one's text.
it('keys every row so a dismissed one cannot hand its text to its neighbour', function (): void {
    $user = inboxActionsUser();
    $first = inboxActionsRow($this->db, $user->id, 'keyed-a');
    $second = inboxActionsRow($this->db, $user->id, 'keyed-b', [
        'title' => 'Budget nudge',
        'created_at' => '2026-07-17 09:00:00',
    ]);

    $component = Livewire::actingAs($user)->test(NotificationsPage::class);

    $component->assertSeeHtml('wire:key="notification-'.$first.'"')
        ->assertSeeHtml('wire:key="notification-'.$second.'"');
});

// A button inside an anchor is invalid markup, and a tap on it follows the link
// as well as firing the action — so the marks are flex siblings of the anchor,
// the way the transactions list carries a split badge on a navigating row.
it('keeps the marks outside the anchor they sit beside', function (): void {
    $user = inboxActionsUser();
    inboxActionsRow($this->db, $user->id, 'siblings');

    $html = Livewire::actingAs($user)->test(NotificationsPage::class)->html();

    $anchors = PatternScan::all('~<a\b.*?</a>~s', $html);

    expect($anchors[0])->not->toBe([]);

    foreach ($anchors[0] as $anchor) {
        expect($anchor)->not->toContain('<button');
    }
});

// Measured in Chromium with a coarse pointer at 375px and 411px against the
// built stylesheet, en/de/hu/el: the marks take their own line under the text
// and every one clears 44px. Both classes are what holds that — app.css gives
// .flex-1 a content basis on touch, so without the wrap the marks are squeezed.
it('lets the row wrap rather than squeeze its marks to the touch floor', function (): void {
    $user = inboxActionsUser();
    inboxActionsRow($this->db, $user->id, 'wrapping');

    $html = Livewire::actingAs($user)->test(NotificationsPage::class)->html();

    $row = PatternScan::first('~<div class="(flex flex-wrap[^"]*rounded-lg[^"]*)"~', $html);
    $group = PatternScan::first('~<div class="(ml-auto flex[^"]*)"~', $html);

    expect($row[1] ?? '')->toContain('flex-wrap')
        ->and($group[1] ?? '')->toContain('shrink-0')
        ->and($group[1] ?? '')->toContain('flex-wrap');
});

it('tells the rail to recount after each of the three writes', function (): void {
    $user = inboxActionsUser();
    $id = inboxActionsRow($this->db, $user->id, 'recount');

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->call('markRead', $id)
        ->assertDispatched(NavBadgeEvents::REFRESH);

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->call('dismiss', $id)
        ->assertDispatched(NavBadgeEvents::REFRESH);

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->call('undoDismiss', $id)
        ->assertDispatched(NavBadgeEvents::REFRESH);
});

// Livewire throws EventHandlerDoesNotExist when the component carries no
// listener for the name, so this fails outright until the rail listens.
it('lets the rail hear that and recount', function (): void {
    $user = inboxActionsUser();
    $first = inboxActionsRow($this->db, $user->id, 'badge-a');
    inboxActionsRow($this->db, $user->id, 'badge-b', ['created_at' => '2026-07-17 09:00:00']);

    $sidebar = Livewire::actingAs($user)->test('core.app-sidebar');
    $sidebar->assertSeeHtml('2 unread notifications');

    Livewire::actingAs($user)->test(NotificationsPage::class)->call('dismiss', $first);

    $sidebar->dispatch(NavBadgeEvents::REFRESH)
        ->assertSeeHtml('1 unread notification"')
        ->assertDontSeeHtml('2 unread notifications');
});
