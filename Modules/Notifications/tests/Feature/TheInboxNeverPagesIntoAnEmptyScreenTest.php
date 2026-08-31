<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Public\Services\NotificationQuery;

uses(RefreshDatabase::class);

// The lookahead row was read, rendered AND used as the cursor, so a page held
// one row more than the page size and the next page began after it. At an
// exact multiple of that number the second page was empty, with a "Load more"
// button behind the reader and no control to go back.

function inboxPagingUser(): User
{
    return User::query()->create([
        'username' => 'paging-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function seedNotificationInbox(DatabaseManager $db, int $userId, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $db->connection()->table('notifications')->insert([
            'id' => hash('sha256', 'paging-'.$userId.'-'.$i),
            'user_id' => $userId,
            'trigger_type' => 'payment_reminder',
            'title' => 'Reminder '.$i,
            'body' => 'Body '.$i,
            'params' => '{}',
            'state' => 'open',
            'read_at' => null,
            'dismissed_at' => null,
            'created_at' => '2026-07-18 09:00:00',
            'updated_at' => '2026-07-18 09:00:00',
        ]);
    }
}

it('renders exactly one page and withholds the row that only says there is another', function (): void {
    $user = inboxPagingUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedNotificationInbox($db, $user->id, NotificationQuery::PAGE_SIZE + 1);

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);
    $page = $query->allForUser($user);

    expect($page['rows'])->toHaveCount(NotificationQuery::PAGE_SIZE)
        ->and($page['nextCursor'])->not->toBeNull();
});

it('lands the second page on the rows nobody has seen when the inbox is an exact multiple of a page', function (): void {
    $user = inboxPagingUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedNotificationInbox($db, $user->id, NotificationQuery::PAGE_SIZE * 2);

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    $first = $query->allForUser($user);
    expect($first['nextCursor'])->not->toBeNull();

    $second = $query->allForUser($user, $first['nextCursor']);

    expect($second['rows'])->toHaveCount(NotificationQuery::PAGE_SIZE)
        ->and($second['nextCursor'])->toBeNull();
});

it('offers no Load more when the last row on the page is the last row there is', function (): void {
    $user = inboxPagingUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedNotificationInbox($db, $user->id, NotificationQuery::PAGE_SIZE);

    /** @var NotificationQuery $query */
    $query = $this->app->make(NotificationQuery::class);

    expect($query->allForUser($user)['nextCursor'])->toBeNull();
});

it('does not draw the Load more control on a page it has already exhausted', function (): void {
    $user = inboxPagingUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    seedNotificationInbox($db, $user->id, NotificationQuery::PAGE_SIZE * 2);

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->set('tab', 'all')
        ->assertSee('Load more')
        ->set('cursor', $this->app->make(NotificationQuery::class)->allForUser($user)['nextCursor'])
        ->assertDontSee('Load more')
        ->assertDontSee('Nothing yet');
});
