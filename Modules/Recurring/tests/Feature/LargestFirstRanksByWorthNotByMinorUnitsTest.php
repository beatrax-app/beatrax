<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

// "Biggest monthly equivalent first" ordered on the raw column, so ¥10,000 a
// month (10000 minor, about €63) outranked €99 a month (9900 minor).

// $monthlyMinor is a magnitude; the column carries the ledger's sign, which is
// what makes the raw ordering diverge from worth rather than merely rescale it.
function lfSeries(DatabaseManager $db, int $userId, string $name, int $monthlyMinor, string $currency, Direction $direction = Direction::Expense): int
{
    $signed = $direction === Direction::Expense ? -$monthlyMinor : $monthlyMinor;

    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => $direction->value,
        'detected_name' => $name,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => $signed,
        'latest_currency' => $currency,
        'monthly_equivalent_minor' => $signed,
        'variance_tolerance_percent' => 5,
        'cluster_key' => 'lf-'.$name,
        'cluster_counterparty_key' => $name,
        'next_expected_at' => '2026-06-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $db->connection()->table('exchange_rates')->updateOrInsert(
        ['base_currency' => 'EUR', 'quote_currency' => Currency::Jpy->value, 'rate_date' => '2026-05-01', 'source' => 'ecb'],
        ['rate' => '159.10', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->user = User::query()->create([
        'username' => 'largest-first',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Four rows the raw column and worth disagree on in both directions: an income
// row outranks every expense on a signed DESC whatever it is worth, and the
// largest expense the reader has sorts last on it. Two same-sign rows alone
// left the old ordering agreeing with this list by coincidence.
it('puts the larger amount first even when the smaller counts more minor units', function (): void {
    lfSeries($this->db, $this->user->id, 'tokyo-rent', 120_000, Currency::Jpy->value);
    lfSeries($this->db, $this->user->id, 'tokyo-gym', 10_000, Currency::Jpy->value);
    lfSeries($this->db, $this->user->id, 'dutch-insurance', 9_900, Currency::Eur->value);
    lfSeries($this->db, $this->user->id, 'dutch-salary', 250_000, Currency::Eur->value, Direction::Income);

    $names = array_map(
        static fn (object $dto): string => (string) $dto->detectedName,
        app(RecurringSeriesQuery::class)->approvedForUser($this->user),
    );

    expect($names)->toBe(['dutch-salary', 'tokyo-rent', 'dutch-insurance', 'tokyo-gym']);
});

it('pages a mixed-currency list without repeating or skipping a row', function (): void {
    lfSeries($this->db, $this->user->id, 'tokyo-gym', 10_000, Currency::Jpy->value);
    lfSeries($this->db, $this->user->id, 'dutch-insurance', 9_900, Currency::Eur->value);
    lfSeries($this->db, $this->user->id, 'tokyo-rent', 120_000, Currency::Jpy->value);

    $query = app(RecurringSeriesQuery::class);
    $firstPage = $query->approvedForUser($this->user, limit: 2);
    $secondPage = $query->approvedForUser($this->user, cursorId: $firstPage[1]->seriesId, limit: 2);

    $names = [
        ...array_map(static fn (object $dto): string => (string) $dto->detectedName, $firstPage),
        ...array_map(static fn (object $dto): string => (string) $dto->detectedName, $secondPage),
    ];

    expect($names)->toBe(['tokyo-rent', 'dutch-insurance', 'tokyo-gym']);
});
