<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftThresholdEditor;
use Modules\Recurring\Public\Actions\SetDriftThresholdForSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function dteUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dteSeries(User $user, array $overrides = []): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('recurring_series')->insertGetId(array_merge([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'DTE fixture',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1149,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1149,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'dte::'.$suffix,
        'created_at' => '2026-05-19 00:00:00',
        'updated_at' => '2026-05-19 00:00:00',
    ], $overrides));
}

function dteCurrentThreshold(int $seriesId): ?int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('recurring_series')->where('id', $seriesId)->first(['drift_threshold_percent']);
    $raw = $row?->drift_threshold_percent ?? null;

    return is_numeric($raw) ? (int) $raw : null;
}

it('mounts with current value of NULL and renders the "global" indicator', function (): void {
    $user = dteUser('dte-null');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => null]);

    $component = Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId]);

    $component->assertSet('currentValue', null);
    $component->assertSee('global');
});

it('mounts with current value of 50 and renders the override label', function (): void {
    $user = dteUser('dte-50');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => 50]);

    $component = Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId]);

    $component->assertSet('currentValue', 50);
    $component->assertSee('±50%');
});

it('saves a new override value via the Public Action and reads back the new state', function (): void {
    $user = dteUser('dte-save');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => null]);

    Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId])
        ->call('save', 25)
        ->assertSet('currentValue', 25)
        ->assertDispatched('toast');

    expect(dteCurrentThreshold($seriesId))->toBe(25);
});

it('saves NULL when "global" is chosen, clearing any prior override', function (): void {
    $user = dteUser('dte-global');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => 50]);

    Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId])
        ->call('save', 'global')
        ->assertSet('currentValue', null);

    expect(dteCurrentThreshold($seriesId))->toBeNull();
});

it('silently rejects invalid threshold values and leaves the DB row untouched', function (): void {
    $user = dteUser('dte-invalid');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => 10]);

    // 7 is not in DriftThresholdEditor::OPTIONS. Rejected silently so a tampered
    // Livewire payload cannot surface an exception on the user's screen.
    Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId])
        ->call('save', 7)
        ->assertSet('currentValue', 10);

    expect(dteCurrentThreshold($seriesId))->toBe(10);
});

it('silently rejects non-numeric strings on save and leaves the DB row untouched', function (): void {
    $user = dteUser('dte-non-numeric');
    $seriesId = dteSeries($user, ['drift_threshold_percent' => 10]);

    // Without the input guard, `(int) "abc"` coerced to 0 and the Public Action
    // then raised InvalidArgumentException.
    Livewire::actingAs($user)
        ->test(DriftThresholdEditor::class, ['recurringSeriesId' => $seriesId])
        ->call('save', 'abc')
        ->assertSet('currentValue', 10);

    expect(dteCurrentThreshold($seriesId))->toBe(10);
});

it('Public Action raises NotFoundHttpException for a cross-user series id (cross-user-404)', function (): void {
    $owner = dteUser('dte-owner');
    $intruder = dteUser('dte-intruder');
    $seriesId = dteSeries($owner, ['drift_threshold_percent' => 5]);

    /** @var SetDriftThresholdForSeries $action */
    $action = $this->app->make(SetDriftThresholdForSeries::class);

    expect(fn () => ($action)($seriesId, $intruder, 25))
        ->toThrow(NotFoundHttpException::class);

    expect(dteCurrentThreshold($seriesId))->toBe(5);
});

it('Public Action rejects a threshold outside the shared allow-list with InvalidArgumentException', function (): void {
    $owner = dteUser('dte-invalid');
    $seriesId = dteSeries($owner, ['drift_threshold_percent' => 5]);

    /** @var SetDriftThresholdForSeries $action */
    $action = $this->app->make(SetDriftThresholdForSeries::class);

    // 7 is not one of DriftThresholdOptions::PERCENTS; the guard throws before any write.
    expect(fn () => ($action)($seriesId, $owner, 7))
        ->toThrow(InvalidArgumentException::class);

    expect(dteCurrentThreshold($seriesId))->toBe(5);
});
