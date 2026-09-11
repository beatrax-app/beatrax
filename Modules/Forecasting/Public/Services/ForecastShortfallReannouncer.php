<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Forecasting\Public\Events\ForecastShortfallDetected;
use stdClass;

// The projection is not re-run: it writes forecast runs and shortfall windows
// that need no key and already landed, and re-deriving a notification must not
// cost the reader a second sweep of work that was never lost. The windows the
// keyless run persisted are the announcement's whole input.
/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-triggers-no-scheduled-pass-covers
 */
final readonly class ForecastShortfallReannouncer
{
    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
    ) {}

    // Baseline only, matching the detector: a scenario is a question, and a dip
    // the reader never chose to have raises nothing.
    public function baselineForUser(int $userId): void
    {
        $rows = $this->db->connection()
            ->table('forecast_shortfall_windows')
            ->where('user_id', $userId)
            ->whereNull('scenario_id')
            ->select(['account_id', 'starts_at', 'ends_at', 'lowest_balance_minor', 'currency', 'buffer_used_minor'])
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

    private static function rowToEvent(stdClass $row, int $userId): ?ForecastShortfallDetected
    {
        // One refusal over every column the event needs, rather than one per
        // pair: the row is a wire shape and a missing field is a missing field
        // whichever it is, so splitting the answer said nothing extra.
        $usable = is_numeric($row->account_id)
            && is_string($row->currency)
            && is_numeric($row->lowest_balance_minor)
            && is_numeric($row->buffer_used_minor)
            && is_string($row->starts_at)
            && is_string($row->ends_at);

        if (! $usable) {
            return null;
        }

        try {
            $startsAt = CarbonImmutable::parse($row->starts_at);
            $endsAt = CarbonImmutable::parse($row->ends_at);
        } catch (InvalidFormatException) {
            return null;
        }

        return new ForecastShortfallDetected(
            userId: $userId,
            accountId: (int) $row->account_id,
            scenarioId: null,
            startsAt: $startsAt,
            endsAt: $endsAt,
            lowestBalanceMinor: (int) $row->lowest_balance_minor,
            currency: $row->currency,
            bufferUsedMinor: (int) $row->buffer_used_minor,
        );
    }
}
