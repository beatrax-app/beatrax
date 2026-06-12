<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Calendar\Internal\Http\Livewire\CalendarPage;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

/*
 * CalendarPage — day-panel drill-through links (CAL-03).
 *
 * RED state (Phase 6 Plan 01): CalendarPage does not yet render the day
 * panel or drill-through links; these tests will fail until Plan 02/03.
 *
 * Contract being tested:
 *   - Each day-panel entry row exposes a link to /recurring/series/{seriesId}.
 *   - When counterpartyIdForSeries resolves a counterparty, the entry also
 *     exposes a link to /counterparties/{slug}.
 *   - Cross-user isolation: foreign seriesIds in the URL cannot expose
 *     another user's series data (the page guards via user-scoped queries).
 */

function cdtUser(string $suffix = 'cdt'): User
{
    return User::query()->create([
        'username' => $suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function cdtSeries(User $user, string $name): RecurringSeries
{
    return RecurringSeries::query()->create([
        'user_id' => $user->id,
        'direction' => 'outbound',
        'detected_name' => $name,
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1200,
        'latest_currency' => 'EUR',
        'variance_tolerance_percent' => 25,
        'cluster_key' => 'cdt::'.$name,
        'next_expected_at' => CarbonImmutable::parse('2026-06-15'),
    ]);
}

function cdtCounterparty(DatabaseManager $db, int $userId, string $slug, string $displayName): int
{
    return $db->connection()->table('counterparties')->insertGetId([
        'user_id' => $userId,
        'slug' => $slug,
        'display_name' => $displayName,
        'type' => 'merchant',
        'created_at' => '2026-06-01 00:00:00',
        'updated_at' => '2026-06-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-06-12 00:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
});

it('renders a link to /recurring/series/{seriesId} for each day-panel entry', function (): void {
    $user = cdtUser('cdt-series-link');
    $series = cdtSeries($user, 'Drill Series');

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026, 'selectedDay' => '2026-06-15'])
        ->assertSee('/recurring/series/'.$series->id, false);
});

it('renders a link to /counterparties/{slug} when the series has a resolved counterparty', function (): void {
    $db = app(DatabaseManager::class);
    $user = cdtUser('cdt-counterparty-link');
    $series = cdtSeries($user, 'Counterparty Series');
    $cpId = cdtCounterparty($db, $user->id, 'spotify-drill', 'Spotify Drill');

    // Wire the counterparty to the series via cluster_counterparty_key
    // (RecurringSeriesQuery::counterpartyIdForSeries uses this to look up the counterparty)
    $db->connection()->table('recurring_series')
        ->where('id', $series->id)
        ->update(['cluster_counterparty_key' => 'spotify-drill']);

    Livewire::actingAs($user)
        ->test(CalendarPage::class, ['month' => 6, 'year' => 2026, 'selectedDay' => '2026-06-15'])
        ->assertSee('/counterparties/spotify-drill', false);
});
