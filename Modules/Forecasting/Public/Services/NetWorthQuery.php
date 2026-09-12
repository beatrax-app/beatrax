<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Dto\RateUsed;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\AccountBalance;
use Modules\Ledger\Public\ValueObjects\Money;
use stdClass;

// Net worth is what the reader holds today, so it reads the ledger balance as
// of today rather than the forecast anchor. One account can hold several
// currencies, so it yields one breakdown line per currency and converts each
// at its own rate; a currency with no rate is listed and left out of the total.
/**
 * @link ../../../../.docs/features/reports/architecture.md
 */
final readonly class NetWorthQuery
{
    use CoercesScalars;

    public function __construct(
        private AccountBalanceQuery $balances,
        private Clock $clock,
        private DatabaseManager $db,
        private ExchangeRateService $fx,
        private BaseCurrency $baseCurrency,
    ) {}

    public function forUser(User $user): NetWorth
    {
        // Not an inclusion list: a kind this build has never heard of is far
        // likelier to be an account the reader holds than a mirror of one, and
        // dropping it would take a real balance off the roll-up in silence.
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', AccountKind::mirrorValues())
            ->orderBy('id')
            ->get(['id', 'name', 'kind', 'default_currency']);

        $lines = [];
        $total = 0;
        $hasExcluded = false;
        $balancesWithoutRate = 0;

        $baseCurrency = $this->baseCurrency->forUser($user);
        $today = $this->clock->now()->startOfDay();
        $rates = RateSet::empty($baseCurrency);

        foreach ($accounts as $account) {
            ['lines' => $accountLines, 'total' => $accountTotal, 'withoutRate' => $withoutRate]
                = $this->accountLines($account, $user, $baseCurrency, $today, $rates);

            $lines = [...$lines, ...$accountLines];
            $total += $accountTotal;
            $balancesWithoutRate += $withoutRate;
            $hasExcluded = $hasExcluded || $withoutRate > 0;
        }

        // The oldest leg answers for the total; the metadata used to be folded
        // newest-first, so a stale leg was reported under a fresh pair's date.
        // No unconverted codes here: this card counts the LINES it left out,
        // which is a different number from the distinct currencies.
        $disclosure = ConversionDisclosure::of($rates);

        return new NetWorth(
            totalMinor: $total,
            currency: $baseCurrency,
            accounts: $lines,
            hasExcludedAccounts: $hasExcluded,
            ratesSource: $disclosure->source(),
            ratesAsOf: $disclosure->asOf(),
            hasStaleRates: $disclosure->isStale(),
            balancesWithoutRate: $balancesWithoutRate,
            conversion: $disclosure,
        );
    }

    // One account can hold several currencies, so it yields one line per
    // currency held, each converted at its own rate.
    /**
     * @return array{lines: list<AccountBalanceLine>, total: int, withoutRate: int}
     */
    private function accountLines(
        stdClass $account,
        User $user,
        string $baseCurrency,
        CarbonImmutable $today,
        RateSet &$rates,
    ): array {
        $accountId = self::toInt($account->id);
        $kind = is_string($account->kind) ? $account->kind : '';
        $name = is_string($account->name) ? $account->name : '';
        $defaultCurrency = self::toString($account->default_currency);
        if ($defaultCurrency === '') {
            $defaultCurrency = $baseCurrency;
        }

        $held = $this->heldLines(
            $this->balances->currentBalanceAsOf($accountId, $user, $today),
            $defaultCurrency,
        );

        $lines = [];
        $total = 0;
        $withoutRate = 0;

        foreach ($held as $currency => $balanceMinor) {
            $result = $this->fx->convertToBase(Money::ofMinor($balanceMinor, $currency), $baseCurrency);

            // With no rate the conversion returns the native currency untouched.
            // Such a line is still listed in the breakdown, but left out of the total.
            $rateAvailable = $result->converted->currency() === $baseCurrency;

            $lines[] = new AccountBalanceLine(
                accountId: $accountId,
                name: $name,
                kind: $kind,
                balanceMinor: $balanceMinor,
                currency: $currency,
                isLiability: AccountKind::tryFrom($kind)?->isLiability() === true,
                baseEquivalentMinor: $rateAvailable && ! $result->isPassthrough
                    ? $result->converted->toMinor()
                    : null,
                fxRate: $result->rate,
                fxSource: $result->source,
                fxAsOf: $result->asOf,
                fxIsStale: $result->isStale,
            );

            if (! $rateAvailable) {
                $withoutRate++;

                continue;
            }

            $total += $result->converted->toMinor();
            $rates = self::withRateUsed($rates, $baseCurrency, $currency, $result);
        }

        return ['lines' => $lines, 'total' => $total, 'withoutRate' => $withoutRate];
    }

    // An account with nothing on it yet still belongs in the breakdown, at
    // zero in the currency it is denominated in, so an empty balance becomes
    // one line rather than none.
    /**
     * @return array<string, int>
     */
    private function heldLines(AccountBalance $balance, string $defaultCurrency): array
    {
        $lines = $balance->lines();

        return $lines === [] ? [$defaultCurrency => 0] : $lines;
    }

    // A passthrough line was already in the base currency, so it converted at
    // no rate and has nothing to disclose. Two accounts holding one currency
    // converted at one rate, so the set collapses them.
    private static function withRateUsed(RateSet $rates, string $baseCurrency, string $currency, ConversionResult $result): RateSet
    {
        if ($result->isPassthrough || $result->rate === null) {
            return $rates;
        }

        return $rates->with(new RateUsed(
            from: $currency,
            to: $baseCurrency,
            rate: $result->rate,
            source: $result->source,
            asOf: $result->asOf,
            isStale: $result->isStale,
        ));
    }
}
