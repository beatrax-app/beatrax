<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;

uses(RefreshDatabase::class);

function aadaAlert(User $user, string $state, ?CarbonImmutable $snoozedUntil = null): DriftAlert
{
    return DriftAlertFixture::alert($user, [
        'state' => $state,
        'snoozed_until' => $snoozedUntil,
        'actioned_at' => $state === DriftAlertState::Open->value ? null : CarbonImmutable::now(),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// A second tab, or a double-click, reaches an action whose target has already
// moved to a terminal state. The revival job catches that; the three user
// actions let InvalidStateTransitionException out as a 500.
it('acknowledging an alert another tab already dismissed leaves it dismissed instead of raising', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::DismissedCancelled->value);

    app(AcknowledgeDriftAlert::class)($alert->id, $user);

    expect(DriftAlert::query()->findOrFail($alert->id)->state)
        ->toBe(DriftAlertState::DismissedCancelled->value);
});

it('dismissing an alert another tab already acknowledged leaves it acknowledged instead of raising', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::Acknowledged->value);

    app(DismissDriftAlertAsCancelled::class)($alert->id, $user);

    expect(DriftAlert::query()->findOrFail($alert->id)->state)
        ->toBe(DriftAlertState::Acknowledged->value);
});

it('snoozing an alert another tab already acknowledged leaves it acknowledged instead of raising', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::Acknowledged->value);

    app(SnoozeDriftAlert::class)($alert->id, $user, CarbonImmutable::parse('2026-06-01 09:00:00'));

    expect(DriftAlert::query()->findOrFail($alert->id)->state)
        ->toBe(DriftAlertState::Acknowledged->value);
});

// The Open tab lists snoozed-and-expired rows with a full snooze popover, so
// re-snoozing to a different date is reachable from the normal UI — and
// snoozed -> snoozed is not an edge the state machine has.
it('re-snoozing an expired snooze to a new date moves the date instead of raising', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::Snoozed->value, CarbonImmutable::parse('2026-05-19 09:00:00'));

    app(SnoozeDriftAlert::class)($alert->id, $user, CarbonImmutable::parse('2026-06-03 09:00:00'));

    $reloaded = DriftAlert::query()->findOrFail($alert->id);
    expect($reloaded->state)->toBe(DriftAlertState::Snoozed->value);
    expect($reloaded->snoozed_until?->toDateTimeString())->toBe('2026-06-03 09:00:00');
});

// Anomaly's equivalent action enforces the bound; this one stored 2000-01-01
// verbatim, which is a snooze that is over before it begins.
it('refuses a snooze target in the past instead of storing it verbatim', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::Open->value);

    expect(fn () => app(SnoozeDriftAlert::class)($alert->id, $user, CarbonImmutable::parse('2000-01-01 00:00:00')))
        ->toThrow(InvalidArgumentException::class);

    expect(DriftAlert::query()->findOrFail($alert->id)->state)->toBe(DriftAlertState::Open->value);
});

it('refuses a snooze target beyond six months instead of storing it verbatim', function (): void {
    $user = DriftAlertFixture::user('aada');
    $alert = aadaAlert($user, DriftAlertState::Open->value);

    expect(fn () => app(SnoozeDriftAlert::class)($alert->id, $user, CarbonImmutable::parse('2027-05-20 09:00:00')))
        ->toThrow(InvalidArgumentException::class);

    expect(DriftAlert::query()->findOrFail($alert->id)->state)->toBe(DriftAlertState::Open->value);
});
