<?php

declare(strict_types=1);

namespace Modules\Recurring\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesTransition;
use Modules\Recurring\Public\Enums\RecurringSeriesState;

// updateOrCreate() reuses the existing row on a second seed run.
// Transitions bypass RecurringSeriesStateMachine since the demo data
// models a pre-established user's file, not a fresh detector surface —
// the boundary arch test only blocks a direct state update.
final class DemoRecurringSeeder
{
    // clusterKey values are hand-picked to avoid colliding with any future
    // real detector output. recurring_series requires Currency values
    // present in the currencies table — the demo command seeds EUR via
    // CurrenciesSeeder before reaching this step, so the FK target holds.
    /** @var list<array{detectedName: string, displayName: string, latestAmountMinor: int, cadence: string, dayOfMonth: int, clusterKey: string, state: string}> */
    private const SERIES = [
        [
            'detectedName' => 'Spotify AB',
            'displayName' => 'Spotify Premium',
            'latestAmountMinor' => 1099,
            'cadence' => 'monthly',
            'dayOfMonth' => 11,
            'clusterKey' => 'demo:spotify:monthly:1099',
            'state' => RecurringSeriesState::Approved->value,
        ],
        [
            'detectedName' => 'Netflix International BV',
            'displayName' => 'Netflix',
            'latestAmountMinor' => 1499,
            'cadence' => 'monthly',
            'dayOfMonth' => 15,
            'clusterKey' => 'demo:netflix:monthly:1499',
            'state' => RecurringSeriesState::Approved->value,
        ],
        [
            'detectedName' => 'Sport City Nederland BV',
            'displayName' => 'Sport City',
            'latestAmountMinor' => 2500,
            'cadence' => 'monthly',
            'dayOfMonth' => 1,
            'clusterKey' => 'demo:sport-city:monthly:2500',
            'state' => RecurringSeriesState::Approved->value,
        ],
        [
            'detectedName' => 'KPN BV',
            'displayName' => 'KPN',
            'latestAmountMinor' => 4500,
            'cadence' => 'monthly',
            'dayOfMonth' => 3,
            'clusterKey' => 'demo:kpn:monthly:4500',
            'state' => RecurringSeriesState::Approved->value,
        ],
        [
            'detectedName' => 'NRC Media',
            'displayName' => 'NRC magazine (paused)',
            'latestAmountMinor' => 750,
            'cadence' => 'monthly',
            'dayOfMonth' => 7,
            'clusterKey' => 'demo:nrc:monthly:750',
            'state' => RecurringSeriesState::Snoozed->value,
        ],
        [
            'detectedName' => 'NordVPN s.r.o.',
            'displayName' => 'NordVPN (cancelled)',
            'latestAmountMinor' => 499,
            'cadence' => 'monthly',
            'dayOfMonth' => 13,
            'clusterKey' => 'demo:nordvpn:monthly:499',
            'state' => RecurringSeriesState::Rejected->value,
        ],
    ];

    // Each row targets a series by its cluster_key so the seeder stays
    // decoupled from auto-incremented primary keys.
    /** @var list<array{clusterKey: string, fromState: string, toState: string, reason: string, actor: string, ageDays: int, notes: ?string}> */
    private const TRANSITIONS = [
        [
            'clusterKey' => 'demo:nrc:monthly:750',
            'fromState' => RecurringSeriesState::Approved->value,
            'toState' => RecurringSeriesState::Snoozed->value,
            'reason' => 'missed_occurrence',
            'actor' => 'detector',
            'ageDays' => 12,
            'notes' => 'NRC magazine charge missed two billing periods in a row',
        ],
        [
            'clusterKey' => 'demo:netflix:monthly:1499',
            'fromState' => RecurringSeriesState::Approved->value,
            'toState' => RecurringSeriesState::CadenceChanged->value,
            'reason' => 'amount_change',
            'actor' => 'detector',
            'ageDays' => 6,
            'notes' => 'Netflix price changed from €13.99 to €14.99',
        ],
        [
            'clusterKey' => 'demo:nordvpn:monthly:499',
            'fromState' => RecurringSeriesState::CadenceChanged->value,
            'toState' => RecurringSeriesState::Rejected->value,
            'reason' => 'user_action',
            'actor' => 'user',
            'ageDays' => 2,
            'notes' => 'User cancelled the NordVPN series after a cadence shift',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

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
            $this->upsertTransitionsForUser($primary);
        }

        return RecurringSeries::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    // The append-only schema has no DB-level UNIQUE on transitions, so
    // this keys on (recurring_series_id, transition_reason) in application
    // code — sufficient since the demo set carries one transition per
    // (series, reason) tuple by design.
    private function upsertTransitionsForUser(User $user): void
    {
        $today = CarbonImmutable::today();

        foreach (self::TRANSITIONS as $row) {
            $series = RecurringSeries::query()
                ->where('user_id', $user->id)
                ->where('cluster_key', $row['clusterKey'])
                ->first();

            if ($series === null) {
                continue;
            }

            $existing = $this->db->connection()
                ->table('recurring_series_transitions')
                ->where('recurring_series_id', $series->id)
                ->where('transition_reason', $row['reason'])
                ->exists();

            if ($existing) {
                continue;
            }

            RecurringSeriesTransition::query()->create([
                'user_id' => $user->id,
                'recurring_series_id' => $series->id,
                'from_state' => $row['fromState'],
                'to_state' => $row['toState'],
                'transition_reason' => $row['reason'],
                'actor' => $row['actor'],
                'transitioned_at' => $today->subDays($row['ageDays'])->setTime(12, 0),
                'notes' => $row['notes'],
            ]);
        }
    }

    /**
     * @param  array{detectedName: string, displayName: string, latestAmountMinor: int, cadence: string, dayOfMonth: int, clusterKey: string, state: string}  $row
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

        // Snoozed rows carry an explicit `snoozed_until` so the resume
        // logic on /recurring shows the wake-up date. Rejected rows
        // never re-fire so the timestamp stays null.
        $snoozedUntil = $row['state'] === RecurringSeriesState::Snoozed->value
            ? $today->addDays(30)->setTime(0, 0)
            : null;

        RecurringSeries::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'direction' => Direction::Expense->value,
                'cluster_key' => $row['clusterKey'],
                'latest_currency' => 'EUR',
            ],
            [
                'detected_name' => $row['detectedName'],
                'display_name_override' => $row['displayName'],
                'state' => $row['state'],
                'cadence' => $row['cadence'],
                'latest_amount_minor' => $row['latestAmountMinor'],
                'latest_fx_rate_used' => null,
                'monthly_equivalent_minor' => $row['latestAmountMinor'],
                'variance_tolerance_percent' => 25,
                'latest_funding_chain_link_id' => null,
                'snoozed_until' => $snoozedUntil,
                'next_expected_at' => $nextExpected,
                'next_expected_confidence_low' => false,
                'cluster_counterparty_key' => $row['detectedName'],
            ],
        );
    }
}
