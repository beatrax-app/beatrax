<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

// An 18-row ASN import read on an iPhone: 23, 25 and 27 August each stepped the
// day panel's balance line by exactly one transaction and each said "No
// payments on this day." above it, while 1 and 5 September listed theirs in
// full. Booked rows were placed from yesterday forward only, and the
// paid/missed pass behind it reaches series occurrences alone — a plain
// imported row belonging to no series was listed by neither pass.

const PDN_TODAY = '2026-08-29';

const PDN_GROCERIES = '2026-08-23';

const PDN_SALARY = '2026-08-25';

const PDN_TAX = '2026-08-27';

const PDN_RENT = '2026-09-01';

const PDN_GROCERIES_MINOR = -4_120;

const PDN_SALARY_MINOR = 320_000;

const PDN_TAX_MINOR = -8_500;

const PDN_RENT_MINOR = -145_000;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(PDN_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'pdn',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = pdnAccount($this->db, (int) $this->user->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function pdnAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'iPhone ASN Betaalrekening',
        'slug' => 'pdn-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00PDN'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 274_792,
        'starting_balance_date' => '2026-07-01',
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function pdnRow(
    DatabaseManager $db,
    int $userId,
    int $accountId,
    string $postedAt,
    int $minor,
    string $counterparty,
): int {
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/pdn-'.$hex.'.csv',
        'sha256' => hash('sha256', 'pdn-'.$hex),
        'uploaded_at' => '2026-08-29 08:00:00',
        'status' => 'imported',
        'created_at' => '2026-08-29 08:00:00',
        'updated_at' => '2026-08-29 08:00:00',
    ]);

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'pdn-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => $minor,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => $minor,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => Str::slug($counterparty),
        'counterparty_name' => $counterparty,
        'normalization_version' => 1,
        'description' => 'pdn fixture',
        'type' => $minor < 0 ? TransactionType::Expense->value : TransactionType::Income->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-08-29 08:00:00',
        'updated_at' => '2026-08-29 08:00:00',
    ]);
}

function pdnSeries(DatabaseManager $db, int $userId, string $nextExpectedAt, string $counterparty): int
{
    return $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => $counterparty,
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => PDN_TAX_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => PDN_TAX_MINOR,
        'variance_tolerance_percent' => 0,
        'cluster_key' => 'pdn::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => Str::slug($counterparty),
        'next_expected_at' => $nextExpectedAt,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-01 00:00:00',
    ]);
}

function pdnOccurrence(DatabaseManager $db, int $userId, int $seriesId, int $transactionId, string $observedAt, int $minor): void
{
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => $minor,
        'observed_currency' => Currency::Eur->value,
        'transaction_id' => $transactionId,
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);
}

function pdnImport(DatabaseManager $db, int $userId, int $accountId): void
{
    pdnRow($db, $userId, $accountId, PDN_GROCERIES, PDN_GROCERIES_MINOR, 'Jumbo Supermarkten');
    pdnRow($db, $userId, $accountId, PDN_SALARY, PDN_SALARY_MINOR, 'Nordwind Media BV');
    pdnRow($db, $userId, $accountId, PDN_TAX, PDN_TAX_MINOR, 'Belastingdienst');
    pdnRow($db, $userId, $accountId, PDN_RENT, PDN_RENT_MINOR, 'Woonstichting Delta');
}

function pdnDayOn(User $user, string $date): CalendarDayDto
{
    $parsed = CarbonImmutable::parse($date);
    foreach (app(CalendarQuery::class)->forMonth($user, $parsed->year, $parsed->month) as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException($date.' is not a cell of its own month grid');
}

/**
 * @return list<string>
 */
function pdnEntryNamesOn(User $user, string $date): array
{
    return array_map(static fn ($entry): string => $entry->name, pdnDayOn($user, $date)->entries);
}

it('names the row that stepped a past day', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    expect(pdnEntryNamesOn($this->user, PDN_GROCERIES))->toBe(['Jumbo Supermarkten'])
        ->and(pdnEntryNamesOn($this->user, PDN_SALARY))->toBe(['Nordwind Media BV'])
        ->and(pdnEntryNamesOn($this->user, PDN_TAX))->toBe(['Belastingdienst']);
});

// The reading off the device: every past cell whose two balance figures differ
// owes the reader the movement between them.
it('leaves no past day stepping its balance over an empty entry list', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    $silentSteps = [];
    foreach (app(CalendarQuery::class)->forMonth($this->user, 2026, 8) as $day) {
        if (! $day->isPast || ! $day->showsBalance() || $day->sodBalanceMinor === null) {
            continue;
        }
        if ($day->sodBalanceMinor !== $day->eodBalanceMinor && $day->entries === []) {
            $silentSteps[] = $day->date->toDateString();
        }
    }

    expect($silentSteps)->toBe([]);
});

// The other half of the same rule: a quiet day still says it is quiet.
it('still says a past day with no rows on it has no payments', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    $quiet = pdnDayOn($this->user, '2026-08-24');

    expect($quiet->entries)->toBe([])
        ->and($quiet->sodBalanceMinor)->toBe($quiet->eodBalanceMinor);
});

it('steps the day by exactly the amount it lists', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    $day = pdnDayOn($this->user, PDN_SALARY);
    $listed = array_sum(array_map(static fn ($entry): int => $entry->amountMinor, $day->entries));

    expect($listed)->toBe(PDN_SALARY_MINOR)
        ->and($day->eodBalanceMinor - (int) $day->sodBalanceMinor)->toBe(PDN_SALARY_MINOR);
});

