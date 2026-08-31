<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\Enums\AnnualImpactTrend;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Http\Livewire\DashboardDriftBadge;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;
use Modules\Ledger\Public\Enums\Currency;

uses(RefreshDatabase::class);

function apdcAlert(User $user, int $annualizedMinor, string $state = 'open', ?CarbonImmutable $snoozedUntil = null): DriftAlert
{
    return DriftAlertFixture::alert($user, [
        'state' => $state,
        'snoozed_until' => $snoozedUntil,
        'annualized_impact_minor' => $annualizedMinor,
        'delta_minor' => intdiv($annualizedMinor, 12),
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The tile says "potential annualized cost". Summing the signed column let one
// series getting EUR 120/yr cheaper erase another getting EUR 120/yr dearer,
// and the headline read EUR 0.00 with real drift sitting open.
it('does not let a price drop erase an equal price rise from the potential annualized cost', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, -12000);
    apdcAlert($user, 12000);

    $total = app(DriftAlertQuery::class)->openAnnualizedImpactByCurrencyForUser($user);

    expect($total)->toBe([Currency::Eur->value => 12000]);
});

it('counts only the rises, so two rises add up', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, -12000);
    apdcAlert($user, -3000);

    expect(app(DriftAlertQuery::class)->openAnnualizedImpactByCurrencyForUser($user))
        ->toBe([Currency::Eur->value => 15000]);
});

// The rest of the module treats a lapsed snooze as open. Two read paths spelled
// that as state='open' only, so for up to an hour after expiry the marker and
// the cancel: savings suggestion silently went missing.
it('counts a snoozed-but-expired alert as open, the way every other read path does', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, -12000, DriftAlertState::Snoozed->value, CarbonImmutable::parse('2026-05-20 08:00:00'));

    $query = app(DriftAlertQuery::class);

    expect($query->openSeriesIdsForUser($user))->toHaveCount(1);
    expect($query->openAnnualizedImpactByCurrencyForUser($user))->toBe([Currency::Eur->value => 12000]);
});

// The query is only half the headline. The tile drew its arrow from a literal
// that could only point up, so a page whose every open alert was a price DROP
// read "3 open · ↗ EUR 0.00 annualized impact" — a rise of nothing.
it('does not point a rise arrow at a total made entirely of price drops', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, 12000);
    apdcAlert($user, 4800);

    $component = Livewire::actingAs($user)->test(DashboardDriftBadge::class);

    expect($component->viewData('totalAnnualizedImpact'))->toBe(0)
        ->and($component->viewData('impactTrend'))->toBe(AnnualImpactTrend::Flat);

    $component->assertDontSee('↗')
        ->assertSee('no added yearly cost');
});

it('points the rise arrow when something actually rose', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, -12000);

    $component = Livewire::actingAs($user)->test(DashboardDriftBadge::class);

    expect($component->viewData('impactTrend'))->toBe(AnnualImpactTrend::Rising);

    $component->assertSee('↗')
        ->assertSee('120.00');
});

it('leaves a snooze that has not yet lapsed out of the open set', function (): void {
    $user = DriftAlertFixture::user('apdc');
    apdcAlert($user, -12000, DriftAlertState::Snoozed->value, CarbonImmutable::parse('2026-06-20 08:00:00'));

    expect(app(DriftAlertQuery::class)->openSeriesIdsForUser($user))->toBe([]);
});
