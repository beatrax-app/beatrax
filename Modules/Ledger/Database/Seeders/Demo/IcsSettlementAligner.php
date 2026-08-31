<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;

// A card settlement IS the statement it pays. The resolver confirms a bulk
// settle only where the unaccounted difference is inside EUR 5 or 2%, so the
// demo's flat 225.00 left three settlements adrift by 97.72, 605.95 and 649.28
// as the card's own rows grew past it — and /chains said "Balances exactly".
final class IcsSettlementAligner
{
    private const string BANK_SIDE = 'ICS afrekening MasterCard';

    private const string CARD_SIDE = 'Afrekening MasterCard ICS';

    public function align(User $user, Account $card): void
    {
        $periodStart = null;

        foreach ($this->settlements($user) as $settlement) {
            $charged = $this->chargedInPeriod($user, $card, $settlement, $periodStart);
            $periodStart = $settlement->posted_at;

            if ($charged !== 0) {
                $this->rewriteBothLegs($user, $settlement, $charged);
            }
        }
    }

    /** @return iterable<Transaction> */
    private function settlements(User $user): iterable
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('description', self::BANK_SIDE)
            ->orderBy('posted_at')
            ->get();
    }

    // The window the Chains seeder reads: every card charge falling after the
    // previous settlement and on or before this one. Counted in the currency
    // the settlement is denominated in, because the yen rows on this card are
    // billed in euro and only the settled leg speaks that.
    private function chargedInPeriod(User $user, Account $card, Transaction $settlement, ?CarbonImmutable $periodStart): int
    {
        return (int) Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('account_id', $card->id)
            ->where('type', TransactionType::Expense->value)
            ->where('settled_currency', $settlement->settled_currency)
            ->where('posted_at', '<=', $settlement->posted_at)
            ->when($periodStart !== null, static fn ($q) => $q->where('posted_at', '>', $periodStart))
            ->sum('settled_amount_minor');
    }

    // Both legs move together or the transfer stops balancing, and the card
    // side is the positive one. They are found by date and description: these
    // two carry no pair_transaction_id, which linkUser1Transfers sets for the
    // PayPal top-ups alone.
    private function rewriteBothLegs(User $user, Transaction $settlement, int $chargedMinor): void
    {
        $currency = $settlement->settled_currency;

        Transaction::query()
            ->where('id', $settlement->id)
            ->update(TransactionAmount::relate($chargedMinor, $currency, $chargedMinor, $currency)->toColumns());

        Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('description', self::CARD_SIDE)
            ->whereDate('posted_at', $settlement->posted_at->toDateString())
            ->update(TransactionAmount::relate(-$chargedMinor, $currency, -$chargedMinor, $currency)->toColumns());
    }
}
