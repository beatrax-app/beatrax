<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Events\UpdateInstallRequested;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// Both handlers take an alert id straight off the wire, and the ownership
// check lives in the acknowledge action, which runs last — so a foreign id
// used to reach the row and be acted on before anything threw.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = User::query()->create([
        'username' => 'sabx-a',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->userB = User::query()->create([
        'username' => 'sabx-b',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $this->foreignAlertId = $db->connection()->table('system_alerts')->insertGetId([
        'user_id' => $this->userB->id,
        'kind' => 'update_available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => json_encode(['latestVersion' => '9.9.9']),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);
});

it('raises no install for an alert belonging to another user', function (): void {
    Event::fake([UpdateInstallRequested::class]);

    Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)
        ->call('install', $this->foreignAlertId);

    Event::assertNotDispatched(UpdateInstallRequested::class);
});

it('writes no version into the caller preferences from another user alert', function (): void {
    Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)
        ->call('skipVersion', $this->foreignAlertId);

    $preference = UserPreference::withoutGlobalScopes()
        ->where('user_id', $this->userA->id)
        ->first();

    expect($preference?->skipped_update_versions ?? [])->toBe([]);
});

it('leaves the other user alert unacknowledged', function (): void {
    Livewire::actingAs($this->userA)->test(SystemAlertsBanner::class)
        ->call('install', $this->foreignAlertId);

    $row = $this->db->connection()->table('system_alerts')->where('id', $this->foreignAlertId)->first();

    expect($row?->acknowledged_at)->toBeNull();
});
