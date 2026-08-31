<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Calendar\Internal\Dto\CalendarEntryDto;
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

const ABR_TODAY = '2026-08-23';

const ABR_RENT_DATE = '2026-09-01';

const ABR_RENT_MINOR = -145_000;

const ABR_DAYS_PER_WEEK = 7;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(ABR_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'abr',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function abrAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'ABR Bank',
        'slug' => 'abr-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00ABR'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function abrRent(DatabaseManager $db, int $userId, int $accountId, string $postedAt): int
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/abr-'.$hex.'.csv',
        'sha256' => hash('sha256', 'abr-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'abr-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => ABR_RENT_MINOR,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => ABR_RENT_MINOR,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'woonstichting-delta',
        'counterparty_name' => 'Woonstichting Delta',
        'normalization_version' => 1,
        'description' => 'abr fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function abrSeries(
    DatabaseManager $db,
    int $userId,
    string $nextExpectedAt,
    SeriesCadence $cadence = SeriesCadence::Monthly,
): int {
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Woonstichting Delta',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => $cadence->value,
        'latest_amount_minor' => ABR_RENT_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => ABR_RENT_MINOR,
        'variance_tolerance_percent' => 0,
        'cluster_key' => 'abr::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'woonstichting-delta',
        'next_expected_at' => $nextExpectedAt,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

/**
 * @return list<CalendarEntryDto>
 */
function abrEntriesOn(User $user, string $date): array
{
    $parsed = CarbonImmutable::parse($date);
    foreach (app(CalendarQuery::class)->forMonth($user, $parsed->year, $parsed->month) as $day) {
        if ($day->date->toDateString() === $date) {
            return $day->entries;
        }
    }

    return [];
}

/**
 * @return list<string>
 */
function abrEntryNamesOn(User $user, string $date): array
{
    return array_map(static fn (CalendarEntryDto $entry): string => $entry->name, abrEntriesOn($user, $date));
}

// A reader whose ledger holds a dated rent was told to go connect an account:
// the card asked an existence question about approved series and nothing else,
// so a full ledger with a booked payment on screen still read as empty.
it('stops saying there are no upcoming payments once a dated row exists', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 9, 'year' => 2026])
        ->assertDontSee('No upcoming payments');
});

// The empty state is still the right answer for a reader with nothing ahead.
it('still says so for a reader with neither a series nor a dated row', function (): void {
    abrAccount($this->db, $this->user->id);

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 9, 'year' => 2026])
        ->assertSee('No upcoming payments');
});

// A day whose balance line steps down has to say what stepped it. Placing the
// booked row nowhere left the panel reading "No payments on this day." over a
// EUR1,450.00 drop.
it('lists the booked row on the day it is dated', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    expect(abrEntryNamesOn($this->user, ABR_RENT_DATE))->toBe(['Woonstichting Delta']);
});

// One rent, listed once: the series expects it on the same day the ledger has
// already booked it.
it('lists one entry where a series and a booked row mean the same payment', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrSeries($this->db, $this->user->id, ABR_RENT_DATE);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    expect(abrEntryNamesOn($this->user, ABR_RENT_DATE))->toBe(['Woonstichting Delta']);
});

// Behind today the ledger row IS the past-day balance step, and the paid/missed
// pass reaches series occurrences only — so a plain imported row belonging to no
// series was listed by neither pass, under a balance line it had just moved.
it('lists a row behind today, which no verdict pass reaches', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrRent($this->db, $this->user->id, $accountId, '2026-08-03');

    expect(abrEntryNamesOn($this->user, '2026-08-03'))->toBe(['Woonstichting Delta']);
});

// It is the payment, not a prediction of one, so it carries no amber "!".
it('never reads a booked row behind today as missed', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrRent($this->db, $this->user->id, $accountId, '2026-08-03');

    $entry = abrEntriesOn($this->user, '2026-08-03')[0];

    expect($entry->isMissed)->toBeFalse()
        ->and($entry->isPaid)->toBeTrue()
        ->and($entry->transactionId)->not->toBeNull();
});

// Not selected for entries means not shown, for a booked row exactly as for a
// series occurrence.
it('honours the accounts the reader chose to see entries for', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    $days = app(CalendarQuery::class)->forMonth($this->user, 2026, 9, [], null);

    $entries = [];
    foreach ($days as $day) {
        foreach ($day->entries as $entry) {
            $entries[] = $entry->name;
        }
    }

    expect($entries)->toBe([]);
});

// A weekly series' next occurrence is exactly MatchWindow::DAYS from the one
// the ledger has already booked, so the booked row retired both and the reader
// lost a week's payment off the calendar.
it('keeps the week after the one a booked row supersedes', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrSeries($this->db, $this->user->id, ABR_RENT_DATE, SeriesCadence::Weekly);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    $nextWeek = CarbonImmutable::parse(ABR_RENT_DATE)->addDays(ABR_DAYS_PER_WEEK)->toDateString();

    expect(abrEntryNamesOn($this->user, $nextWeek))->toBe(['Woonstichting Delta']);
});

it('still lists the booked week once', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrSeries($this->db, $this->user->id, ABR_RENT_DATE, SeriesCadence::Weekly);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    expect(abrEntryNamesOn($this->user, ABR_RENT_DATE))->toBe(['Woonstichting Delta']);
});

// The monthly claim, measured: 30 days between occurrences never reached the
// next one, so a monthly series was never the case that broke.
it('leaves a monthly series unaffected', function (): void {
    $accountId = abrAccount($this->db, $this->user->id);
    abrSeries($this->db, $this->user->id, ABR_RENT_DATE);
    abrRent($this->db, $this->user->id, $accountId, ABR_RENT_DATE);

    $nextMonth = CarbonImmutable::parse(ABR_RENT_DATE)->addMonthNoOverflow()->toDateString();

    expect(abrEntryNamesOn($this->user, ABR_RENT_DATE))->toBe(['Woonstichting Delta'])
        ->and(abrEntryNamesOn($this->user, $nextMonth))->toBe(['Woonstichting Delta']);
});
