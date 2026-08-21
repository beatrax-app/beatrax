<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Models\User;

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'sab-reconsent',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function sabReconsentInsert(DatabaseManager $db, array $overrides): int
{
    $defaults = [
        'kind' => 'oauth_reconsent_required',
        'severity' => 'warning',
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ];

    return $db->connection()->table('system_alerts')->insertGetId(array_merge($defaults, $overrides));
}

it('renders the Gmail re-consent banner with a Reconnect link to /inboxes?reconnect={inbox_id}', function (): void {
    sabReconsentInsert($this->db, [
        'user_id' => $this->user->id,
        'message' => 'Reconnect your Gmail',
        'metadata' => json_encode(['inbox_id' => 5, 'provider' => 'gmail']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Reconnect your Gmail')
        ->assertSeeHtml('/inboxes?reconnect=5')
        ->assertSee('Reconnect');
});

it('renders the Microsoft re-consent banner with the Outlook copy and a Reconnect link', function (): void {
    sabReconsentInsert($this->db, [
        'user_id' => $this->user->id,
        'message' => 'Reconnect your Outlook',
        'metadata' => json_encode(['inbox_id' => 7, 'provider' => 'microsoft']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Reconnect your Outlook')
        ->assertSeeHtml('/inboxes?reconnect=7');
});

it('renders the message but omits the Reconnect link when metadata.inbox_id is missing', function (): void {
    sabReconsentInsert($this->db, [
        'user_id' => $this->user->id,
        'message' => 'Reconnect your Gmail',
        'metadata' => json_encode(['provider' => 'gmail']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Reconnect your Gmail')
        ->assertDontSeeHtml('/inboxes?reconnect=');
});

it('does not leak a Gmail re-consent row to a different user', function (): void {
    $otherUser = User::query()->create([
        'username' => 'sab-reconsent-other',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    sabReconsentInsert($this->db, [
        'user_id' => $otherUser->id,
        'message' => 'Reconnect your Gmail',
        'metadata' => json_encode(['inbox_id' => 99, 'provider' => 'gmail']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertDontSee('Reconnect your Gmail')
        ->assertDontSeeHtml('/inboxes?reconnect=99');
});
