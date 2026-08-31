<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\DriftEvaluator;

uses(RefreshDatabase::class);

// A drift alert is a claim that a recurring charge changed price. The shipped
// demo ledger charged Spotify, Netflix, Sport City and KPN the same amount
// every month, so the prior price on all four alerts was the one figure no
// transaction corroborated: open the list and every charge is identical.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->artisan('demo:seed')->assertSuccessful();
    $this->demoUser = User::query()->where('username', 'demo-1')->firstOrFail();
});

/**
 * @return list<stdClass>
 */
function dlsAlerts(DatabaseManager $db, User $user): array
{
    return array_values($db->connection()->table('drift_alerts')
        ->where('user_id', $user->id)
        ->orderBy('id')
        ->get()
        ->all());
}

/**
 * @return list<stdClass>
 */
function dlsOccurrences(DatabaseManager $db, User $user, int $seriesId): array
{
    return array_values($db->connection()->table('recurring_series_occurrences')
        ->where('user_id', $user->id)
        ->where('recurring_series_id', $seriesId)
        ->orderBy('observed_at')
        ->orderBy('id')
        ->get()
        ->all());
}

it('backs every demo drift alert with two charges the reader can find in the ledger', function (): void {
    /** @var User $user */
    $user = $this->demoUser;
    /** @var DatabaseManager $db */
    $db = $this->db;

    $alerts = dlsAlerts($db, $user);
    expect($alerts)->not->toBeEmpty();

    foreach ($alerts as $alert) {
        $occurrences = dlsOccurrences($db, $user, (int) $alert->recurring_series_id);
        $index = null;
        foreach ($occurrences as $position => $occurrence) {
            if ((int) $occurrence->id === (int) $alert->latest_occurrence_id) {
                $index = $position;
            }
        }

        expect($index)->not->toBeNull('alert '.$alert->id.' names an occurrence outside its own series');
        expect($index)->toBeGreaterThan(0, 'alert '.$alert->id.' names the first occurrence, which has no prior to move from');

        $latest = $occurrences[(int) $index];
        $prior = $occurrences[(int) $index - 1];

        expect((int) $latest->observed_amount_minor)->toBe((int) $alert->latest_amount_minor)
            ->and((int) $prior->observed_amount_minor)->toBe((int) $alert->baseline_amount_minor)
            ->and((int) $prior->observed_amount_minor)->not->toBe((int) $latest->observed_amount_minor);

        // The occurrences are bookkeeping; the bank lines behind them are what
        // the reader opens the transaction list to check.
        foreach ([$latest, $prior] as $occurrence) {
            $charge = $db->connection()->table('transactions')
                ->where('id', $occurrence->transaction_id)
                ->first(['amount_minor', 'currency']);

            expect($charge)->not->toBeNull()
                ->and((int) $charge->amount_minor)->toBe((int) $occurrence->observed_amount_minor)
                ->and($charge->currency)->toBe($alert->currency);
        }
    }
});

// The seeder asserting a figure and the detector deriving it are different
// claims. This runs the shipped evaluator over the seeded occurrences, rewound
// to the moment each step landed, and demands the same row back.
it('reproduces every demo drift alert by running the shipped evaluator over the seeded ledger', function (): void {
    /** @var User $user */
    $user = $this->demoUser;
    /** @var DatabaseManager $db */
    $db = $this->db;

    $expected = [];
    foreach (dlsAlerts($db, $user) as $alert) {
        $expected[(int) $alert->recurring_series_id] = [
            'baseline_amount_minor' => (int) $alert->baseline_amount_minor,
            'latest_amount_minor' => (int) $alert->latest_amount_minor,
            'delta_minor' => (int) $alert->delta_minor,
            'annualized_impact_minor' => (int) $alert->annualized_impact_minor,
            'threshold_percent_used' => (int) $alert->threshold_percent_used,
            'threshold_source' => $alert->threshold_source,
            'latest_occurrence_id' => (int) $alert->latest_occurrence_id,
            'currency' => $alert->currency,
        ];
    }

    expect($expected)->not->toBeEmpty();

    $db->connection()->table('drift_alert_transitions')->where('user_id', $user->id)->delete();
    $db->connection()->table('drift_alerts')->where('user_id', $user->id)->delete();

    $evaluator = $this->app->make(DriftEvaluator::class);

    $produced = [];
    foreach ($expected as $seriesId => $row) {
        // The charges after the step had not been booked yet when the detector
        // would have run, so they are not part of what it saw. "After" is the
        // (observed_at, id) order the evaluator reads in — the occurrence id is
        // derived from (series, transaction) and does not ascend with time.
        $anchorObservedAt = (string) $db->connection()->table('recurring_series_occurrences')
            ->where('id', $row['latest_occurrence_id'])
            ->value('observed_at');

        $db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->where(function ($q) use ($anchorObservedAt, $row): void {
                $q->where('observed_at', '>', $anchorObservedAt)
                    ->orWhere(function ($tie) use ($anchorObservedAt, $row): void {
                        $tie->where('observed_at', $anchorObservedAt)
                            ->where('id', '>', $row['latest_occurrence_id']);
                    });
            })
            ->delete();

        $evaluator->evaluateForSeries($seriesId, $user);

        $written = $db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->first();

        expect($written)->not->toBeNull('the evaluator refused to open an alert the demo ships for series '.$seriesId);

        $produced[$seriesId] = [
            'baseline_amount_minor' => (int) $written->baseline_amount_minor,
            'latest_amount_minor' => (int) $written->latest_amount_minor,
            'delta_minor' => (int) $written->delta_minor,
            'annualized_impact_minor' => (int) $written->annualized_impact_minor,
            'threshold_percent_used' => (int) $written->threshold_percent_used,
            'threshold_source' => $written->threshold_source,
            'latest_occurrence_id' => (int) $written->latest_occurrence_id,
            'currency' => $written->currency,
        ];
    }

    expect($produced)->toBe($expected);
});

// Not every eligible subscription drifted. KPN is charged EUR 45.00 in all
// three months on purpose: an alert against every approved series would teach
// the reader that /drift lists their subscriptions rather than filters them.
it('leaves at least one eligible demo subscription with no alert and no price movement', function (): void {
    /** @var User $user */
    $user = $this->demoUser;
    /** @var DatabaseManager $db */
    $db = $this->db;

    $alertedSeriesIds = array_map(
        static fn (stdClass $alert): int => (int) $alert->recurring_series_id,
        dlsAlerts($db, $user),
    );

    $eligible = $db->connection()->table('recurring_series')
        ->where('user_id', $user->id)
        ->whereIn('state', ['approved', 'cadence_changed'])
        ->pluck('id')
        ->all();

    $quiet = array_values(array_diff(
        array_map(static fn (mixed $id): int => (int) $id, $eligible),
        $alertedSeriesIds,
    ));

    expect($quiet)->not->toBe([], 'every drift-eligible demo series carries an alert');

    foreach ($quiet as $seriesId) {
        $amounts = array_values(array_unique(array_map(
            static fn (stdClass $occurrence): int => (int) $occurrence->observed_amount_minor,
            dlsOccurrences($db, $user, $seriesId),
        )));

        expect($amounts)->toHaveCount(1, 'series '.$seriesId.' moved price but carries no alert');
    }
});
