<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Queries;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
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
    public function scoped(User $user, array $states, ?int $cursorId, int $limit, string $primarySort): array
    {
        $query = $this->db->connection()->table('recurring_series')
            ->select('recurring_series.*')
            ->selectSub($this->latestObservedAt(), 'latest_observed_at')
            ->where('user_id', $user->id)
            ->whereIn('state', $states)
            ->limit($limit);

        if ($primarySort === 'monthly_equivalent_minor') {
            $query->orderByDesc('monthly_equivalent_minor')->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        if ($cursorId !== null) {
            if ($primarySort === 'monthly_equivalent_minor') {
                // The cursor has to carry the sort value as well as the id:
                // on an id alone, rows tying on the sort value get skipped or
                // repeated across the page boundary.
                $cursorRow = $this->db->connection()->table('recurring_series')
                    ->where('id', $cursorId)
                    ->first(['monthly_equivalent_minor']);
                if ($cursorRow !== null) {
                    $cursorEq = self::toInt($cursorRow->monthly_equivalent_minor);
                    $query->where(function (Builder $q) use ($cursorEq, $cursorId): void {
                        $q->where('monthly_equivalent_minor', '<', $cursorEq)
                            ->orWhere(function (Builder $q2) use ($cursorEq, $cursorId): void {
                                $q2->where('monthly_equivalent_minor', $cursorEq)
                                    ->where('id', '<', $cursorId);
                            });
                    });
                }
            } else {
                $query->where('id', '<', $cursorId);
            }
        }

        return $this->toDtos($query->get()->all());
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
