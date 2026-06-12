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
        'direction' => 'outbound',
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
    $db->connection()->table('recurring_series_occurrences')->insert([
        'user_id' => $userId,
        'recurring_series_id' => $seriesId,
        'observed_at' => $observedAt,
        'observed_amount_minor' => -1500,
        'observed_currency' => 'EUR',
        'transaction_id' => null,
        'created_at' => $observedAt,
        'updated_at' => $observedAt,
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
