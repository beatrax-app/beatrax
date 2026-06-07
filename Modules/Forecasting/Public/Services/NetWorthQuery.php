<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Pipeline\BalanceAnchorResolver;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;

/**
 * Net-worth roll-up across all of a user's accounts. Each account's current
 * balance is the same anchor the forecast uses as "today's balance"
 * (BalanceAnchorResolver), which is already sign-correct — bank/PayPal balances
 * are positive, a credit card's is negative (amount owed) — so net worth is
 * simply the sum, no per-kind sign juggling.
 *
 * Multi-currency: there is no balance FX-conversion service yet, so the single
 * `totalMinor` sums only EUR-denominated accounts; any non-EUR account is still
 * listed in the breakdown and flagged via `hasExcludedAccounts`. `paypal_funding`
 * is an internal routing construct, not a balance-holding account, so it is
 * excluded.
 */
final class NetWorthQuery
{
    private const EXCLUDED_KINDS = ['paypal_funding'];

    public function __construct(
        private readonly BalanceAnchorResolver $anchor,
        private readonly DatabaseManager $db,
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

        foreach ($accounts as $account) {
            $accountId = self::toInt($account->id);
            $anchor = $this->anchor->forAccount($accountId, $user);
            $kind = is_string($account->kind) ? $account->kind : '';

            $lines[] = new AccountBalanceLine(
                accountId: $accountId,
                name: is_string($account->name) ? $account->name : '',
                kind: $kind,
                balanceMinor: $anchor->openingBalanceMinor,
                currency: $anchor->currency,
                isLiability: $kind === 'ics_card',
            );

            if ($anchor->currency === 'EUR') {
                $total += $anchor->openingBalanceMinor;
            } else {
                $hasExcluded = true;
            }
        }

        return new NetWorth(
            totalMinor: $total,
            currency: 'EUR',
            accounts: $lines,
            hasExcludedAccounts: $hasExcluded,
        );
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
