<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\CurrencyScale;
use Modules\Recurring\Internal\Mapping\RecurringSeriesDtoMapper;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

final readonly class RecurringSeriesProjector
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    /**
     * @param  list<string>  $states
     * @return list<RecurringSeriesDto>
     */
    public function scoped(User $user, array $states, ?int $cursorId, int $limit, SeriesPageSort $sort): array
    {
        $query = $this->db->connection()->table('recurring_series')
            ->select('recurring_series.*')
            ->selectSub($this->latestObservedAt(), 'latest_observed_at')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->limit($limit);

        if ($sort === SeriesPageSort::LargestMonthlyEquivalentFirst) {
            [$worth, $bindings] = self::worthInBase($this->multipliers($user));
            $query->orderByRaw($worth.' DESC', $bindings)->orderByDesc('id');
        } else {
            // `id` is derived from the series' own identity columns, so it
            // sorts in hash order rather than insertion order: on `id DESC`
            // alone the review queue said newest-first and showed an arbitrary
            // shuffle. created_at leads; id only breaks ties within a second.
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        if ($cursorId !== null) {
            $this->applyCursor($query, $user, $cursorId, $sort);
        }

        return $this->toDtos($query->get()->all());
    }

    private function applyCursor(Builder $query, User $user, int $cursorId, SeriesPageSort $sort): void
    {
        if ($sort === SeriesPageSort::NewestFirst) {
            $cursorRow = $this->cursorRow($user, $cursorId, 'created_at');
            if ($cursorRow === null) {
                return;
            }

            $cursorCreatedAt = self::toString($cursorRow->created_at);
            $query->where(function (Builder $q) use ($cursorCreatedAt, $cursorId): void {
                $q->where('created_at', '<', $cursorCreatedAt)
                    ->orWhere(function (Builder $q2) use ($cursorCreatedAt, $cursorId): void {
                        $q2->where('created_at', $cursorCreatedAt)->where('id', '<', $cursorId);
                    });
            });

            return;
        }

        $cursorRow = $this->cursorRow($user, $cursorId, 'monthly_equivalent_minor', 'latest_currency');
        if ($cursorRow === null) {
            return;
        }

        // The cursor carries the sort value as well as the id: on an id alone,
        // rows tying on that value skip or repeat across the page boundary. The
        // value is the ordering expression's, not the raw column's, or the page
        // boundary lands in a different place from the sort.
        $multipliers = $this->multipliers($user);
        [$worth, $bindings] = self::worthInBase($multipliers);
        $cursorCurrency = self::toString($cursorRow->latest_currency ?? null);
        $cursorWorth = (int) (abs(self::toInt($cursorRow->monthly_equivalent_minor)) * ($multipliers[$cursorCurrency] ?? 1.0));

        $query->where(function (Builder $q) use ($worth, $bindings, $cursorWorth, $cursorId): void {
            $q->whereRaw($worth.' < ?', [...$bindings, $cursorWorth])
                ->orWhere(function (Builder $q2) use ($worth, $bindings, $cursorWorth, $cursorId): void {
                    $q2->whereRaw($worth.' = ?', [...$bindings, $cursorWorth])
                        ->where('id', '<', $cursorId);
                });
        });
    }

    // A list headed "biggest first" put ¥10,000 a month (10000, about €63)
    // above €99 a month (9900). The multiplier is the rate times the ratio of
    // the two minor-unit scales; a pair no rate reaches keeps its raw units.
    /**
     * @return array<string, float>
     *
     * @link ../../../../.docs/features/ledger/minor-units-and-zero-decimal-currencies.md#the-other-half-comparing-two-denominations-as-bare-integers
     */
    private function multipliers(User $user): array
    {
        $baseCurrency = $this->baseCurrency->forUser($user);
        $baseScale = CurrencyScale::minorUnitsPerMajor($baseCurrency);

        $currencies = array_values(array_filter(
            array_map(self::toString(...), $this->db->connection()->table('recurring_series')
                ->where('user_id', $user->id)
                ->distinct()
                ->pluck('latest_currency')
                ->all()),
            static fn (string $code): bool => $code !== '' && $code !== $baseCurrency,
        ));

        $multipliers = [];
        foreach ($this->fx->ratesTo($currencies, $baseCurrency) as $currency => $rate) {
            $scale = CurrencyScale::minorUnitsPerMajor($currency);
            $multipliers[$currency] = (float) $rate * $baseScale / $scale;
        }

        return $multipliers;
    }

    /**
     * @param  array<string, float>  $multipliers  as returned by multipliers()
     * @return array{literal-string, list<float|string>}
     */
    private static function worthInBase(array $multipliers): array
    {
        $magnitude = 'ABS(COALESCE(monthly_equivalent_minor, 0))';

        if ($multipliers === []) {
            return [$magnitude, []];
        }

        $cases = '';
        $bindings = [];
        foreach ($multipliers as $currency => $multiplier) {
            $cases .= ' WHEN ? THEN ?';
            $bindings[] = $currency;
            $bindings[] = $multiplier;
        }

        // CAST, and an integer on the other side of the comparison: PDO binds a
        // PHP float as a string, and SQLite sorts every number below every
        // string, so `worth < 9900.0` was true for every row and the cursor
        // handed back the page it had just returned.
        return ['CAST('.$magnitude.' * (CASE latest_currency'.$cases.' ELSE 1 END) AS INTEGER)', $bindings];
    }

    // Scoped to the reader: unscoped, another household member's row decided
    // which of this reader's rows came back, which is a binary search on their
    // monthly_equivalent_minor.
    private function cursorRow(User $user, int $cursorId, string ...$columns): ?stdClass
    {
        /** @var stdClass|null $row */
        $row = $this->db->connection()->table('recurring_series')
            ->where('id', $cursorId)
            ->where('user_id', $user->id)
            ->first($columns);

        return $row;
    }

    // The day the series last actually saw money, which is what tells a passed
    // next-expected date apart from a charge that simply landed after it. The
    // (recurring_series_id, observed_at) index serves the MAX, so it costs one
    // indexed lookup per row rather than a second pass over the occurrences.
    private function latestObservedAt(): Builder
    {
        return $this->db->connection()->table('recurring_series_occurrences')
            ->selectRaw('MAX(observed_at)')
            ->whereColumn('recurring_series_occurrences.recurring_series_id', 'recurring_series.id');
    }

    // Batched on purpose: ratesTo() reads the whole exchange_rates table per
    // currency, so a page of rows asks for each pair once rather than once per
    // row.
    /**
     * @param  iterable<mixed>  $rows
     * @return list<RecurringSeriesDto>
     */
    public function toDtos(iterable $rows): array
    {
        $list = [];
        $currencies = [];
        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }
            $list[] = $row;
            $currencies[] = self::toString($row->latest_currency ?? null);
        }

        $baseCurrency = $this->baseCurrency->code();
        $rates = $this->fx->ratesTo($currencies, $baseCurrency);

        $result = [];
        foreach ($list as $row) {
            $result[] = $this->hydrate($row, $baseCurrency, $rates);
        }

        return $result;
    }

    public function toDto(stdClass $row): RecurringSeriesDto
    {
        $baseCurrency = $this->baseCurrency->code();

        return $this->hydrate(
            $row,
            $baseCurrency,
            $this->fx->ratesTo([self::toString($row->latest_currency ?? null)], $baseCurrency),
        );
    }

    /**
     * @param  array<string, string>  $rates
     */
    private function hydrate(stdClass $row, string $baseCurrency, array $rates): RecurringSeriesDto
    {
        // The raw column only. The occurrence-walk fallback lives in
        // FixedPaymentsViewQuery, the one caller it is load-bearing for.
        $chainLinkId = isset($row->latest_funding_chain_link_id)
            ? self::toInt($row->latest_funding_chain_link_id)
            : null;

        return RecurringSeriesDtoMapper::hydrate($row, $chainLinkId, $baseCurrency, $this->fx, $rates);
    }
}
