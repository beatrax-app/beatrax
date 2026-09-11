<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use stdClass;

// Re-announcing, not re-detecting: DriftEvaluator dispatches DriftAlertOpened
// only when its insert wins the table's UNIQUE, so a second detection run finds
// the row already standing and says nothing. The row is what a keyless run left
// behind; the announcement is what it could not seal.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-triggers-no-scheduled-pass-covers
 */
final readonly class DriftAlertReannouncer
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    // Open alerts only. An alert the reader acknowledged, snoozed or dismissed
    // is one they have already answered, and a notification about it days later
    // would be the app arguing with a decision they made.
    public function openForUser(int $userId): void
    {
        $rows = $this->db->connection()
            ->table('drift_alerts')
            ->where('user_id', $userId)
            ->where('state', DriftAlertState::Open->value)
            ->select(['id', 'recurring_series_id', 'direction', 'delta_minor', 'annualized_impact_minor', 'currency'])
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $event = self::rowToEvent($row, $userId);
            if ($event !== null) {
                $this->events->dispatch($event);
            }
        }
    }

    private static function rowToEvent(stdClass $row, int $userId): ?DriftAlertOpened
    {
        // One refusal over every column the event needs, rather than one per
        // pair: the row is a wire shape and a missing field is a missing field
        // whichever it is, so splitting the answer said nothing extra.
        $usable = is_numeric($row->id)
            && is_numeric($row->recurring_series_id)
            && is_numeric($row->delta_minor)
            && is_numeric($row->annualized_impact_minor)
            && is_string($row->direction)
            && is_string($row->currency);

        if (! $usable) {
            return null;
        }

        return new DriftAlertOpened(
            userId: $userId,
            driftAlertId: (int) $row->id,
            recurringSeriesId: (int) $row->recurring_series_id,
            direction: $row->direction,
            deltaMinor: (int) $row->delta_minor,
            annualizedImpactMinor: (int) $row->annualized_impact_minor,
            currency: $row->currency,
        );
    }
}
