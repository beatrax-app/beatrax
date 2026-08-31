<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;

function vanishedUser(): User
{
    return User::query()->create([
        'username' => 'vanished-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = vanishedUser();
});

afterEach(fn () => CarbonImmutable::setTestNow());

// Two tabs, or the paired device: the row on screen is already gone, and the
// action raises NotFoundHttpException. The house standard is a calm flash.
it('flashes rather than 404s for every drift action on a row that is gone', function (string $method, array $args): void {
    Livewire::actingAs($this->user)
        ->test(DriftPage::class)
        ->call($method, ...$args)
        ->assertOk()
        ->assertDispatched('toast');
})->with([
    'acknowledge' => ['acknowledge', [424242]],
    'snooze' => ['snooze', [424242, '2026-05-27T09:00:00+00:00']],
    'dismissAsCancelled' => ['dismissAsCancelled', [424242]],
    'modelCancelInForecast' => ['modelCancelInForecast', [424242]],
]);

it('flashes rather than 404s for every anomaly action on a row that is gone', function (string $method, array $args): void {
    Livewire::actingAs($this->user)
        ->test(DriftPage::class, ['type' => 'anomaly'])
        ->call($method, ...$args)
        ->assertOk()
        ->assertDispatched('toast');
})->with([
    'acknowledgeAnomaly' => ['acknowledgeAnomaly', ['424242']],
    'snoozeAnomaly' => ['snoozeAnomaly', ['424242', '2026-05-27T09:00:00+00:00']],
    'dismissAnomaly' => ['dismissAnomaly', ['424242']],
    'markAnomalyExpected' => ['markAnomalyExpected', ['424242']],
    'undoAnomalySuppression' => ['undoAnomalySuppression', ['424242']],
]);
