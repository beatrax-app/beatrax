<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Services;

use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\AmountMovement;
use Modules\DriftAlerts\Public\Dto\SubscriptionDriftRow;
use Modules\FX\Public\Dto\ConvertedTotal;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../../.docs/features/drift-alerts/drift-detection.md
 */
final readonly class SubscriptionDriftWatchQuery
{
    private const int FULL_HISTORY_POINTS = 600;

    public function __construct(
        private RecurringSeriesQuery $series,
        private RecurringOccurrenceQuery $occurrences,
        private CrossCurrencyTotal $fx,
        private BaseCurrency $baseCurrency,
        private DriftAlertQuery $alerts,
    ) {}

    /**
     * @return list<SubscriptionDriftRow>
     */
    public function forUser(User $user): array
    {
        $openAlertSeriesIds = $this->alerts->openSeriesIdsForUser($user);

        $rows = [];
        foreach ($this->series->allApprovedForUser($user) as $dto) {
            if ($dto->direction !== Direction::Expense->value) {
                continue;
            }

            $trend = $this->occurrences->amountTrendForSeries($dto->seriesId, $user, self::FULL_HISTORY_POINTS);
            $points = $trend->points;
            if (count($points) < 2) {
                continue;
            }

            // The same gate the evaluator applies before it calls a movement a
            // movement. Taking magnitudes first hid both refusals: a zero
            // baseline printed "+0.0%" beside a real euro figure, and a refund
            // against a charge rendered as a +100.0% price rise.
            $firstPoint = $points[0];
            $lastPoint = $points[count($points) - 1];
            $first = Money::tryOfMinor($firstPoint['amount_minor'], self::currencyOf($firstPoint, $trend->currency));
            $last = Money::tryOfMinor($lastPoint['amount_minor'], self::currencyOf($lastPoint, $trend->currency));
            if ($first === null || $last === null
                || $first->currency() !== $trend->currency
                || $last->currency() !== $trend->currency) {
                continue;
            }

            $movement = AmountMovement::between($first, $last);
            if ($movement === null) {
                continue;
            }

            $baseline = abs($movement->priorMinor);
            $latest = abs($movement->latestMinor);
            $delta = $latest - $baseline;

            $rows[] = new SubscriptionDriftRow(
                seriesId: $dto->seriesId,
                name: $dto->displayName(),
                baselineMinor: $baseline,
                latestMinor: $latest,
                deltaMinor: $delta,
                deltaPercent: $delta / $baseline * 100,
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

    // Each occurrence's own code, because latest_currency is rewritten on every
    // refresh and a point stamped with it reads as an amount it never was.
    // Converting the older one at today's rate would file the rate's movement
    // as the merchant's, so the row is refused as DriftEvaluator refuses it.
    /**
     * @param  array{date: string, amount_minor: int, currency?: string, settled_amount_minor: int|null, settled_currency: string|null}  $point
     */
    private static function currencyOf(array $point, string $fallback): string
    {
        $currency = $point['currency'] ?? '';

        return $currency === '' ? $fallback : $currency;
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
}
