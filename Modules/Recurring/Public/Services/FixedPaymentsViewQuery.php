<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Services\MerchantMemoryQuery;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Recurring\Internal\Mapping\RecurringSeriesDtoMapper;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use stdClass;

final readonly class FixedPaymentsViewQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private MerchantMemoryQuery $merchantMemory,
        private BaseCurrency $baseCurrency,
    ) {}

    /**
     * @return array{expenses: list<RecurringSeriesDto>, income: list<RecurringSeriesDto>, transfers: list<RecurringSeriesDto>}
     */
    public function viewForUser(User $user): array
    {
        $rows = $this->approvedRows($user);
        if ($rows === []) {
            return ['expenses' => [], 'income' => [], 'transfers' => []];
        }

        $fallbackMap = $this->resolveFallbackChainIds($user, $rows);

        // The merchant-memory result is deliberately discarded: keeping the
        // cross-module read on the happy path is what lets the boundary arch
        // test catch a regression. It matches on the normalised key, so it
        // is fed cluster_counterparty_key and never the name as written.
        $counterpartyNames = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterpartyNames[] = self::toString($row->cluster_counterparty_key);
        }
        $hasExpenseRow = false;
        foreach ($rows as $row) {
            /** @var stdClass $row */
            if (self::toString($row->direction) === Direction::Expense->value) {
                $hasExpenseRow = true;

                break;
            }
        }
        if ($hasExpenseRow) {
            $this->merchantMemory->forCounterpartiesNormalized($user, $counterpartyNames);
        }

        $expenses = [];
        $income = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $direction = self::toString($row->direction);
            $dto = $this->toDto($row, $fallbackMap);
            if ($direction === Direction::Income->value) {
                $income[] = $dto;
            } else {
                $expenses[] = $dto;
            }
        }

        self::sortByAbsoluteMonthlyEquivalentDesc($expenses);
        self::sortByAbsoluteMonthlyEquivalentDesc($income);

        return [
            'expenses' => $expenses,
            'income' => $income,
            'transfers' => [],
        ];
    }

    /**
     * @return list<RecurringSeriesDto> top approved series by absolute monthly equivalent,
     *                                  optionally filtered to those whose next-expected-charge falls inside
     *                                  [$monthStart, $monthEnd] (inclusive). Filtering at the read layer means the
     *                                  dashboard's "This month only" toggle returns up to $limit matching rows instead of
     *                                  "the subset of the top N that happen to fall in this month", which could
     *                                  legitimately produce zero rows even when many series are due
     */
    public function topByMonthlyEquivalent(
        User $user,
        int $limit = 6,
        ?CarbonImmutable $monthStart = null,
        ?CarbonImmutable $monthEnd = null,
    ): array {
        $sections = $this->viewForUser($user);
        $combined = array_merge($sections['expenses'], $sections['income']);

        if ($monthStart !== null && $monthEnd !== null) {
            $combined = array_values(array_filter(
                $combined,
                static function (RecurringSeriesDto $row) use ($monthStart, $monthEnd): bool {
                    if ($row->nextExpectedAt === null) {
                        return false;
                    }

                    return $row->nextExpectedAt->between($monthStart, $monthEnd);
                },
            ));
        }

        self::sortByAbsoluteMonthlyEquivalentDesc($combined);

        return array_slice($combined, 0, $limit);
    }

    /**
     * @return array{expense_eur_minor: int, income_eur_minor: int, net_eur_minor: int}
     */
    public function monthlyEquivalentTotals(User $user): array
    {
        $row = $this->db->connection()
            ->table('recurring_series')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN direction = ? THEN monthly_equivalent_minor ELSE 0 END), 0) AS expense_eur_minor, '.
                'COALESCE(SUM(CASE WHEN direction = ? THEN monthly_equivalent_minor ELSE 0 END), 0) AS income_eur_minor',
                [Direction::Expense->value, Direction::Income->value],
            )
            ->where('user_id', $user->id)
            ->where('state', RecurringSeriesState::Approved->value)
            ->first();

        $expense = $row !== null ? self::toInt($row->expense_eur_minor) : 0;
        $income = $row !== null ? self::toInt($row->income_eur_minor) : 0;

        return [
            'expense_eur_minor' => $expense,
            'income_eur_minor' => $income,
            'net_eur_minor' => $income + $expense,
        ];
    }

    /**
     * @return list<stdClass>
     */
    private function approvedRows(User $user): array
    {
        $rows = $this->db->connection()
            ->table('recurring_series as rs')
            ->leftJoin('chain_links as cl', 'cl.id', '=', 'rs.latest_funding_chain_link_id')
            ->where('rs.user_id', $user->id)
            ->where('rs.state', RecurringSeriesState::Approved->value)
            ->orderByDesc('rs.monthly_equivalent_minor')
            ->orderByDesc('rs.id')
            ->get([
                'rs.id',
                'rs.user_id',
                'rs.direction',
                'rs.detected_name',
                'rs.display_name_override',
                'rs.state',
                'rs.cadence',
                'rs.latest_amount_minor',
                'rs.latest_currency',
                'rs.latest_fx_rate_used',
                'rs.monthly_equivalent_minor',
                'rs.variance_tolerance_percent',
                'rs.latest_funding_chain_link_id',
                'rs.snoozed_until',
                'rs.next_expected_at',
                'rs.next_expected_confidence_low',
                'rs.cluster_key',
                'rs.cluster_counterparty_key',
                'cl.state as chain_link_state',
            ]);

        $list = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $list[] = $row;
        }

        return $list;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array<int, int> recurring_series_id => chain_link_id per-series prior-occurrence
     *                         fallback, for every series whose latest chain is missing/unresolved AND whose prior
     *                         occurrences include a transaction with a confirmed/candidate chain link
     */
    private function resolveFallbackChainIds(User $user, array $rows): array
    {
        $needsFallback = [];
        foreach ($rows as $row) {
            $chainLinkId = $row->latest_funding_chain_link_id ?? null;
            $chainLinkState = $row->chain_link_state ?? null;
            $hasResolvedChain = $chainLinkId !== null
                && is_string($chainLinkState)
                && in_array($chainLinkState, [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value], true);
            if (! $hasResolvedChain) {
                $needsFallback[] = self::toInt($row->id);
            }
        }

        if ($needsFallback === []) {
            return [];
        }

        $rowsFromJoin = $this->db->connection()
            ->table('recurring_series_occurrences as rso')
            ->join('chain_links as cl', 'cl.from_transaction_id', '=', 'rso.transaction_id')
            ->where('rso.user_id', $user->id)
            ->where('cl.user_id', $user->id)
            ->whereIn('cl.state', [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value])
            ->whereIn('rso.recurring_series_id', $needsFallback)
            ->orderByDesc('rso.observed_at')
            ->orderByDesc('rso.id')
            ->get(['rso.recurring_series_id', 'cl.id as chain_link_id']);

        $map = [];
        foreach ($rowsFromJoin as $candidate) {
            /** @var stdClass $candidate */
            $seriesId = self::toInt($candidate->recurring_series_id);
            if (isset($map[$seriesId])) {
                continue;
            }
            $map[$seriesId] = self::toInt($candidate->chain_link_id);
        }

        return $map;
    }

    /**
     * @param  array<int, int>  $fallbackMap
     */
    private function toDto(stdClass $row, array $fallbackMap): RecurringSeriesDto
    {
        // Falls back to walking the series' occurrences for the first usable
        // chain. RecurringSeriesQuery skips that walk; its callers don't need
        // the fallback and would pay for it on every row.
        $chainLinkId = null;
        $primaryChainLinkId = $row->latest_funding_chain_link_id ?? null;
        $primaryChainState = $row->chain_link_state ?? null;
        if (
            $primaryChainLinkId !== null
            && is_string($primaryChainState)
            && in_array($primaryChainState, [ChainLinkState::Confirmed->value, ChainLinkState::Candidate->value], true)
        ) {
            $chainLinkId = self::toInt($primaryChainLinkId);
        } else {
            $seriesId = self::toInt($row->id);
            if (isset($fallbackMap[$seriesId])) {
                $chainLinkId = $fallbackMap[$seriesId];
            }
        }

        return RecurringSeriesDtoMapper::hydrate($row, $chainLinkId, $this->baseCurrency->code());
    }

    /**
     * @param  list<RecurringSeriesDto>  $rows
     */
    private static function sortByAbsoluteMonthlyEquivalentDesc(array &$rows): void
    {
        usort($rows, static function (RecurringSeriesDto $a, RecurringSeriesDto $b): int {
            return abs($b->monthlyEquivalent->toMinor()) <=> abs($a->monthlyEquivalent->toMinor());
        });
    }
}
