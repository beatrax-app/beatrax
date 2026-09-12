<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

// Every row on /recurring prints what RecurringSeriesDtoMapper derives from
// `latest_amount_minor` and `cadence`; the list they sit in ordered on the
// stored `monthly_equivalent_minor` beside them. The three merge as three
// independent fields, so the stored copy is exactly the figure a sync can part
// from the row -- and a list headed "biggest first" then ranks on a number
// nothing on the screen shows. The keyset cursor read the same stored column,
// so fixing only the ORDER BY would have made the page boundary land where the
// sort never put a row: hence the paging case below as well as the order one.

// $storedMonthly is given separately on purpose. A fixture whose stored copy
// already agrees with its own amount cannot fail this claim either way.
function orderedApartSeries(
    DatabaseManager $db,
    int $userId,
    string $name,
    SeriesCadence $cadence,
    int $latestAmountMinor,
    int $storedMonthly,
): int {
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => $cadence->value,
        'latest_amount_minor' => $latestAmountMinor,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => $storedMonthly,
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'oa-'.$name,
        'cluster_counterparty_key' => $name,
        'next_expected_at' => '2026-06-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

/**
 * @param  list<RecurringSeriesDto>  $dtos
 * @return list<string>
 */
function orderedApartNames(array $dtos): array
{
    return array_map(static fn (RecurringSeriesDto $dto): string => $dto->detectedName, $dtos);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-17 12:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'ordered-apart',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// One row per cadence, with stored copies running in exactly the reverse of
// the derived order: an ORDER BY that reads the column hands back this list
// upside down, and one that misses a single WHEN drops that row to the bottom.
it('orders the list by the monthly figure its rows print, not by the copy stored beside it', function (): void {
    orderedApartSeries($this->db, $this->user->id, 'yearly-domain', SeriesCadence::Yearly, -120_000, -100);
    orderedApartSeries($this->db, $this->user->id, 'quarterly-insurance', SeriesCadence::Quarterly, -9_000, -200);
    orderedApartSeries($this->db, $this->user->id, 'weekly-coffee', SeriesCadence::Weekly, -300, -300);
    orderedApartSeries($this->db, $this->user->id, 'monthly-stream', SeriesCadence::Monthly, -1_099, -400);
    orderedApartSeries($this->db, $this->user->id, 'irregular-vet', SeriesCadence::Irregular, -5_000, -500);

    $rows = app(RecurringSeriesQuery::class)->approvedForUser($this->user);

    // -120000/12, -9000/3, -300*52/12, -1099, and the stored copy an irregular
    // series has no cadence to derive anything from.
    expect(array_map(static fn (RecurringSeriesDto $dto): int => $dto->monthlyEquivalent->toMinor(), $rows))
        ->toBe([-10_000, -3_000, -1_300, -1_099, -500]);

    // Reading the stored column answered ['irregular-vet', 'monthly-stream',
    // 'weekly-coffee', 'quarterly-insurance', 'yearly-domain'] — the smallest
    // real monthly commitment the reader has, at the top of "biggest first".
    expect(orderedApartNames($rows))
        ->toBe(['yearly-domain', 'quarterly-insurance', 'weekly-coffee', 'monthly-stream', 'irregular-vet']);
});

// The cursor is the half that costs a row rather than merely misplacing one.
// 'monthly-stream' is the row page one ends on, and its stored copy (400) sits
// BELOW what 'quarterly-insurance' is worth (900): a cursor taken from the
// column asks for rows under 400 and the third row never comes back at all.
it('pages without skipping a row whose worth sits between the cursor row and its stored copy', function (): void {
    orderedApartSeries($this->db, $this->user->id, 'weekly-gym', SeriesCadence::Weekly, -346, -346);
    orderedApartSeries($this->db, $this->user->id, 'monthly-stream', SeriesCadence::Monthly, -1_099, -400);
    orderedApartSeries($this->db, $this->user->id, 'quarterly-insurance', SeriesCadence::Quarterly, -2_700, -2_700);

    $query = app(RecurringSeriesQuery::class);

    $firstPage = $query->approvedForUser($this->user, limit: 2);
    expect(orderedApartNames($firstPage))->toBe(['weekly-gym', 'monthly-stream']);
    expect($firstPage[0]->monthlyEquivalent->toMinor())->toBe(-1_499);
    expect($firstPage[1]->monthlyEquivalent->toMinor())->toBe(-1_099);

    $secondPage = $query->approvedForUser($this->user, cursorId: $firstPage[1]->seriesId, limit: 2);

    // -2700 a quarter is -900 a month, which is under -1099 and over nothing
    // else: it belongs on page two exactly once.
    expect(orderedApartNames($secondPage))->toBe(['quarterly-insurance']);
    expect($secondPage[0]->monthlyEquivalent->toMinor())->toBe(-900);

    expect([...orderedApartNames($firstPage), ...orderedApartNames($secondPage)])
        ->toBe(['weekly-gym', 'monthly-stream', 'quarterly-insurance']);
});
