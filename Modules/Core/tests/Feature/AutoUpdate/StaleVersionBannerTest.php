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
        'username' => 'svb-user',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function svbInsertAlert(DatabaseManager $db, array $overrides = []): int
{
    $defaults = [
        'user_id' => null,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'fixture',
        'metadata' => null,
        'created_at' => '2026-05-28 00:00:00',
        'acknowledged_at' => null,
    ];

    return $db->connection()->table('system_alerts')->insertGetId(array_merge($defaults, $overrides));
}

it('renders the update.available banner with the verbatim copy and the install + skip + release-notes actions', function (): void {
    $id = svbInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.available',
        'severity' => 'info',
        'message' => 'Update available — Beatrax 0.1.1 is ready. It will install on next launch.',
        'metadata' => json_encode(['latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Update available — Beatrax 0.1.1 is ready. It will install on next launch.', escape: false)
        ->assertSee('Install on next launch')
        ->assertSee('Skip this version')
        ->assertSee('Release notes')
        ->assertSeeHtml('wire:click="skipVersion('.$id.')"')
        ->assertSeeHtml('border-slate-200');
});

it('renders the update.stale banner with the amber severity and the update-now + remind-later actions', function (): void {
    svbInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.stale',
        'severity' => 'warning',
        'message' => 'You\'re on version 0.1.0 — version 0.1.1 has been available for 30 days. Update now.',
        'metadata' => json_encode(['currentVersion' => '0.1.0', 'latestVersion' => '0.1.1']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee("You're on version 0.1.0 — version 0.1.1 has been available for 30 days. Update now.", escape: false)
        ->assertSee('Update now')
        ->assertSee('Remind me later')
        ->assertSeeHtml('border-amber-200');
});

it('renders the update.critical banner with the rose severity and only the install-on-next-launch action (no skip)', function (): void {
    svbInsertAlert($this->db, [
        'user_id' => $this->user->id,
        'kind' => 'update.critical',
        'severity' => 'critical',
        'message' => 'Critical update available — version 0.1.2 fixes data corruption on import. Install as soon as possible.',
        'metadata' => json_encode(['newVersion' => '0.1.2', 'summary' => 'data corruption on import']),
    ]);

    Livewire::actingAs($this->user)->test(SystemAlertsBanner::class)
        ->assertSee('Critical update available — version 0.1.2 fixes data corruption on import. Install as soon as possible.', escape: false)
        ->assertSee('Install on next launch')
        ->assertDontSee('Skip this version')
        ->assertSeeHtml('border-rose-200');
});
