<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Calendar\Internal\Services\CalendarQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

uses(RefreshDatabase::class);

// Booked rows were placed strictly AHEAD of today and the paid/missed pass runs
// strictly BEHIND it, so today itself got neither: its balance line stepped
// down EUR1,450.00 over a day panel reading "No payments on this day." — the
// exact reading BookedEntryPlacer exists to prevent, left open on the one day
// the reader looks at first.

const TCS_TODAY = '2026-08-23';

const TCS_RENT_MINOR = -145_000;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(TCS_TODAY.' 09:00:00');
    $this->db = app(DatabaseManager::class);
    $this->user = User::query()->create([
        'username' => 'tcs',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
        'default_currency_view' => 'eur_only',
    ]);
    $this->accountId = tcsAccount($this->db, (int) $this->user->id);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

function tcsAccount(DatabaseManager $db, int $userId): int
{
    $hex = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'TCS Bank',
        'slug' => 'tcs-'.$hex,
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL00TCS'.strtoupper($hex),
        'default_currency' => Currency::Eur->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tcsRent(DatabaseManager $db, int $userId, int $accountId, string $postedAt): void
{
    static $row = 0;
    $row++;
    $hex = bin2hex(random_bytes(6));

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tcs-'.$hex.'.csv',
        'sha256' => hash('sha256', 'tcs-'.$hex),
        'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'imported',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('transactions')->insert([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'tcs-fp-'.$hex),
        'fingerprint_version' => 3,
        'posted_at' => $postedAt,
        'booked_at' => $postedAt.' 12:00:00',
        'value_date' => $postedAt,
        'amount_minor' => TCS_RENT_MINOR,
        'currency' => Currency::Eur->value,
        'settled_amount_minor' => TCS_RENT_MINOR,
        'settled_currency' => Currency::Eur->value,
        'counterparty_normalized' => 'woonstichting-delta',
        'counterparty_name' => 'Woonstichting Delta',
        'normalization_version' => 1,
        'description' => 'tcs fixture',
        'type' => TransactionType::Expense->value,
        'source_format' => 'asn-csv',
        'source_row_index' => $row,
        'status' => ClearedStatus::Cleared->value,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tcsSeries(DatabaseManager $db, int $userId, string $nextExpectedAt): void
{
    $db->connection()->table('recurring_series')->insert([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Woonstichting Delta',
        'state' => RecurringSeriesState::Approved->value,
        'cadence' => SeriesCadence::Monthly->value,
        'latest_amount_minor' => TCS_RENT_MINOR,
        'latest_currency' => Currency::Eur->value,
        'monthly_equivalent_minor' => TCS_RENT_MINOR,
        'variance_tolerance_percent' => 0,
        'cluster_key' => 'tcs::'.bin2hex(random_bytes(4)),
        'cluster_counterparty_key' => 'woonstichting-delta',
        'next_expected_at' => $nextExpectedAt,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

function tcsDay(User $user, string $date): object
{
    $parsed = CarbonImmutable::parse($date);
    foreach (app(CalendarQuery::class)->forMonth($user, $parsed->year, $parsed->month) as $day) {
        if ($day->date->toDateString() === $date) {
            return $day;
        }
    }

    throw new RuntimeException('Day not rendered: '.$date);
}

it('lists the row that stepped today balance', function (): void {
    tcsRent($this->db, (int) $this->user->id, $this->accountId, TCS_TODAY);

    $today = tcsDay($this->user, TCS_TODAY);

    expect($today->eodBalanceMinor - $today->sodBalanceMinor)->toBe(TCS_RENT_MINOR);

    expect(array_map(static fn ($entry): string => $entry->name, $today->entries))
        ->toBe(['Woonstichting Delta']);
});

it('lists it once when a series expects it today too', function (): void {
    tcsSeries($this->db, (int) $this->user->id, TCS_TODAY);
    tcsRent($this->db, (int) $this->user->id, $this->accountId, TCS_TODAY);

    expect(array_map(static fn ($entry): string => $entry->name, tcsDay($this->user, TCS_TODAY)->entries))
        ->toBe(['Woonstichting Delta']);
});
