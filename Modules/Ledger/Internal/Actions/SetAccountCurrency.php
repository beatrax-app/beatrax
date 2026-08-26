<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Internal\Exceptions\AccountCurrencyRelabelWarning;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/ledger/architecture.md#changing-an-accounts-currency
 */
final readonly class SetAccountCurrency
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private AccountBalanceQuery $balances,
    ) {}

    public function __invoke(int $accountId, User $user, string $currency, bool $allowRelabel = false): void
    {
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first(['id', 'default_currency', 'opening_balance_minor', 'starting_balance_minor']);

        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }

        /** @var stdClass $account */
        $current = self::toString($account->default_currency);

        // The <select> narrows the choice; nothing enforces it. A tampered
        // Livewire payload arrives here as any three bytes, and an account
        // denominated in a code the rate table does not know drops out of
        // every converted roll-up instead of failing where it was chosen.
        $known = $this->db->connection()->table('currencies')->where('code', $currency)->exists();
        if (! $known) {
            throw new InvalidArgumentException(Lang::get('ledger::account_currency.errors.unknown'));
        }

        if ($currency === $current) {
            return;
        }

        if (! $allowRelabel) {
            $this->warnIfTheAccountAlreadyHoldsSomething($accountId, $user, $account, $current, $currency);
        }

        $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->update([
                'default_currency' => $currency,
                'updated_at' => $this->clock->now()->toDateTimeString(),
            ]);
    }

    // Nothing stored is rewritten, so the only thing that moves is which line
    // the account reports: the baseline is relabelled where it stands and rows
    // keep the currency they were booked in. An account holding neither has
    // nothing to warn about.
    private function warnIfTheAccountAlreadyHoldsSomething(
        int $accountId,
        User $user,
        stdClass $account,
        string $current,
        string $currency,
    ): void {
        // A baseline of zero is not a figure that can be misread under a new
        // label, so it counts as no baseline here even though it is a stored
        // value everywhere else.
        $baselineMinor = match (true) {
            is_numeric($account->opening_balance_minor) => self::toInt($account->opening_balance_minor),
            is_numeric($account->starting_balance_minor) => self::toInt($account->starting_balance_minor),
            default => null,
        };
        $baselineMinor = $baselineMinor === 0 ? null : $baselineMinor;

        $hasRows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->exists();

        if ($baselineMinor === null && ! $hasRows) {
            return;
        }

        throw new AccountCurrencyRelabelWarning(
            fromCurrency: $current,
            toCurrency: $currency,
            baselineMinor: $baselineMinor,
            linesByCurrency: $this->balances->currentBalance($accountId, $user)->lines(),
        );
    }
}
