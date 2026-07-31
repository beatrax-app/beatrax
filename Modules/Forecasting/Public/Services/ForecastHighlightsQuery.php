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
        $lowest = $this->lowestProjectedBalance($user);
        $nextIcsSettlement = $this->cardStatementQuery->nextSettlementForUser($user);

        return new ForecastHighlightsDto(
            userId: $user->id,
            lowestProjectedBalanceMinor: $lowest['balanceMinor'] ?? null,
            lowestProjectedBalanceDate: $lowest['date'] ?? null,
            lowestProjectedAccountId: $lowest['accountId'] ?? null,
            lowestProjectedAccountName: $lowest['accountName'] ?? null,
            activeShortfallCount: $shortfallCount,
            nextIcsSettlement: $nextIcsSettlement,
        );
    }

    // Lowest projected balance across all accounts in the next 30 days.
    // The forecast run carries every account's points in a single
    // result_json, so the run is loaded once (not once per account).
    /**
     * @return array{balanceMinor: int, date: string, accountId: int, accountName: string}|null
     */
    private function lowestProjectedBalance(User $user): ?array
    {
        $accountsBlock = $this->loadLatestAccountsBlock($user);
        if ($accountsBlock === null) {
            return null;
        }

        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $lowest = null;
        foreach ($accounts as $accountRow) {
            /** @var stdClass $accountRow */
            $accountId = is_numeric($accountRow->id) ? (int) $accountRow->id : 0;
            $accountName = is_string($accountRow->name) ? $accountRow->name : '';
            $lowest = $this->lowestForAccount($accountsBlock, $accountId, $accountName, $lowest);
        }

        return $lowest;
    }

    /**
     * @param  array<int|string, mixed>  $accountsBlock
     * @param  array{balanceMinor: int, date: string, accountId: int, accountName: string}|null  $lowest
     * @return array{balanceMinor: int, date: string, accountId: int, accountName: string}|null
     */
    private function lowestForAccount(array $accountsBlock, int $accountId, string $accountName, ?array $lowest): ?array
    {
        foreach ($this->pointsForAccount($accountsBlock, $accountId) as $point) {
            $candidate = $this->pointMinorOnDate($point);
            if ($candidate === null) {
                continue;
            }
            [$pointMinor, $pointDate] = $candidate;
            if ($lowest === null || $pointMinor < $lowest['balanceMinor']) {
                $lowest = [
                    'balanceMinor' => $pointMinor,
                    'date' => $pointDate,
                    'accountId' => $accountId,
                    'accountName' => $accountName,
                ];
            }
        }

        return $lowest;
    }

    /**
     * @param  array<int|string, mixed>  $accountsBlock
     * @return array<int, mixed>
     */
    private function pointsForAccount(array $accountsBlock, int $accountId): array
    {
        $accountResult = $accountsBlock[(string) $accountId] ?? $accountsBlock[$accountId] ?? null;
        if (! is_array($accountResult) || ! is_array($accountResult['points'] ?? null)) {
            return [];
        }

        return array_values($accountResult['points']);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function pointMinorOnDate(mixed $point): ?array
    {
        // Skip malformed rows entirely — a point without a string date
        // contributes nothing to the "lowest on date X" surface; a ''
        // sentinel would let the tile render " on " with no date.
        if (! is_array($point) || ! is_string($point['date'] ?? null) || $point['date'] === '') {
            return null;
        }

        $pointMinor = isset($point['point_minor']) && is_numeric($point['point_minor'])
            ? (int) $point['point_minor']
            : 0;

        return [$pointMinor, $point['date']];
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function loadLatestAccountsBlock(User $user): ?array
    {
        $run = $this->db->connection()->table('forecast_runs')
            ->where('user_id', $user->id)
            ->whereNull('scenario_id')
            ->where('horizon_days', self::HORIZON_DAYS)
            ->where('status', 'complete')
            ->orderByDesc('id')
            ->first(['result_json']);

        $rawJson = ($run instanceof stdClass && is_string($run->result_json ?? null)) ? $run->result_json : '';
        if ($rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, associative: true);
        if (! is_array($decoded) || ! is_array($decoded['accounts'] ?? null)) {
            return null;
        }

        return $decoded['accounts'];
    }
}
