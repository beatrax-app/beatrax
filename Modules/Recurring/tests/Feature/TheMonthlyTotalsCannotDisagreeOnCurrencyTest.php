<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Recurring\Public\Services\FixedPaymentsViewQuery;

function monthlyTotalsReader(): User
{
    return User::query()->create([
        'username' => 'monthly-totals-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @param  list<array{0:string,1:string,2:int}>  $series  direction, currency, monthly equivalent in minor units
 */
function monthlyTotalsSeed(User $user, array $series): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    foreach ($series as $i => [$direction, $currency, $minor]) {
        $db->connection()->table('recurring_series')->insert([
            'user_id' => $user->id,
            'direction' => $direction,
            'state' => 'approved',
            'latest_currency' => $currency,
            'latest_amount_minor' => $minor,
            'monthly_equivalent_minor' => $minor,
            'detected_name' => 'monthly totals '.$currency,
            'cluster_key' => 'monthly-totals-'.$i.'-'.bin2hex(random_bytes(4)),
            'cluster_counterparty_key' => 'monthly-totals-'.$currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

// Money::plus() refuses two currencies, and monthlyEquivalentTotals() adds the
// income total to the expense total. A whole-program escape analysis read that
// pair as a CurrencyMismatchException reaching the handler on GET /recurring.
// It cannot: CrossCurrencyTotal::withRates() stamps its own $targetCurrency on
// every result it builds, and both halves are handed the one base currency the
// method resolved once. Measured over a real request, /recurring answers 200.
// This is the guard that keeps that true, because the day a second currency is
// threaded into either half the page starts answering 500 with no other test
// standing between the change and the reader.
it('denominates all three monthly totals in the reader base currency', function (): void {
    $user = monthlyTotalsReader();
    monthlyTotalsSeed($user, [
        ['expense', 'JPY', -500000],
        ['income', 'USD', 900000],
        ['expense', 'EUR', -12345],
    ]);

    $totals = app(FixedPaymentsViewQuery::class)->monthlyEquivalentTotals($user);

    expect($totals->expense->currency())->toBe('EUR')
        ->and($totals->income->currency())->toBe('EUR')
        ->and($totals->net->currency())->toBe('EUR');
});

// The stronger half: a bucket with no rate at all is LEFT OUT and named,
// never carried through at one to one and never handed on in its own
// currency. That is what makes the sum above unconditional.
it('leaves an unconvertible currency out and still totals in the base currency', function (): void {
    $user = monthlyTotalsReader();
    monthlyTotalsSeed($user, [
        ['expense', 'MGA', -700000],
        ['income', 'XPF', 400000],
        ['income', 'EUR', 250000],
    ]);

    $totals = app(FixedPaymentsViewQuery::class)->monthlyEquivalentTotals($user);

    expect($totals->expense->currency())->toBe('EUR')
        ->and($totals->income->currency())->toBe('EUR')
        ->and($totals->net->currency())->toBe('EUR')
        ->and($totals->unconverted)->toBe(['MGA', 'XPF'])
        ->and($totals->isPartial())->toBeTrue();
});

it('renders the fixed payments page across currencies rather than answering a server fault', function (): void {
    $user = monthlyTotalsReader();
    monthlyTotalsSeed($user, [
        ['expense', 'JPY', -500000],
        ['income', 'MGA', 400000],
        ['expense', 'EUR', -12345],
    ]);

    $this->actingAs($user)->get('/recurring')->assertOk();
});
