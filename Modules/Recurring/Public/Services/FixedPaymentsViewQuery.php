<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Services\MerchantMemoryQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use stdClass;

/**
 * Public read API over approved `recurring_series` rows for the
 * /recurring page sections, the dashboard fixed-payments tile, and the
 * net-flow header summary.
 *
 * Three top-level methods:
 *  - `viewForUser(User)` — full grouped payload (expenses + income +
 *    transfers) for the /recurring page; each section sorted DESC by
 *    absolute monthly_equivalent_minor.
 *  - `topByMonthlyEquivalent(User, $limit = 6)` — dashboard tile payload.
 *  - `monthlyEquivalentTotals(User)` — single SUM query partitioned by
 *    direction, used by the page header net-flow summary.
 *
 * Query budget: `viewForUser` runs in at most three queries regardless
 * of N — one approved-series scan with the chain_link join, one
 * fallback-chain walk against `recurring_series_occurrences` joined to
 * `chain_links` (skipped when no series needs the fallback), and one
 * batch merchant-memory lookup (skipped when no expense rows surface).
 * The n-plus-one-budget feature test enforces ≤ 3 queries for N≥10.
 *
 * The transfers section ships empty in v1: neither the expense detector
 * (type='expense'|'fee'|'refund') nor the income detector
 * (type='income') reads `transfer_out`/`transfer_in` transactions, so
 * no `recurring_series` rows currently model a recurring transfer. The
 * empty list is reserved structure for future detector work; the
 * /recurring page renders the section as a collapsed `<details>` panel
 * so the layout slot is visible.
 *
 * Chain-fallback semantics: when a series'
 * `latest_funding_chain_link_id` is null or points at a `chain_links`
 * row whose `state` is anything other than `confirmed`/`candidate`,
 * walk back through the series' occurrences (ordered by `observed_at`
 * DESC) and adopt the first occurrence's confirmed/candidate chain.
 * The walk happens in a single batch query against the full
 * "needs-fallback" set so the per-row query count stays flat.
 *
 * Cross-user reads return empty containers; cross-user 404s are the
 * concern of mutating Public Actions, not read services.
 */
final readonly class FixedPaymentsViewQuery
{
    public function __construct(
        private DatabaseManager $db,
        private MerchantMemoryQuery $merchantMemory,
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

        // Batch decorate counterparty names — the DTO does not surface
        // a category id today, but priming the merchant-memory query
        // here keeps the cross-module read on the happy path so the
        // boundary arch invariant catches any future regression.
        $counterpartyNames = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $counterpartyNames[] = self::toString($row->detected_name);
        }
        $hasExpenseRow = false;
        foreach ($rows as $row) {
            /** @var stdClass $row */
            if (self::toString($row->direction) === 'expense') {
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
            if ($direction === 'income') {
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
     * Top approved series by absolute monthly equivalent, optionally
     * filtered to those whose next-expected-charge falls inside
     * `[$monthStart, $monthEnd]` (inclusive). Pushing the date filter
     * into the read layer means the dashboard's "This month only"
     * toggle returns up to `$limit` matching rows instead of "the
     * subset of the top N that happen to fall in this month" — the
     * latter could legitimately produce zero rows even when many
     * series are due.
     *
     * @return list<RecurringSeriesDto>
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
                "COALESCE(SUM(CASE WHEN direction = 'expense' THEN monthly_equivalent_minor ELSE 0 END), 0) AS expense_eur_minor, ".
                "COALESCE(SUM(CASE WHEN direction = 'income' THEN monthly_equivalent_minor ELSE 0 END), 0) AS income_eur_minor"
            )
            ->where('user_id', $user->id)
            ->where('state', 'approved')
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
            ->where('rs.state', 'approved')
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
     * Per-series prior-occurrence chain fallback. Returns a
     * `recurring_series_id => chain_link_id` map for every series whose
     * latest chain is missing/unresolved AND whose prior occurrences
     * include a transaction with a `confirmed`/`candidate` chain link.
     *
     * @param  list<stdClass>  $rows
     * @return array<int, int>
     */
    private function resolveFallbackChainIds(User $user, array $rows): array
    {
        $needsFallback = [];
        foreach ($rows as $row) {
            $chainLinkId = $row->latest_funding_chain_link_id ?? null;
            $chainLinkState = $row->chain_link_state ?? null;
            $hasResolvedChain = $chainLinkId !== null
                && is_string($chainLinkState)
                && in_array($chainLinkState, ['confirmed', 'candidate'], true);
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
            ->whereIn('cl.state', ['confirmed', 'candidate'])
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
        $latestCurrency = self::toString($row->latest_currency);
        $latestAmount = Money::ofMinor(self::toInt($row->latest_amount_minor), $latestCurrency);

        $eurEquivalent = null;
        if ($latestCurrency !== 'EUR' && isset($row->monthly_equivalent_minor)) {
            $eurEquivalent = Money::ofMinor(self::toInt($row->monthly_equivalent_minor), 'EUR');
        }

        $monthlyEquivalent = Money::ofMinor(
            isset($row->monthly_equivalent_minor) ? self::toInt($row->monthly_equivalent_minor) : 0,
            $latestCurrency !== '' ? $latestCurrency : 'EUR',
        );

        $nextExpectedAt = null;
        $rawNext = $row->next_expected_at ?? null;
        if (is_string($rawNext) && $rawNext !== '') {
            $nextExpectedAt = CarbonImmutable::parse($rawNext);
        }

        $snoozedUntil = null;
        $rawSnooze = $row->snoozed_until ?? null;
        if (is_string($rawSnooze) && $rawSnooze !== '') {
            $snoozedUntil = CarbonImmutable::parse($rawSnooze);
        }

        $displayNameOverride = $row->display_name_override ?? null;

        $chainLinkId = null;
        $primaryChainLinkId = $row->latest_funding_chain_link_id ?? null;
        $primaryChainState = $row->chain_link_state ?? null;
        if (
            $primaryChainLinkId !== null
            && is_string($primaryChainState)
            && in_array($primaryChainState, ['confirmed', 'candidate'], true)
        ) {
            $chainLinkId = self::toInt($primaryChainLinkId);
        } else {
            $seriesId = self::toInt($row->id);
            if (isset($fallbackMap[$seriesId])) {
                $chainLinkId = $fallbackMap[$seriesId];
            }
        }

        return new RecurringSeriesDto(
            seriesId: self::toInt($row->id),
            direction: self::toString($row->direction),
            detectedName: self::toString($row->detected_name),
            displayNameOverride: is_string($displayNameOverride) && $displayNameOverride !== ''
                ? $displayNameOverride
                : null,
            state: self::toString($row->state),
            cadence: self::toString($row->cadence),
            latestAmount: $latestAmount,
            eurEquivalent: $eurEquivalent,
            monthlyEquivalent: $monthlyEquivalent,
            latestFundingChainLinkId: $chainLinkId,
            nextExpectedAt: $nextExpectedAt,
            nextExpectedConfidenceLow: (bool) ($row->next_expected_confidence_low ?? false),
            varianceTolerancePercent: self::toInt($row->variance_tolerance_percent ?? 25),
            snoozedUntil: $snoozedUntil,
        );
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
