<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Dto\ForecastHighlightsDto;
use stdClass;

/**
 * Public read API powering the dashboard "Forecast highlights" tile +
 * the top-nav "Forecast" slot badge.
 *
 * Returns a single `ForecastHighlightsDto` from `forUser` carrying the
 * lowest-projected-balance line + the active-shortfall count + the
 * next ICS bulk-iDEAL settlement (Phase 5 strict superset). The
 * top-nav badge composer uses the lighter `activeShortfallCountForUser`
 * which only counts baseline rows (scenario shortfalls do NOT count
 * toward the badge per D-1013).
 *
 * Baseline-only filter (`scenario_id IS NULL`): the dashboard +
 * top-nav represent the user's CURRENT financial picture. Scenarios
 * are "what-if" simulations the user explicitly opted into; they
 * carry their own UI affordances on /forecast. Including a scenario
 * shortfall in the badge would conflate "real" and "imagined" risk.
 *
 * Window-active filter: a window is "active in the next 30 days" when
 * `starts_at <= today + 30d` AND `ends_at >= today`. Past windows
 * remain in the table for the audit trail but do not surface in the
 * tile.
 *
 * Cross-user safety: every read filters on `user_id` before any join.
 */
final readonly class ForecastHighlightsQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CardStatementQuery $cardStatementQuery,
    ) {}

    public function activeShortfallCountForUser(User $user): int
    {
        $today = $this->clock->now()->startOfDay()->toDateString();
        $horizon = $this->clock->now()->startOfDay()->addDays(30)->toDateString();

        return $this->db->connection()->table('forecast_shortfall_windows')
            ->where('user_id', $user->id)
            ->whereNull('scenario_id')
            ->where('starts_at', '<=', $horizon)
            ->where('ends_at', '>=', $today)
            ->count();
    }

    public function forUser(User $user): ForecastHighlightsDto
    {
        $shortfallCount = $this->activeShortfallCountForUser($user);

        // Lowest projected balance across all accounts in the next 30
        // days. Walk the most-recent baseline run per account at
        // horizon 30 and find the minimum point.
        $lowestMinor = null;
        $lowestDate = null;
        $lowestAccountId = null;
        $lowestAccountName = null;

        // Account list — alphabetical by name, then id for stability.
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($accounts as $accountRow) {
            /** @var stdClass $accountRow */
            $accountId = is_numeric($accountRow->id) ? (int) $accountRow->id : 0;
            $accountName = is_string($accountRow->name) ? $accountRow->name : '';

            $run = $this->db->connection()->table('forecast_runs')
                ->where('user_id', $user->id)
                ->whereNull('scenario_id')
                ->where('horizon_days', 30)
                ->where('status', 'complete')
                ->orderByDesc('id')
                ->first(['result_json']);

            if ($run === null) {
                continue;
            }
            /** @var stdClass $run */
            $rawJson = is_string($run->result_json ?? null) ? $run->result_json : '';
            if ($rawJson === '') {
                continue;
            }
            $decoded = json_decode($rawJson, associative: true);
            if (! is_array($decoded)) {
                continue;
            }
            $accountsBlock = $decoded['accounts'] ?? null;
            if (! is_array($accountsBlock)) {
                continue;
            }
            $accountResult = $accountsBlock[(string) $accountId] ?? $accountsBlock[$accountId] ?? null;
            if (! is_array($accountResult)) {
                continue;
            }
            $points = $accountResult['points'] ?? null;
            if (! is_array($points)) {
                continue;
            }
            foreach ($points as $point) {
                if (! is_array($point)) {
                    continue;
                }
                $pointMinor = isset($point['point_minor']) && is_numeric($point['point_minor'])
                    ? (int) $point['point_minor']
                    : 0;
                $pointDate = isset($point['date']) && is_string($point['date']) ? $point['date'] : '';
                if ($lowestMinor === null || $pointMinor < $lowestMinor) {
                    $lowestMinor = $pointMinor;
                    $lowestDate = $pointDate;
                    $lowestAccountId = $accountId;
                    $lowestAccountName = $accountName;
                }
            }
        }

        $nextIcsSettlement = $this->cardStatementQuery->nextSettlementForUser($user);

        return new ForecastHighlightsDto(
            userId: $user->id,
            lowestProjectedBalanceMinor: $lowestMinor,
            lowestProjectedBalanceDate: $lowestDate,
            lowestProjectedAccountId: $lowestAccountId,
            lowestProjectedAccountName: $lowestAccountName,
            activeShortfallCount: $shortfallCount,
            nextIcsSettlement: $nextIcsSettlement,
        );
    }
}