// A past day drills through to the row that moved the money, exactly as a
// future day already does.
it('gives a past entry the same drill-through a future one has', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    $past = pdnDayOn($this->user, PDN_GROCERIES)->entries[0];
    $future = pdnDayOn($this->user, PDN_RENT)->entries[0];

    expect($past->transactionId)->not->toBeNull()
        ->and($past->accountName)->toBe('iPhone ASN Betaalrekening')
        ->and($future->transactionId)->not->toBeNull()
        ->and($future->accountName)->toBe('iPhone ASN Betaalrekening');
});

// One payment, one line: the series expects the tax on the day the ledger has
// already booked it, and the day panel must not print it twice.
it('lists a past payment once where a series expects it too', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);
    $taxId = $this->db->connection()->table('transactions')
        ->where('posted_at', PDN_TAX)->value('id');
    $seriesId = pdnSeries($this->db, (int) $this->user->id, '2026-09-27', 'Belastingdienst');
    pdnOccurrence($this->db, (int) $this->user->id, $seriesId, (int) $taxId, PDN_TAX, PDN_TAX_MINOR);

    expect(pdnDayOn($this->user, PDN_TAX)->entries)->toHaveCount(1);
});

// A cadence that expected a payment nothing paid still reads missed: adding the
// ledger's own rows must not swallow the verdict pass.
it('keeps reporting a past expectation nothing paid', function (): void {
    pdnSeries($this->db, (int) $this->user->id, '2026-09-27', 'Belastingdienst');

    $entries = pdnDayOn($this->user, PDN_TAX)->entries;

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->isMissed)->toBeTrue();
});

// The lower bound is EXCLUSIVE, so the placer asks from the day before the
// first cell. August's strip opens on 27 July: a row on that cell is inside the
// grid the balance overlay already runs over, and one day's slip drops it.
it('reaches the past lead-in cell the grid opens on', function (): void {
    $userId = (int) $this->user->id;
    pdnRow($this->db, $userId, $this->accountId, '2026-07-27', PDN_GROCERIES_MINOR, 'Jumbo Supermarkten');

    $august = app(CalendarQuery::class)->forMonth($this->user, 2026, 8);

    expect($august[0]->date->toDateString())->toBe('2026-07-27')
        ->and(array_map(static fn ($entry): string => $entry->name, $august[0]->entries))
        ->toBe(['Jumbo Supermarkten']);
});

// One day earlier is off the strip: the compensation for the exclusive bound is
// not a wider reach, and SeriesEntryPlacer cannot draw that cell either.
it('reaches no further back than the cell before the grid opens', function (): void {
    $userId = (int) $this->user->id;
    pdnRow($this->db, $userId, $this->accountId, '2026-07-26', PDN_GROCERIES_MINOR, 'Jumbo Supermarkten');

    $names = [];
    foreach (app(CalendarQuery::class)->forMonth($this->user, 2026, 8) as $day) {
        foreach ($day->entries as $entry) {
            $names[] = $entry->name;
        }
    }

    expect($names)->toBe([]);
});

// The estimate sat on the day it was due and the ledger row on the day it was
// paid, so the cadence's day carried a paid ✓ over a balance that never moved
// while the day that did move carried nothing. One payment, one day: the one
// the money left the account on.
it('draws a payment on the day it moved, not the day it was due', function (): void {
    $userId = (int) $this->user->id;
    pdnRow($this->db, $userId, $this->accountId, PDN_TAX, PDN_TAX_MINOR, 'Belastingdienst');
    pdnSeries($this->db, $userId, '2026-09-25', 'Belastingdienst');

    expect(pdnEntryNamesOn($this->user, '2026-08-25'))->toBe([])
        ->and(pdnEntryNamesOn($this->user, PDN_TAX))->toBe(['Belastingdienst']);
});

it('keeps both the series it belongs to and the row it was paid by', function (): void {
    $userId = (int) $this->user->id;
    pdnRow($this->db, $userId, $this->accountId, PDN_TAX, PDN_TAX_MINOR, 'Belastingdienst');
    pdnSeries($this->db, $userId, '2026-09-25', 'Belastingdienst');

    $entry = pdnDayOn($this->user, PDN_TAX)->entries[0];

    expect($entry->seriesId)->not->toBeNull()
        ->and($entry->transactionId)->not->toBeNull()
        ->and($entry->amountMinor)->toBe(PDN_TAX_MINOR)
        ->and($entry->isPaid)->toBeTrue()
        ->and($entry->isMissed)->toBeFalse();
});

it('offers the reader both drill-throughs on that entry', function (): void {
    $userId = (int) $this->user->id;
    $transactionId = pdnRow($this->db, $userId, $this->accountId, PDN_TAX, PDN_TAX_MINOR, 'Belastingdienst');
    $seriesId = pdnSeries($this->db, $userId, '2026-09-25', 'Belastingdienst');

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 8, 'year' => 2026])
        ->call('selectDay', PDN_TAX)
        ->assertSee('/recurring/series/'.$seriesId)
        ->assertSee('/transactions/'.$transactionId);
});

it('does not read "No payments on this day." over a past step on the page', function (): void {
    pdnImport($this->db, (int) $this->user->id, $this->accountId);

    Livewire::actingAs($this->user)
        ->test(CalendarPage::class, ['month' => 8, 'year' => 2026])
        ->call('selectDay', PDN_GROCERIES)
        ->assertSee('Jumbo Supermarkten')
        ->assertDontSee('No payments on this day.');
});
