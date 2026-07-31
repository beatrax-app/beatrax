<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Public\Enums\SeriesCadence;
use stdClass;

// Decides whether a past-day expected entry was actually paid: loads observed
// occurrences around the month and matches them to expected dates within a
// cadence-clamped tolerance window.
/**
 * @link ../../../../.docs/features/calendar/architecture.md
 */
final readonly class OccurrenceMatcher
{
    use CoercesScalars;

    // Tolerance window cap for past-day paid/missed matching; the effective
    // window is clamped per cadence in matchWindowDays() so one observed
    // payment can never mark multiple adjacent expected entries paid.
    private const int MATCH_WINDOW_DAYS = 7;

    private const int DAYS_PER_WEEK = 7;

    public function __construct(private DatabaseManager $db) {}

    /**
     * @return array<int, list<string>>
     */
    public function buildOccurrenceMap(
        User $user,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): array {
        // Extend the window to catch occurrences just outside the month that
        // might still match entries near the month boundaries.
        $windowStart = $monthStart->subDays(self::MATCH_WINDOW_DAYS)->toDateString();
        $windowEnd = $monthEnd->addDays(self::MATCH_WINDOW_DAYS)->toDateString();

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->whereBetween('observed_at', [$windowStart, $windowEnd])
            ->get(['recurring_series_id', 'observed_at']);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $seriesId = self::toInt($row->recurring_series_id);
            if ($seriesId === 0) {
                continue;
            }
            $observedDate = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $map[$seriesId][] = $observedDate;
        }

        return $map;
    }

    // The window is clamped to half the cadence interval so adjacent
    // expected occurrences can never both match the same observed payment.
    public function matchWindowDays(?SeriesCadence $cadence): int
    {
        // Weekly is the only cadence short enough for the clamp to bite —
        // half of a monthly interval already exceeds the default window. The
        // string version also carried a 'daily' arm no series could reach:
        // SeriesCadence has no daily case and never did.
        $cadenceDays = match ($cadence) {
            SeriesCadence::Weekly => self::DAYS_PER_WEEK,
            default => null,
        };

        if ($cadenceDays === null) {
            return self::MATCH_WINDOW_DAYS;
        }

        return min(self::MATCH_WINDOW_DAYS, intdiv($cadenceDays, 2));
    }

    /**
     * @param  list<string>  $observedDates
     */
    public function hasMatchingOccurrence(CarbonImmutable $date, array $observedDates, int $windowDays): bool
    {
        $expected = $date->startOfDay();
        $windowStart = $expected->subDays($windowDays);
        $windowEnd = $expected->addDays($windowDays);

        foreach ($observedDates as $observedDateStr) {
            $observed = CarbonImmutable::parse($observedDateStr)->startOfDay();
            if ($observed->gte($windowStart) && $observed->lte($windowEnd)) {
                return true;
            }
        }

        return false;
    }
}
