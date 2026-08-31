<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Public\Dto\ForecastHighlightsDto;
use Modules\Forecasting\Public\Enums\ForecastHorizon;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

final readonly class ForecastHighlightsQuery
{
    // Not the projection horizon set — this is the one run the highlights
    // tile and the sidebar badge read, and it is a single day count.
    public const int TILE_HORIZON = ForecastHorizon::OneMonth->value;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CardStatementQuery $cardStatementQuery,
        private CrossCurrencyTotal $fx,
        private BaseCurrency $baseCurrency,
    ) {}

    public function activeShortfallCountForUser(User $user): int
    {
        $today = $this->clock->now()->startOfDay()->toDateString();
        $horizon = $this->clock->now()->startOfDay()->addDays(self::TILE_HORIZON)->toDateString();

        return $this->db->connection()->table('forecast_shortfall_windows')
            ->where('user_id', $user->id)
            ->whereNull('scenario_id')
            // The tile reads the 30-day run, so it counts the 30-day run's own
            // windows rather than whichever horizon last wrote the table.
            ->where('horizon_days', self::TILE_HORIZON)
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
            lowestProjectedBalanceCurrency: $lowest['currency'] ?? null,
            lowestProjectedBalanceDate: $lowest['date'] ?? null,
            lowestProjectedAccountId: $lowest['accountId'] ?? null,
            lowestProjectedAccountName: $lowest['accountName'] ?? null,
            activeShortfallCount: $shortfallCount,
            nextIcsSettlement: $nextIcsSettlement,
            // An imported statement stays open until it is settled, so a due
            // date that has passed is the ordinary case rather than the odd
            // one, and calling it "next" reads as a date still to come.
            icsSettlementOverdue: $nextIcsSettlement !== null
                && $nextIcsSettlement->dueDate->lessThan($this->clock->now()->startOfDay()),
        );
    }

    // One run holds every account's points in its result_json, so this loads
    // the run once rather than once per account. Each account's own dip is
    // found in the account's own currency, then the dips race each other in
    // the reader's — a JPY minor unit is not a euro cent.
    /**
     * @link ../../../../.docs/features/ledger/architecture.md#accountkind--which-kinds-hold-money
     *
     * @return array{balanceMinor: int, currency: string, date: string, accountId: int, accountName: string}|null
     */
    private function lowestProjectedBalance(User $user): ?array
    {
        $accountsBlock = $this->loadLatestAccountsBlock($user);
        if ($accountsBlock === null) {
            return null;
        }

        // Forward CASH, so only money the reader holds is in the race. A card
        // sits below zero for its whole life and a Play tally only descends,
        // so either wins it outright without naming a balance anyone can run
        // short of — which is why BufferFloor gives the card no floor either.
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereIn('kind', AccountKind::spendableValues())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'default_currency']);

        $baseCurrency = $this->baseCurrency->forUser($user);
        $candidates = $this->candidatesByAccount($accountsBlock, $accounts, $baseCurrency);
        $rates = $this->fx->ratesTo(
            array_map(static fn (array $candidate): string => $candidate['currency'], $candidates),
            $baseCurrency,
        );

        $lowest = null;
        $lowestInBase = null;

        foreach ($candidates as $candidate) {
            $inBase = $this->inBase($candidate['balanceMinor'], $candidate['currency'], $baseCurrency, $rates);
            if ($inBase === null) {
                continue;
            }

            if ($lowestInBase === null || $inBase < $lowestInBase) {
                $lowest = $candidate;
                $lowestInBase = $inBase;
            }
        }

        return $lowest;
    }

    /**
     * @param  array<int|string, mixed>  $accountsBlock
     * @param  Collection<int, stdClass>  $accounts
     * @return list<array{balanceMinor: int, currency: string, date: string, accountId: int, accountName: string}>
     */
    private function candidatesByAccount(array $accountsBlock, Collection $accounts, string $baseCurrency): array
    {
        $candidates = [];
        foreach ($accounts as $accountRow) {
            $accountId = is_numeric($accountRow->id) ? (int) $accountRow->id : 0;
            $accountName = is_string($accountRow->name) ? $accountRow->name : '';
            $accountCurrency = is_string($accountRow->default_currency) && $accountRow->default_currency !== ''
                ? $accountRow->default_currency
                : $baseCurrency;

            $candidate = $this->lowestForAccount($accountsBlock, $accountId, $accountName, $accountCurrency);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    // Null for a currency the rate table cannot reach, which drops the account
    // out of the race rather than letting its raw minor units win it.
    /**
     * @param  array<string, string>  $rates
     */
    private function inBase(int $minor, string $currency, string $baseCurrency, array $rates): ?int
    {
        $money = Money::tryOfMinor($minor, $currency);

        return $money === null ? null : $this->fx->convert($money, $baseCurrency, $rates)?->toMinor();
    }

    /**
     * @param  array<int|string, mixed>  $accountsBlock
     * @return array{balanceMinor: int, currency: string, date: string, accountId: int, accountName: string}|null
     */
    private function lowestForAccount(array $accountsBlock, int $accountId, string $accountName, string $accountCurrency): ?array
    {
        $lowest = null;
        foreach ($this->pointsForAccount($accountsBlock, $accountId) as $point) {
            $candidate = $this->pointMinorOnDate($point);
            if ($candidate === null) {
                continue;
            }
            [$pointMinor, $pointDate] = $candidate;
            if ($lowest === null || $pointMinor < $lowest['balanceMinor']) {
                $lowest = [
                    'balanceMinor' => $pointMinor,
                    'currency' => $this->pointCurrency($point, $accountCurrency),
                    'date' => $pointDate,
                    'accountId' => $accountId,
                    'accountName' => $accountName,
                ];
            }
        }

        return $lowest;
    }

    private function pointCurrency(mixed $point, string $accountCurrency): string
    {
        if (is_array($point) && is_string($point['currency'] ?? null) && $point['currency'] !== '') {
            return $point['currency'];
        }

        return $accountCurrency;
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
        // Dropped rather than defaulted: a '' date sentinel would let the tile
        // render "… on " with nothing after it.
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
            ->where('horizon_days', self::TILE_HORIZON)
            ->where('status', JobRunStatus::Complete->value)
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
