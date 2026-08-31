<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\ValueObjects\AccountBalance;
use Modules\Pots\Public\Dto\ReconciliationRow;
use Modules\Pots\Public\Enums\PotStatus;
use stdClass;

// Both halves are per-currency lines, because minor units of two currencies do
// not add. `accounts.default_currency` is mutable and `pots.currency` frozen, so
// an account holds pots in a currency it no longer reports: one sum read
// "allocated ¥270.000" for pots holding EUR 2.700,00.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam
 */
final readonly class PotAllocationLedger
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private AccountBalanceQuery $accountBalance,
        private BaseCurrency $baseCurrency,
        private Clock $clock,
    ) {}

    // The account's own denomination first, then every other currency it still
    // holds pots in, so a relabelled account answers for all of them instead of
    // printing one line's figure under another line's sign.
    /**
     * @return list<ReconciliationRow>
     */
    public function rows(int $accountId, User $user): array
    {
        $account = $this->accountRow($accountId, $user);
        $held = $this->held($accountId, $user);
        $allocated = $this->allocated($accountId, $user);
        $covered = self::covered($this->currencyOf($account), $allocated);
        $leftOut = self::leftOut($held, $allocated, $covered);

        $rows = [];
        foreach ($covered as $currency) {
            $rows[] = self::build($accountId, self::nameOf($account), $currency, $held, $allocated, $leftOut);
        }

        return $rows;
    }

    // One currency's line. Null asks for the account's own denomination, which
    // is what a reader creating a pot there is weighed against; a caller holding
    // a pot passes the pot's, which is the only currency its balance is in.
    public function row(int $accountId, User $user, ?string $currency): ReconciliationRow
    {
        $account = $this->accountRow($accountId, $user);
        $code = $currency ?? $this->currencyOf($account);
        $held = $this->held($accountId, $user);
        $allocated = $this->allocated($accountId, $user);

        return self::build($accountId, self::nameOf($account), $code, $held, $allocated, self::leftOut($held, $allocated, [$code]));
    }

    public function unallocated(int $accountId, User $user, ?string $currency): int
    {
        return $this->row($accountId, $user, $currency)->unallocatedMinor;
    }

    public function accountCurrency(int $accountId, User $user): string
    {
        return $this->currencyOf($this->accountRow($accountId, $user));
    }

    // Only money the account already holds can be put in an envelope. Counting
    // a future-dated row made a pot read as funded by a payment still to
    // arrive, and left isOverAllocated false while the account could not cover
    // what its pots claimed.
    private function held(int $accountId, User $user): AccountBalance
    {
        return $this->accountBalance
            ->currentBalanceAsOf($accountId, $user, $this->clock->now()->startOfDay());
    }

    // Grouped on the currency of the movements AND of the pots they belong to:
    // the first is what the minor units are denominated in, the second gives a
    // pot nobody has funded yet a zero line rather than no line, so its currency
    // still reconciles.
    private function allocated(int $accountId, User $user): AccountBalance
    {
        $connection = $this->db->connection();

        $rows = $connection->table('pots')
            ->leftJoin('pot_movements', static function (JoinClause $join) use ($user): void {
                $join->on('pot_movements.pot_id', '=', 'pots.id')
                    ->where('pot_movements.user_id', '=', $user->id);
            })
            ->where('pots.user_id', $user->id)
            ->where('pots.account_id', $accountId)
            ->where('pots.status', PotStatus::Active->value)
            ->groupBy('pots.currency', 'pot_movements.currency')
            ->get([
                'pots.currency AS pot_currency',
                'pot_movements.currency AS movement_currency',
                $connection->raw('COALESCE(SUM(pot_movements.amount_minor), 0) AS allocated_minor'),
            ]);

        $lines = [];
        foreach ($rows as $row) {
            $potCurrency = self::toString($row->pot_currency);
            $movementCurrency = self::toStringOrNull($row->movement_currency) ?? '';
            $code = $movementCurrency !== '' ? $movementCurrency : $potCurrency;

            $lines[$potCurrency] ??= 0;
            $lines[$code] = ($lines[$code] ?? 0) + self::toInt($row->allocated_minor);
        }

        return AccountBalance::of($lines);
    }

    /**
     * @return list<string>
     */
    private static function covered(string $accountCurrency, AccountBalance $allocated): array
    {
        $covered = [$accountCurrency];
        foreach (array_keys($allocated->lines()) as $currency) {
            if ($currency !== $accountCurrency) {
                $covered[] = $currency;
            }
        }

        return $covered;
    }

    // Named rather than dropped, so the header can say what it left out: a
    // currency the account holds that no row above answers for.
    /**
     * @param  list<string>  $covered
     * @return list<string>
     */
    private static function leftOut(AccountBalance $held, AccountBalance $allocated, array $covered): array
    {
        $codes = [];
        foreach ([$held->lines(), $allocated->lines()] as $lines) {
            foreach ($lines as $currency => $minor) {
                if ($minor !== 0 && ! in_array($currency, $covered, true)) {
                    $codes[$currency] = true;
                }
            }
        }

        $left = array_keys($codes);
        sort($left);

        return $left;
    }

    /**
     * @param  list<string>  $leftOut
     */
    private static function build(
        int $accountId,
        string $accountName,
        string $currency,
        AccountBalance $held,
        AccountBalance $allocated,
        array $leftOut,
    ): ReconciliationRow {
        $real = $held->in($currency);
        $allocatedMinor = $allocated->in($currency);
        $unallocated = $real - $allocatedMinor;

        return new ReconciliationRow(
            accountId: $accountId,
            accountName: $accountName,
            currency: $currency,
            realBalanceMinor: $real,
            allocatedMinor: $allocatedMinor,
            unallocatedMinor: $unallocated,
            isOverAllocated: $unallocated < 0,
            unconverted: $leftOut,
        );
    }

    private function accountRow(int $accountId, User $user): ?stdClass
    {
        return $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['name', 'default_currency']);
    }

    private function currencyOf(?stdClass $account): string
    {
        $currency = $account === null ? null : self::toStringOrNull($account->default_currency);

        return ($currency === null || $currency === '') ? $this->baseCurrency->code() : $currency;
    }

    private static function nameOf(?stdClass $account): string
    {
        return $account === null ? '' : self::toString($account->name);
    }
}
