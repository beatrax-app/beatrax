<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

uses(RefreshDatabase::class);

// monthly_equivalent_minor, latest_amount_minor and cadence merge as three
// independent per-field fields, so a series that crossed a sync boundary can
// carry a monthly figure its own amount disagrees with. RecurringSeriesDtoMapper
// re-derives for that reason and every row on /recurring goes through it -- and
// the header above those rows summed the stored copy instead, so the two halves
// of one screen answered with different money.

function staleMonthlySeries(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // A weekly EUR 10.00 expense whose stored monthly copy is the figure a
    // MONTHLY cadence would have: the exact disagreement per-field merge
    // produces when one device refreshes the amount and another the cadence.
    RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'weekly-probe',
        'state' => 'approved',
        'cadence' => 'weekly',
        'latest_amount_minor' => -1000,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1000,
        'variance_tolerance_percent' => 25,
        'cluster_key' => $username.'-weekly',
    ]);

    return $user;
}

it('sums the monthly figure its own rows print', function (): void {
    $user = staleMonthlySeries('monthly-total');

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);

    $sections = $query->viewForUser($user);
    expect($sections['expenses'])->toHaveCount(1);

    // 52/12 of ten euro, the figure MonthlyEquivalent derives and the row
    // renders: -1000 * 52 / 12 = -4333.33 -> -4333.
    expect($sections['expenses'][0]->monthlyEquivalent->toMinor())->toBe(-4333);

    $totals = $query->monthlyEquivalentTotals($user);

    // SUM(monthly_equivalent_minor) answered -1000 here: a header reading
    // EUR 10.00 a month directly above a row reading EUR 43.33 a month.
    expect($totals->expense->toMinor())->toBe(-4333);
    expect($totals->expense->currency())->toBe('EUR');
    expect($totals->income->toMinor())->toBe(0);
    expect($totals->net->toMinor())->toBe(-4333);
    expect($totals->unconverted)->toBe([]);
});

it('keeps the stored copy for a cadence no monthly figure can be derived from', function (): void {
    $user = User::query()->create([
        'username' => 'monthly-total-irregular',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // MonthlyEquivalent::forCadence() answers null for an irregular series, and
    // the mapper falls back to the stored column there. The total has to fall
    // back the same way or the two disagree in the other direction.
    RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => 'irregular-probe',
        'state' => 'approved',
        'cadence' => 'irregular',
        'latest_amount_minor' => -2500,
        'latest_currency' => 'EUR',
        'monthly_equivalent_minor' => -1800,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'monthly-total-irregular-key',
    ]);

    /** @var FixedPaymentsViewQuery $query */
    $query = $this->app->make(FixedPaymentsViewQuery::class);

    $sections = $query->viewForUser($user);
    expect($sections['expenses'])->toHaveCount(1);
    expect($sections['expenses'][0]->monthlyEquivalent->toMinor())->toBe(-1800);
    expect($query->monthlyEquivalentTotals($user)->expense->toMinor())->toBe(-1800);
});
