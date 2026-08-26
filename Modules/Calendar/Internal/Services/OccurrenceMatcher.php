<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Support\MatchWindow;
use stdClass;

final readonly class OccurrenceMatcher
{
    use CoercesScalars;

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
        // Overshoots the month so an entry near a boundary can still match an
        // occurrence that landed just outside it.
        $windowStart = $monthStart->subDays(MatchWindow::DAYS)->toDateString();
        $windowEnd = $monthEnd->addDays(MatchWindow::DAYS)->toDateString();

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
        // Only weekly is short enough for the clamp to bite: half a monthly
        // interval already exceeds the default window.
        $cadenceDays = match ($cadence) {
            SeriesCadence::Weekly => self::DAYS_PER_WEEK,
            default => null,
        };

        if ($cadenceDays === null) {
            return MatchWindow::DAYS;
        }

        return min(MatchWindow::DAYS, intdiv($cadenceDays, 2));
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
