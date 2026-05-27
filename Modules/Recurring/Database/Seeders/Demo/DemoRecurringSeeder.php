<?php

declare(strict_types=1);

namespace Modules\Recurring\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Models\RecurringSeries;

/**
 * Pre-registers four `recurring_series` rows for the primary demo
 * user so the `/recurring` review surface, the dashboard's "fixed
 * payments" panel, and the drift-alert quiet-period engine all have
 * data to render without waiting for the daily detector sweep to
 * run. Each row models a subscription the demo dataset emits monthly:
 *
 *   - Spotify Premium  — €10.99 monthly streaming
 *   - Netflix          — €14.99 monthly streaming
 *   - Sport City       — €25.00 monthly gym membership
 *   - KPN              — €45.00 monthly internet + phone bill
 *
 * Idempotency: the table's `(user_id, direction, cluster_key,
 * latest_currency)` UNIQUE is keyed on the same tuple this seeder
 * sets, so `updateOrCreate` keyed on `(user_id, direction,
 * cluster_key, latest_currency)` reuses the existing row on a second
 * seed run.
 *
 * All four series land in state=`approved` so the review surface
 * treats them as confirmed. State transitions normally route through
 * the RecurringSeriesStateMachine; the demo data is "what an
 * established user already has on file", not "what the detector just
 * surfaced for review".
 */
final class DemoRecurringSeeder
{
    /**
     * Per-series static data — kept inline so a future "add another
     * demo subscription" change is a one-line edit. `clusterKey` is
     * the detector's deterministic identity for the series (the
     * tuple of cadence + counterparty); the production detector
     * builds it from a few raw transaction columns, the demo seeder
     * hand-picks values that won't collide with any future detector
     * output.
     *
     * Note that `recurring_series` requires Currency values to be
     * present in the `currencies` table — the demo command seeds
     * `EUR` via CurrenciesSeeder before reaching this step, so the
     * FK target is guaranteed.
     *
     * @var list<array{detectedName: string, displayName: string, latestAmountMinor: int, cadence: string, dayOfMonth: int, clusterKey: string}>
     */
    private const SERIES = [
        [
            'detectedName' => 'Spotify AB',
            'displayName' => 'Spotify Premium',
            'latestAmountMinor' => 1099,
            'cadence' => 'monthly',
            'dayOfMonth' => 11,
            'clusterKey' => 'demo:spotify:monthly:1099',
        ],
        [
            'detectedName' => 'Netflix International BV',
            'displayName' => 'Netflix',
            'latestAmountMinor' => 1499,
            'cadence' => 'monthly',
            'dayOfMonth' => 15,
            'clusterKey' => 'demo:netflix:monthly:1499',
        ],
        [
            'detectedName' => 'Sport City Nederland BV',
            'displayName' => 'Sport City',
            'latestAmountMinor' => 2500,
            'cadence' => 'monthly',
            'dayOfMonth' => 1,
            'clusterKey' => 'demo:sport-city:monthly:2500',
        ],
        [
            'detectedName' => 'KPN BV',
            'displayName' => 'KPN',
            'latestAmountMinor' => 4500,
            'cadence' => 'monthly',
            'dayOfMonth' => 3,
            'clusterKey' => 'demo:kpn:monthly:4500',
        ],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::SERIES as $row) {
                $this->upsertSeries($primary, $row);
            }
        }

        return RecurringSeries::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{detectedName: string, displayName: string, latestAmountMinor: int, cadence: string, dayOfMonth: int, clusterKey: string}  $row
     */
    private function upsertSeries(User $user, array $row): void
    {
        // Compute the next-expected date by walking the cadence
        // forward from today; the demo data shows the user as having
        // already paid this month's instalment, so the next-expected
        // sits at the same day-of-month next month.
        $today = CarbonImmutable::today();
        $nextMonth = $today->addMonthNoOverflow()->startOfMonth();
        $nextExpected = $nextMonth->setDay(min($row['dayOfMonth'], $nextMonth->daysInMonth));

        RecurringSeries::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'direction' => 'expense',
                'cluster_key' => $row['clusterKey'],
                'latest_currency' => 'EUR',
            ],
            [
                'detected_name' => $row['detectedName'],
                'display_name_override' => $row['displayName'],
                'state' => 'approved',
                'cadence' => $row['cadence'],
                'latest_amount_minor' => $row['latestAmountMinor'],
                'latest_fx_rate_used' => null,
                'monthly_equivalent_minor' => $row['latestAmountMinor'],
                'variance_tolerance_percent' => 25,
                'latest_funding_chain_link_id' => null,
                'snoozed_until' => null,
                'next_expected_at' => $nextExpected,
                'next_expected_confidence_low' => false,
                'cluster_counterparty_key' => $row['detectedName'],
            ],
        );
    }
}
