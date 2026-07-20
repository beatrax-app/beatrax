<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @see BalanceAnchorResolver
 */
final class NetWorthQuery
{
    private const EXCLUDED_KINDS = ['paypal_funding'];

    public function __construct(
        private readonly BalanceAnchorResolver $anchor,
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
    ) {}

    public function forUser(User $user): NetWorth
    {
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', self::EXCLUDED_KINDS)
            ->orderBy('id')
            ->get(['id', 'name', 'kind']);

        $lines = [];
        $total = 0;
        $hasExcluded = false;
        $accountsWithoutRate = 0;
        $hasStaleRates = false;
        $latestSource = null;
        $latestAsOf = null;

        $baseCurrency = $user->base_currency;

        foreach ($accounts as $account) {
            $accountId = self::toInt($account->id);
            $anchor = $this->anchor->forAccount($accountId, $user);
            $kind = is_string($account->kind) ? $account->kind : '';

            // Run the conversion first so the account line can carry the
            // real rate/source/as-of — not just the native amount.
            $money = Money::ofMinor($anchor->openingBalanceMinor, $anchor->currency);
            $result = $this->fx->convertToBase($money, $baseCurrency);

            // No rate available — result currency stays native. The line
            // is still emitted (the breakdown shows a no-rate note) but
            // carries no base equivalent and is left out of the total.
            $rateAvailable = $result->converted->currency() === $baseCurrency;

            $lines[] = new AccountBalanceLine(
                accountId: $accountId,
                name: is_string($account->name) ? $account->name : '',
                kind: $kind,
                balanceMinor: $anchor->openingBalanceMinor,
                currency: $anchor->currency,
                isLiability: $kind === 'ics_card',
                baseEquivalentMinor: $rateAvailable && ! $result->isPassthrough
                    ? $result->converted->toMinor()
                    : null,
                fxRate: $result->rate,
                fxSource: $result->source,
                fxAsOf: $result->asOf,
                fxIsStale: $result->isStale,
            );

            if (! $rateAvailable) {
                $hasExcluded = true;
                $accountsWithoutRate++;

                continue;
            }

            $total += $result->converted->toMinor();

            // Track FX metadata from the latest non-passthrough conversion
            // only — a passthrough (already-base-currency) line carries no
            // rate/source/as-of worth surfacing.
            if (! $result->isPassthrough) {
                $hasStaleRates = $hasStaleRates || $result->isStale;

                if ($result->source !== null) {
                    $latestSource = $result->source;
                }

                if ($result->asOf !== null) {
                    if ($latestAsOf === null || $result->asOf > $latestAsOf) {
                        $latestAsOf = $result->asOf;
                    }
                }
            }
        }

        return new NetWorth(
            totalMinor: $total,
            currency: $baseCurrency,
            accounts: $lines,
            hasExcludedAccounts: $hasExcluded,
            ratesSource: $latestSource,
            ratesAsOf: $latestAsOf instanceof CarbonImmutable ? $latestAsOf : null,
            hasStaleRates: $hasStaleRates,
            accountsWithoutRate: $accountsWithoutRate,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
