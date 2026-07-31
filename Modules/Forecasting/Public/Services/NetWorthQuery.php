<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\FX\Public\Dto\ConversionResult;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\ValueObjects\Money;

/**
 * @see BalanceAnchorResolver
 */
final class NetWorthQuery
{
    use CoercesScalars;

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
        /** @var array{stale: bool, source: ?string, asOf: ?CarbonImmutable} $fxMeta */
        $fxMeta = ['stale' => false, 'source' => null, 'asOf' => null];

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
                $hasExcluded = true;
                $accountsWithoutRate++;

                continue;
            }

            $total += $result->converted->toMinor();
            $this->trackFxMetadata($fxMeta, $result);
        }

        return new NetWorth(
            totalMinor: $total,
            currency: $baseCurrency,
            accounts: $lines,
            hasExcludedAccounts: $hasExcluded,
            ratesSource: $fxMeta['source'],
            ratesAsOf: $fxMeta['asOf'] instanceof CarbonImmutable ? $fxMeta['asOf'] : null,
            hasStaleRates: $fxMeta['stale'],
            accountsWithoutRate: $accountsWithoutRate,
        );
    }

    /**
     * @param  array{stale: bool, source: ?string, asOf: ?CarbonImmutable}  $meta
     */
    private function trackFxMetadata(array &$meta, ConversionResult $result): void
    {
        // A passthrough (already-base-currency) line carries no rate,
        // source or as-of worth surfacing, so only non-passthrough
        // conversions feed the rollup metadata.
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
