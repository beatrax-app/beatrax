<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Calendar\Internal\Dto\CalendarDayDto;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// The August 2026 grid runs Mon 27 Jul → Sun 6 Sep, and the September 2026 grid
// runs Mon 31 Aug → Sun 4 Oct. Every date below is chosen off those two strips.
const GEDGE_TODAY = '2026-08-23';

const GEDGE_NEXT_MONTH_CELL = '2026-09-03';

const GEDGE_SEPTEMBER_GRID_FIRST_CELL = '2026-08-31';

const GEDGE_SEPTEMBER_GRID_LAST_CELL = '2026-10-04';

const GEDGE_OUTSIDE_AUGUST_GRID = '2026-09-07';

const GEDGE_RENT_MINOR = -145_000;

const GEDGE_NAME = 'Woonstichting Delta';

beforeEach(function (): void {
    CarbonImmutable::setTestNow(GEDGE_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'get-edge',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function gedgeAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'GET Bank',
        'slug' => 'get-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00GET'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function gedgeBookedRow(DatabaseManager $db, int $userId, int $accountId, string $postedAt): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/get-'.$hex.'.csv',
        'sha256' => hash('sha256', 'get-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'get-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => GEDGE_RENT_MINOR,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => GEDGE_RENT_MINOR,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'woonstichting-delta',
        'counterparty_name' => GEDGE_NAME,
        'normalization_version' => 1,
        'description' => 'get fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function gedgeSeries(DatabaseManager $db, int $userId, string $nextExpectedAt): void
{
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => GEDGE_NAME,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => GEDGE_RENT_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => GEDGE_RENT_MINOR,
        'variance_tolerance_percent' => 0,
        'cluster_key' => 'get::'.bin2hex(random_bytes(4)),
        'next_expected_at' => $nextExpectedAt,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function gedgeDayOn(User $user, int $year, int $month, string $date): CalendarDayDto
{
    foreach (app(CalendarQuery::class)->forMonth($user, $year, $month) as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException($date.' is not a cell of the '.$year.'-'.$month.' grid');
}

/**
 * @return list<string>
 */
function gedgeEntryNamesOn(User $user, int $year, int $month, string $date): array
{
    return array_map(
        static fn ($entry): string => $entry->name,
        gedgeDayOn($user, $year, $month, $date)->entries,
    );
}

// The grid draws Monday-before-the-1st through Sunday-after-the-last, and every
// one of those cells already carries a balance corner off the forecast. The
// entry map stopped at the month, so 3 September drew a €1,450 step in the
// August grid with nothing listed against it.
it('lists the payment on an adjacent-month cell that already draws its balance', function (): void {
    $accountId = gedgeAccount($this->db, $this->user->id);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_NEXT_MONTH_CELL);

    expect(gedgeEntryNamesOn($this->user, 2026, 8, GEDGE_NEXT_MONTH_CELL))->toBe([GEDGE_NAME]);
});

// The same cell, one month on. The two views of one day must not disagree, and
// the balance corner is what was already right when the entry list was not.
it('draws that day identically whichever month is on screen', function (): void {
    $accountId = gedgeAccount($this->db, $this->user->id);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_NEXT_MONTH_CELL);

    $inAugustGrid = gedgeDayOn($this->user, 2026, 8, GEDGE_NEXT_MONTH_CELL);
    $inSeptember = gedgeDayOn($this->user, 2026, 9, GEDGE_NEXT_MONTH_CELL);

    expect(array_map(static fn ($e): string => $e->name, $inAugustGrid->entries))->toBe([GEDGE_NAME])
        ->and(array_map(static fn ($e): string => $e->name, $inSeptember->entries))->toBe([GEDGE_NAME])
        ->and($inAugustGrid->eodBalanceMinor)->toBe($inSeptember->eodBalanceMinor)
        ->and($inAugustGrid->isComputing)->toBe($inSeptember->isComputing);
});

// The cell carries tabindex="0" and wire:click="selectDay(...)". selectDay()
// rejected anything outside the display month, so the click did nothing at all.
it('opens an adjacent-month cell the grid drew instead of ignoring the click', function (): void {
    $accountId = gedgeAccount($this->db, $this->user->id);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_NEXT_MONTH_CELL);

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 8, 'year' => 2026])
        ->call('selectDay', GEDGE_NEXT_MONTH_CELL)
        ->assertSet('selectedDay', GEDGE_NEXT_MONTH_CELL);
});

// Widening the accepted range to the grid must not widen it further: 7 September
// is off the August strip and has no cell to open.
it('still refuses a day the grid never drew', function (): void {
    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 8, 'year' => 2026])
        ->call('selectDay', GEDGE_OUTSIDE_AUGUST_GRID)
        ->assertSet('selectedDay', null);
});

// A screen reader heard "3 September 2026: 0 entries" on a cell showing a rent.
it('announces the payment an adjacent-month cell is showing', function (): void {
    $accountId = gedgeAccount($this->db, $this->user->id);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_NEXT_MONTH_CELL);

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 8, 'year' => 2026])
        ->assertSee('3 September 2026: 1 entry', false)
        ->assertDontSee('3 September 2026: 0 entries', false);
});

// BookedEntryPlacer asked from monthStart and SeriesEntryPlacer from monthStart:
// both stopped at the month while the grid did not. They have to reach the same
// two edge cells, or one placer's payment vanishes where the other's is drawn.
it('reaches both grid edges with a booked row', function (): void {
    $accountId = gedgeAccount($this->db, $this->user->id);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_SEPTEMBER_GRID_FIRST_CELL);
    gedgeBookedRow($this->db, $this->user->id, $accountId, GEDGE_SEPTEMBER_GRID_LAST_CELL);

    expect(gedgeEntryNamesOn($this->user, 2026, 9, GEDGE_SEPTEMBER_GRID_FIRST_CELL))->toBe([GEDGE_NAME])
        ->and(gedgeEntryNamesOn($this->user, 2026, 9, GEDGE_SEPTEMBER_GRID_LAST_CELL))->toBe([GEDGE_NAME]);
});

it('reaches both grid edges with a series entry', function (): void {
    gedgeSeries($this->db, $this->user->id, GEDGE_SEPTEMBER_GRID_FIRST_CELL);
    gedgeSeries($this->db, $this->user->id, GEDGE_SEPTEMBER_GRID_LAST_CELL);

    expect(gedgeEntryNamesOn($this->user, 2026, 9, GEDGE_SEPTEMBER_GRID_FIRST_CELL))->toBe([GEDGE_NAME])
        ->and(gedgeEntryNamesOn($this->user, 2026, 9, GEDGE_SEPTEMBER_GRID_LAST_CELL))->toBe([GEDGE_NAME]);
});
