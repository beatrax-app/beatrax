<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Internal\Support\SeriesIds;
use Modules\Recurring\Internal\Support\SeriesTables;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesAmountTrendDto;
use stdClass;

// The occurrence log a detected series was built from: every observation that
// joined the cluster, and the amounts read off them. Separate from the series
// rows because the log is append-only and per-observation, so the questions
// asked of it are about dates and amounts rather than about a series' state.
final readonly class RecurringOccurrenceQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private BaseCurrency $baseCurrency,
    ) {}

    // The reminder sweep asks this of every approved series on every run, and
    // only ever reads the newest date; occurrencesForSeries() hydrated a DTO
    // per observation to answer it, once per series.
    /**
     * @param  array<int|string, mixed>  $seriesIds
     * @return array<int, string> newest observed_at per id, as Y-m-d; a series with no
     *                            observation, a missing id or a cross-user id is silently absent
     */
    public function latestObservedAtForSeriesIds(array $seriesIds, User $user): array
    {
        $unique = SeriesIds::normalise($seriesIds);
        if ($unique === []) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->whereIn('recurring_series_id', $unique)
            ->groupBy('recurring_series_id')
            ->get([
                'recurring_series_id',
                $this->db->connection()->raw('MAX(observed_at) as latest_observed_at'),
            ]);

        $map = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $observed = self::toString($row->latest_observed_at ?? null);
            if ($observed === '') {
                continue;
            }
            $map[self::toInt($row->recurring_series_id)] = substr($observed, 0, 10);
        }

        return $map;
    }

    /**
     * @return list<RecurringOccurrenceDto> every observation row that contributed to the
     *                                      cluster, ordered by observed_at DESC. Cross-user lookups return an empty list
     */
    public function occurrencesForSeries(int $seriesId, User $user): array
    {
        $owns = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->exists();
        if (! $owns) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series_occurrences')
            ->where('recurring_series_id', $seriesId)
            ->where('user_id', $user->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        return self::toOccurrenceDtos($rows);
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return list<RecurringOccurrenceDto>
     */
    private static function toOccurrenceDtos(iterable $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $observedCurrency = self::toString($row->observed_currency);

            $result[] = new RecurringOccurrenceDto(
                occurrenceId: self::toInt($row->id),
                recurringSeriesId: self::toInt($row->recurring_series_id),
                transactionId: self::toInt($row->transaction_id),
                observedAt: CarbonImmutable::parse(self::toString($row->observed_at)),
                observedAmount: Money::ofMinor(self::toInt($row->observed_amount_minor), $observedCurrency),
                observedCurrency: $observedCurrency,
            );
        }

        return $result;
    }

    /**
     * @return list<RecurringOccurrenceDto> the newest $limit observations, newest first.
     *                                      DriftEvaluator reads two of them; occurrencesForSeries() hydrates the
     *                                      whole history to hand over the same pair. Cross-user lookups return []
     */
    public function latestOccurrencesForSeries(int $seriesId, User $user, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $rows = $this->db->connection()->table('recurring_series_occurrences as o')
            ->join('recurring_series as s', 's.id', '=', 'o.recurring_series_id')
            ->where('o.recurring_series_id', $seriesId)
            ->where('o.user_id', $user->id)
            ->where('s.user_id', $user->id)
            ->orderByDesc('o.observed_at')
            ->orderByDesc('o.id')
            ->limit($limit)
            ->get(['o.id', 'o.recurring_series_id', 'o.transaction_id', 'o.observed_at', 'o.observed_amount_minor', 'o.observed_currency']);

        return self::toOccurrenceDtos($rows);
    }

    /**
     * @return RecurringSeriesAmountTrendDto up to $maxPoints points, oldest first. Each
     *                                       carries the native amount plus the settled shadow; settled_amount_minor
     *                                       is null when the account was debited in the currency quoted
     */
    public function amountTrendForSeries(int $seriesId, User $user, int $maxPoints = 24): RecurringSeriesAmountTrendDto
    {
        $seriesRow = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();
        if ($seriesRow === null) {
            return new RecurringSeriesAmountTrendDto(
                seriesId: $seriesId,
                currency: $this->baseCurrency->code(),
                points: [],
                maxPoints: $maxPoints,
            );
        }

        /** @var stdClass $seriesRow */
        $currency = self::toString($seriesRow->latest_currency);
        if ($currency === '') {
            // Schema guarantees latest_currency is non-null + 3 chars, so an
            // empty value means a corrupt row. Fabricating an EUR label here
            // would mislabel the chart axis, so return nothing instead.
            return new RecurringSeriesAmountTrendDto(
                seriesId: $seriesId,
                currency: '',
                points: [],
                maxPoints: $maxPoints,
            );
        }

        $effectiveLimit = max(1, $maxPoints);
        $rows = $this->db->connection()->table('recurring_series_occurrences as rso')
            ->leftJoin(SeriesTables::TRANSACTIONS, 't.id', '=', 'rso.transaction_id')
            ->where('rso.recurring_series_id', $seriesId)
            ->where('rso.user_id', $user->id)
            ->orderByDesc('rso.observed_at')
            ->orderByDesc('rso.id')
            ->limit($effectiveLimit)
            ->get([
                'rso.observed_at',
                'rso.observed_amount_minor',
                'rso.observed_currency',
                't.settled_amount_minor as settled_amount_minor',
                't.settled_currency as settled_currency',
            ]);

        // DESC + LIMIT is how the newest N are fetched; reverse so the chart's
        // time axis runs left-to-right.
        $ordered = $rows->reverse()->values();

        $points = [];
        foreach ($ordered as $row) {
            /** @var stdClass $row */
            $observedAt = CarbonImmutable::parse(self::toString($row->observed_at))->toDateString();
            $amountMinor = self::toInt($row->observed_amount_minor);
            $observedCurrency = self::toString($row->observed_currency);
            // Whatever the account was actually debited, whenever that is not
            // what the charge was quoted in. Pinned to the euro, the shadow
            // line never appeared for an account denominated in anything else.
            $settledCurrency = self::toString($row->settled_currency ?? null);
            $settledMinor = null;
            if ($settledCurrency !== '' && $settledCurrency !== $observedCurrency) {
                $settledMinor = self::toInt($row->settled_amount_minor ?? null);
            }
            $points[] = [
                'date' => $observedAt,
                'amount_minor' => $amountMinor,
                // The header's latest_currency is rewritten on every refresh,
                // so a point stamped with it rather than its own reads as an
                // amount it never was once a merchant changes denomination.
                'currency' => $observedCurrency,
                'settled_amount_minor' => $settledMinor,
                'settled_currency' => $settledMinor === null ? null : $settledCurrency,
            ];
        }

        return new RecurringSeriesAmountTrendDto(
            seriesId: $seriesId,
            currency: $currency,
            points: $points,
            maxPoints: $maxPoints,
        );
    }
}
