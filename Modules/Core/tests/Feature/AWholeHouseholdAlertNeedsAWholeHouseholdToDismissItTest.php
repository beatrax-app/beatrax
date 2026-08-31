<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;
use Modules\Core\Public\Services\SystemAlertQuery;

// The system-wide alerts are the infrastructure ones — WAL mode missing,
// PRAGMA drift, a failed OAuth scrub. One row addressed to everybody, with
// acknowledged_at on the row itself, meant either member could take a
// database-integrity warning off the other's screen for good.

function householdMember(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'household-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function machineWideAlert(): SystemAlert
{
    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'kind' => 'wal_mode_missing',
        'severity' => SystemAlertSeverity::Warning->value,
        'message' => "SQLite is not in WAL mode (currently 'delete').",
        'metadata' => ['current_mode' => 'delete'],
    ]);

    return $alert;
}

it('leaves a machine-wide warning standing for the member who has not seen it', function (): void {
    $first = householdMember('first-member');
    $second = householdMember('second-member');
    $alert = machineWideAlert();

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = $this->app->make(AcknowledgeSystemAlert::class);
    $acknowledge($alert->id, $first);

    /** @var SystemAlertQuery $query */
    $query = $this->app->make(SystemAlertQuery::class);

    expect($query->active($first)->pluck('id')->all())->not->toContain($alert->id)
        ->and($query->active($second)->pluck('id')->all())->toContain($alert->id);
});

it('never stamps the shared row, so the probe still knows the fault is open', function (): void {
    $first = householdMember('stamping-first');
    $alert = machineWideAlert();

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = $this->app->make(AcknowledgeSystemAlert::class);
    $acknowledge($alert->id, $first);

    expect(DB::connection()->table('system_alerts')->where('id', $alert->id)->value('acknowledged_at'))->toBeNull();
});

it('takes the same reader through the banner and leaves the other one alone', function (): void {
    $first = householdMember('banner-first');
    $second = householdMember('banner-second');
    $alert = machineWideAlert();

    Livewire::actingAs($first)->test(SystemAlertsBanner::class)
        ->call('acknowledge', $alert->id)
        ->assertDontSee('SQLite is not in WAL mode');

    Livewire::actingAs($second)->test(SystemAlertsBanner::class)
        ->assertSee('SQLite is not in WAL mode');
});

it('does not raise on a second dismissal by the same reader', function (): void {
    $first = householdMember('twice-first');
    $alert = machineWideAlert();

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = $this->app->make(AcknowledgeSystemAlert::class);
    $acknowledge($alert->id, $first);
    $acknowledge($alert->id, $first);

    expect(DB::connection()->table('system_alert_acknowledgements')->where('system_alert_id', $alert->id)->count())->toBe(1);
});

it('still stamps an owned alert on the row, because one person is all it was addressed to', function (): void {
    $owner = householdMember('owned-alert-owner');

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => $owner->id,
        'kind' => 'auth.lock.corrupted_key',
        'severity' => SystemAlertSeverity::Critical->value,
        'message' => 'The stored key is unreadable.',
        'metadata' => null,
    ]);

    /** @var AcknowledgeSystemAlert $acknowledge */
    $acknowledge = $this->app->make(AcknowledgeSystemAlert::class);
    $acknowledge($alert->id, $owner);

    expect(DB::connection()->table('system_alerts')->where('id', $alert->id)->value('acknowledged_at'))->not->toBeNull();
});
