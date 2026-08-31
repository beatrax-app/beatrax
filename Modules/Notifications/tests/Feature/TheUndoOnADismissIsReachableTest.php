<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Notifications\Internal\Http\Livewire\NotificationsPage;
use Modules\Notifications\Public\Enums\NotificationTrigger;

uses(RefreshDatabase::class);

// The dismiss toast used to invent its own `undo`/`undoArg` param names, which
// the shared trait does not use and the toast host never read either. The copy
// said "Dismissed — Undo" over a sentence with nothing behind it.

function undoDismissUser(): User
{
    return User::query()->create([
        'username' => 'undo-dismiss-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function undoDismissNotification(DatabaseManager $db, int $userId): string
{
    $id = hash('sha256', 'undo-dismiss-'.$userId);

    $db->connection()->table('notifications')->insert([
        'id' => $id,
        'user_id' => $userId,
        'state' => 'open',
        'read_at' => null,
        'dismissed_at' => null,
        'title' => 'Import finished',
        'body' => '3 transactions imported.',
        'params' => '{}',
        'trigger_type' => NotificationTrigger::ImportFinished,
        'created_at' => '2026-07-18 09:00:00',
        'updated_at' => '2026-07-18 09:00:00',
    ]);

    return $id;
}

it('offers the undo through the shared seam the toast host reads', function (): void {
    $user = undoDismissUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $id = undoDismissNotification($db, $user->id);

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->call('dismiss', $id)
        ->assertDispatched('toast', undoAction: 'undoDismiss', undoPayload: $id);
});

it('names the component the undo button has to call back into', function (): void {
    $user = undoDismissUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $id = undoDismissNotification($db, $user->id);

    $component = Livewire::actingAs($user)->test(NotificationsPage::class);
    $component->call('dismiss', $id);

    // By name, never by index: dismiss() raises the rail's recount as well,
    // and reading dispatches[0] made this assert against whichever of the two
    // was written first.
    /** @var list<array{name: string, params: array<string, mixed>}> $dispatches */
    $dispatches = $component->effects['dispatches'] ?? [];
    $params = [];
    foreach ($dispatches as $dispatch) {
        if ($dispatch['name'] === 'toast') {
            $params = $dispatch['params'];
        }
    }

    expect($params['componentId'] ?? null)->toBe($component->id());
});

it('leaves the word Undo to the control rather than the sentence', function (): void {
    $user = undoDismissUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $id = undoDismissNotification($db, $user->id);

    Livewire::actingAs($user)->test(NotificationsPage::class)
        ->call('dismiss', $id)
        ->assertDispatched('toast', message: 'Dismissed');
});
