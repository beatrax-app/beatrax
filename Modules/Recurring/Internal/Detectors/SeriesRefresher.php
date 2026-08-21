<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Detectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped;
use Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed;

// Shared by both detectors; only the direction they report differs.
final readonly class SeriesRefresher
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesStateMachine $stateMachine,
        private Dispatcher $events,
        private OccurrenceWriter $occurrences,
    ) {}

    // $healedName is set only for a row still showing the clustering key as its
    // name; it is never the user's own name for the series, which lives on
    // display_name_override and is not written here.
    public function refresh(
        RecurringSeries $series,
        string $counterpartyKey,
        DetectedSeries $detected,
        User $user,
        string $direction,
        ?string $healedName = null,
    ): void {
        $previousCadence = $series->cadence;
        $seriesId = $series->id;

        $columns = [
            'cadence' => $detected->cadence->value,
            'cluster_key' => $detected->clusterKey,
            'cluster_counterparty_key' => $counterpartyKey,
            'latest_amount_minor' => $detected->latestAmountMinor,
            'latest_currency' => $detected->currency,
            'monthly_equivalent_minor' => $detected->monthlyEquivalentMinor,
            'next_expected_at' => $detected->nextExpectedAt?->toDateString(),
            'next_expected_confidence_low' => $detected->confidenceLow,
            'updated_at' => $this->clock->now()->toDateTimeString(),
        ];

        if ($healedName !== null) {
            $columns['detected_name'] = $healedName;
        }

        $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->update($columns);

        $this->occurrences->write($user->id, $seriesId, $detected->rows, $detected->currency);

        if ($previousCadence !== $detected->cadence->value) {
            $this->flipCadence($series, $detected, $user, $previousCadence);
        }

        $this->events->dispatch(new RecurringSeriesMetricsRefreshed(
            userId: $user->id,
            recurringSeriesId: $seriesId,
            direction: $direction,
            cadence: $detected->cadence->value,
            latestAmountMinor: $detected->latestAmountMinor,
            latestCurrency: $detected->currency,
        ));
    }

    // The second state read is not redundant: the transition is only legal
    // from approved, and the row may have moved since the caller read it.
    private function flipCadence(RecurringSeries $series, DetectedSeries $detected, User $user, string $previousCadence): void
    {
        if (! in_array($series->state, [RecurringSeriesState::Approved->value, RecurringSeriesState::CadenceChanged->value], true)) {
            return;
        }

        /** @var RecurringSeries $fresh */
        $fresh = RecurringSeries::query()->findOrFail($series->id);
        if ($fresh->state !== RecurringSeriesState::Approved->value) {
            return;
        }

        $this->stateMachine->transition($fresh, RecurringSeriesState::CadenceChanged->value, 'detector_cadence_flip', 'detector');

        $this->events->dispatch(new RecurringSeriesCadenceFlipped(
            seriesId: $series->id,
            userId: $user->id,
            oldCadence: $previousCadence,
            newCadence: $detected->cadence->value,
        ));
    }
}
