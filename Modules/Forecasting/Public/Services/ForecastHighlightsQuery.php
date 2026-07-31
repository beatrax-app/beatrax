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
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ForecastHighlightsQuery
{
    private const int HORIZON_DAYS = 30;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CardStatementQuery $cardStatementQuery,
    ) {}

    public function activeShortfallCountForUser(User $user): int
    {
        $today = $this->clock->now()->startOfDay()->toDateString();
        $horizon = $this->clock->now()->startOfDay()->addDays(self::HORIZON_DAYS)->toDateString();

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
        // days. The forecast run carries every account's points in a
        // single result_json, so hoist the run lookup OUT of the
        // per-account loop (one query, not N).
        $lowestMinor = null;
        $lowestDate = null;
        $lowestAccountId = null;
        $lowestAccountName = null;

        $run = $this->db->connection()->table('forecast_runs')
            ->where('user_id', $user->id)
            ->whereNull('scenario_id')
            ->where('horizon_days', 30)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first(['result_json']);

        $accountsBlock = null;
        if ($run !== null) {
            /** @var stdClass $run */
            $rawJson = is_string($run->result_json ?? null) ? $run->result_json : '';
            if ($rawJson !== '') {
                $decoded = json_decode($rawJson, associative: true);
                if (is_array($decoded) && is_array($decoded['accounts'] ?? null)) {
                    /** @var array<int|string, mixed> $accountsBlock */
                    $accountsBlock = $decoded['accounts'];
                }
            }
        }

        if ($accountsBlock !== null) {
            $accounts = $this->db->connection()->table('accounts')
                ->where('user_id', $user->id)
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name']);

            foreach ($accounts as $accountRow) {
                /** @var stdClass $accountRow */
                $accountId = is_numeric($accountRow->id) ? (int) $accountRow->id : 0;
                $accountName = is_string($accountRow->name) ? $accountRow->name : '';

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
                    // Skip malformed rows entirely — a point without a
                    // string date contributes nothing to the "lowest on
                    // date X" surface; using '' as a sentinel let the
                    // tile render " on " with no date.
                    if (! is_string($point['date'] ?? null) || $point['date'] === '') {
                        continue;
                    }
                    $pointMinor = isset($point['point_minor']) && is_numeric($point['point_minor'])
                        ? (int) $point['point_minor']
                        : 0;
                    $pointDate = $point['date'];
                    if ($lowestMinor === null || $pointMinor < $lowestMinor) {
                        $lowestMinor = $pointMinor;
                        $lowestDate = $pointDate;
                        $lowestAccountId = $accountId;
                        $lowestAccountName = $accountName;
                    }
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
