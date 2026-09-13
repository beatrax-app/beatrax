<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\RenderedMarkup;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Public\Enums\NotificationTrigger;

// Unread was a blue dot and a heavier title. The dot was aria-hidden and font
// weight is not announced, so the one state on this screen a reader acts on —
// which of these have I already seen — reached nobody listening to it. The word
// is the one the Unread tab overhead already uses, so no locale learns a second.

function unreadWordUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function unreadWordNotification(DatabaseManager $db, int $userId, string $id, string $title, ?string $readAt): void
{
    $db->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => $readAt,
        'dismissed_at' => null,
        'title' => $title,
        'body' => 'fixture body',
        'params' => json_encode(['target_kind' => 'dashboard'], JSON_THROW_ON_ERROR),
        'trigger_type' => NotificationTrigger::ImportFinished,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);
}

/**
 * Every accessible name a row carries on a graphic, keyed by the row's title.
 * Reading names rather than classes is the point: a hue has no name.
 *
 * @return array<string, list<string>>
 */
function unreadWordGraphicNames(string $html): array
{
    $named = [];

    foreach (RenderedMarkup::of($html)->all('[data-testid="notification-row"]') as $row) {
        $title = $row->firstOrFail('p')->text();
        $named[$title] = [];

        foreach ($row->all('[role="img"]') as $graphic) {
            $named[$title][] = (string) $graphic->attribute('aria-label');
        }
    }

    return $named;
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->reader = unreadWordUser('unread-word-reader');
});

it('names an unread row unread, and leaves a read one carrying no such name', function (): void {
    unreadWordNotification($this->db, $this->reader->id, str_repeat('a', 64), 'Row still unread', null);
    unreadWordNotification($this->db, $this->reader->id, str_repeat('b', 64), 'Row already read', '2026-07-18 10:00:00');

    $component = Livewire::actingAs($this->reader)->test(NotificationsPage::class);
    $component->call('setTab', 'all');

    $named = unreadWordGraphicNames((string) $component->html());
    $unread = Lang::get('notifications::inbox.tabs.unread');

    expect(array_keys($named))->toContain('Row still unread', 'Row already read');

    expect($named['Row still unread'])->toContain($unread)
        ->and($named['Row already read'])->not->toContain($unread);
});
