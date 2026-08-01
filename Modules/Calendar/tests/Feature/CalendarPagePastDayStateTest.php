<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

/*
 * CalendarPage — past-day paid/missed state (CAL-02, D-07).
 *
 * RED state (Phase 6 Plan 01): CalendarPage does not yet implement
 * past-day paid/missed matching; these tests will fail until Plan 02/03.
 *
 * Contract being tested:
 *   - A past-day entry with a matching RecurringSeriesOccurrence within
 *     the tolerance window is marked isPaid (rendered with a ✓ or paid marker).
 *   - A past-day entry with NO matching occurrence is marked isMissed
 *     (rendered with a missed marker).
 *   - The balance line for past days uses the actual (real) balance up
 *     to today and projection after today.
 */

function cppdsUser(string $suffix = 'cppds'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cppdsSeries(User $user, string $name, CarbonImmutable $nextExpectedAt): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'expense',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1500,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cppds::'.$name,
        'next_expected_at' => $nextExpectedAt,
    ]);
}

function cppdsOccurrence(DatabaseManager $db, int $userId, int $seriesId, string $observedAt): void
{
    static $cppdsOccCounter = 0;
    $cppdsOccCounter++;

    // Create an account for the FK chain (transactions → accounts)
    $hex = bin2hex(random_bytes(4));
    $accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CPPDS ASN',
        'slug' => 'cppds-'.$hex,
        'kind' => 'bank',
        'iban' => 'NL00CPPDS'.strtoupper($hex),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 0,
        'opening_balance_as_of_date' => '2026-01-01',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cppds-'.$cppdsOccCounter.'.csv',
        'sha256' => str_pad((string) ($cppdsOccCounter + 100), 64, 'b'),
        'uploaded_at' => $observedAt.' 00:00:00',
        'status' => 'imported',
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $txId = $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId,
        'account_id' => $accountId,
        'import_run_id' => $runId,
        'fingerprint' => str_pad((string) ($cppdsOccCounter + 100), 64, 'b'),
        'fingerprint_version' => 3,
        'posted_at' => $observedAt,
        'booked_at' => $observedAt.' 00:00:00',
        'value_date' => $observedAt,
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_normalized' => 'cppds-counterparty',
        'counterparty_name' => 'CPPDS Test',
        'normalization_version' => 1,
        'description' => 'cppds occurrence fixture',
        'type' => 'expense',
        'source_format' => 'asn-csv',
        'source_row_index' => $cppdsOccCounter + 100,
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);

    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => -1500,
        'observed_currency' => 'EUR',
        'transaction_id' => $txId,
        'created_at' => $observedAt.' 00:00:00',
        'updated_at' => $observedAt.' 00:00:00',
    ]);
}

beforeEach(function (): void {
    // Set "today" to June 12 so June 5 is a past day with a paid occurrence
    // and June 8 is a past day with a missed (no occurrence) entry
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('marks a past-day entry as paid when a matching occurrence exists within the tolerance window', function (): void {
    $db = app(DatabaseManager::class);
    $user = cppdsUser('cppds-paid');
    $series = cppdsSeries($user, 'Paid Subscription', CarbonImmutable::parse('2026-06-05'));

    // Occurrence observed on June 5 — within the tolerance window of the expected date
    cppdsOccurrence($db, $user->id, $series->id, '2026-06-05');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('Paid Subscription')
        // The paid state indicator should be visible (✓ or data-testid="paid")
        ->assertSee('paid', false);
});

it('renders past-day balance cells from actual transactions, not the computing em-dash (WR-09, D-07)', function (): void {
    $db = app(DatabaseManager::class);
    $user = cppdsUser('cppds-actuals');
    $series = cppdsSeries($user, 'Actuals Series', CarbonImmutable::parse('2026-06-05'));

    // The occurrence fixture writes a real −€15,00 transaction on June 5;
    // no forecast_run exists, so future days stay "—" while past days must
    // show the real cumulative balance (−€15 from June 5 onward).
    cppdsOccurrence($db, $user->id, $series->id, '2026-06-05');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('−€15');
});

it('marks a past-day entry as missed when no occurrence exists for an expected date that has passed', function (): void {
    $user = cppdsUser('cppds-missed');
    // Expected June 8, now it is June 12 — the entry should be flagged as missed
    cppdsSeries($user, 'Missed Payment', CarbonImmutable::parse('2026-06-08'));

    // No occurrence seeded — payment expected but not observed

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026])
        ->assertSee('Missed Payment')
        ->assertSee('missed', false);
});
