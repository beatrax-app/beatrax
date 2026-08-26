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
use Modules\FX\Public\Dto\ConversionResult;
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
final class NetWorthQuery
{
    use CoercesScalars;

    private const EXCLUDED_KINDS = [AccountKind::PaypalFunding->value];

    public function __construct(
        private readonly AccountBalanceQuery $balances,
        private readonly Clock $clock,
        private readonly DatabaseManager $db,
        private readonly ExchangeRateService $fx,
        private readonly BaseCurrency $baseCurrency,
    ) {}

    public function forUser(User $user): NetWorth
    {
        $accounts = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->whereNotIn('kind', self::EXCLUDED_KINDS)
            ->orderBy('id')
            ->get(['id', 'name', 'kind', 'default_currency']);

        $lines = [];
        $total = 0;
        $hasExcluded = false;
        $balancesWithoutRate = 0;
        /** @var array{stale: bool, source: ?string, asOf: ?CarbonImmutable} $fxMeta */
        $fxMeta = ['stale' => false, 'source' => null, 'asOf' => null];

        $baseCurrency = $this->baseCurrency->forUser($user);
        $today = $this->clock->now()->startOfDay();

        foreach ($accounts as $account) {
            ['lines' => $accountLines, 'total' => $accountTotal, 'withoutRate' => $withoutRate]
                = $this->accountLines($account, $user, $baseCurrency, $today, $fxMeta);

            $lines = [...$lines, ...$accountLines];
            $total += $accountTotal;
            $balancesWithoutRate += $withoutRate;
            $hasExcluded = $hasExcluded || $withoutRate > 0;
        }

        return new NetWorth(
            totalMinor: $total,
            currency: $baseCurrency,
            accounts: $lines,
            hasExcludedAccounts: $hasExcluded,
            ratesSource: $fxMeta['source'],
            ratesAsOf: $fxMeta['asOf'] instanceof CarbonImmutable ? $fxMeta['asOf'] : null,
            hasStaleRates: $fxMeta['stale'],
            balancesWithoutRate: $balancesWithoutRate,
        );
    }

    // One account can hold several currencies, so it yields one line per
    // currency held, each converted at its own rate.
    /**
     * @param  array{stale: bool, source: ?string, asOf: ?CarbonImmutable}  $fxMeta
     * @return array{lines: list<AccountBalanceLine>, total: int, withoutRate: int}
     */
    private function accountLines(
        stdClass $account,
        User $user,
        string $baseCurrency,
        CarbonImmutable $today,
        array &$fxMeta,
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
                isLiability: $kind === AccountKind::IcsCard->value,
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
            $this->trackFxMetadata($fxMeta, $result);
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

    /**
     * @param  array{stale: bool, source: ?string, asOf: ?CarbonImmutable}  $meta
     */
    private function trackFxMetadata(array &$meta, ConversionResult $result): void
    {
        // A passthrough line was already in the base currency, so it has no rate,
        // source or as-of to contribute.
        if ($result->isPassthrough) {
            return;
        }

        $meta['stale'] = $meta['stale'] || $result->isStale;
        if ($result->source !== null) {
            $meta['source'] = $result->source;
        }
        if ($result->asOf !== null && ($meta['asOf'] === null || $result->asOf > $meta['asOf'])) {
            $meta['asOf'] = $result->asOf;
        }
    }
}
