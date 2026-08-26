<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Public\Dto\SubscriptionDriftRow;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\FX\Public\Dto\ConvertedTotal;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/drift-alerts/drift-detection.md
 */
final class SubscriptionDriftWatchQuery
{
    private const FULL_HISTORY_POINTS = 600;

    public function __construct(
        private readonly RecurringSeriesQuery $series,
        private readonly DatabaseManager $db,
        private readonly CrossCurrencyTotal $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    /**
     * @return list<SubscriptionDriftRow>
     */
    public function forUser(User $user): array
    {
        $openAlertSeriesIds = $this->openAlertSeriesIds($user);

        $rows = [];
        foreach ($this->series->allApprovedForUser($user) as $dto) {
            if ($dto->direction !== Direction::Expense->value) {
                continue;
            }

            $trend = $this->series->amountTrendForSeries($dto->seriesId, $user, self::FULL_HISTORY_POINTS);
            $points = $trend->points;
            if (count($points) < 2) {
                continue;
            }

            $baseline = abs($points[0]['amount_minor']);
            $latest = abs($points[count($points) - 1]['amount_minor']);
            $delta = $latest - $baseline;

            $rows[] = new SubscriptionDriftRow(
                seriesId: $dto->seriesId,
                name: $dto->displayName(),
                baselineMinor: $baseline,
                latestMinor: $latest,
                deltaMinor: $delta,
                deltaPercent: $baseline > 0 ? $delta / $baseline * 100 : 0.0,
                monthlyEquivalentMinor: abs($dto->monthlyEquivalent->toMinor()),
                currency: $trend->currency,
                points: array_map(
                    static fn (array $point): array => ['date' => $point['date'], 'amount_minor' => abs($point['amount_minor'])],
                    $points,
                ),
                hasOpenAlert: isset($openAlertSeriesIds[$dto->seriesId]),
            );
        }

        return $this->biggestCreepFirst($rows, $user);
    }

    // "What crept up most" is a race between currencies, so it is run in the
    // reader's: on raw minor units a USD5.00 creep beat a EUR4.00 one while
    // being the smaller. A row the rate table cannot reach has no size in the
    // reader's currency, so it sorts after every row that does.
    /**
     * @param  list<SubscriptionDriftRow>  $rows
     * @return list<SubscriptionDriftRow>
     */
    private function biggestCreepFirst(array $rows, User $user): array
    {
        $baseCurrency = $this->baseCurrency->forUser($user);
        $rates = $this->fx->ratesTo(array_map(
            static fn (SubscriptionDriftRow $row): string => $row->currency,
            $rows,
        ), $baseCurrency);

        $inBase = [];
        foreach ($rows as $index => $row) {
            $money = Money::tryOfMinor($row->deltaMinor, $row->currency);
            $inBase[$index] = $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates)?->toMinor();
        }

        $order = array_keys($rows);
        usort($order, static function (int $a, int $b) use ($inBase, $rows): int {
            $rankable = ($inBase[$b] !== null) <=> ($inBase[$a] !== null);
            if ($rankable !== 0) {
                return $rankable;
            }

            return ($inBase[$b] ?? $rows[$b]->deltaMinor) <=> ($inBase[$a] ?? $rows[$a]->deltaMinor);
        });

        return array_map(static fn (int $index): SubscriptionDriftRow => $rows[$index], $order);
    }

    // A watchlist row's monthly equivalent is denominated in its own series'
    // currency, so the header figure buckets by currency and converts each
    // before adding. A currency with no rate is named rather than counted at
    // one to one.
    /**
     * @param  list<SubscriptionDriftRow>  $rows
     */
    public function monthlyTotalFor(User $user, array $rows): ConvertedTotal
    {
        $byCurrency = [];
        foreach ($rows as $row) {
            $byCurrency[$row->currency] = ($byCurrency[$row->currency] ?? 0) + $row->monthlyEquivalentMinor;
        }

        return $this->fx->of($byCurrency, $this->baseCurrency->forUser($user));
    }

    /**
     * @return array<int, true> series ids with an unresolved drift alert
     */
    private function openAlertSeriesIds(User $user): array
    {
        $ids = [];
        $rows = $this->db->connection()->table('drift_alerts')
            ->where('user_id', $user->id)
            ->where('state', DriftAlertState::Open->value)
            ->get(['recurring_series_id']);

        foreach ($rows as $row) {
            if (is_numeric($row->recurring_series_id)) {
                $ids[(int) $row->recurring_series_id] = true;
            }
        }

        return $ids;
    }
}
