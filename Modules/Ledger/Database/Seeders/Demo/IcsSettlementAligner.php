<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;

// A card settlement IS the statement it pays. The resolver confirms a bulk
// settle only where the unaccounted difference is inside EUR 5 or 2%, so the
// demo's flat 225.00 left three settlements adrift by 97.72, 605.95 and 649.28
// as the card's own rows grew past it — and /chains said "Balances exactly".
final class IcsSettlementAligner
{
    public function __construct(private readonly FingerprintComposer $fingerprints) {}

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
            ->where('source_ref', 'like', DemoTransactionRef::IcsSettlementBankSide->pattern())
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
    // side is the positive one. They are found by date and seeded tag: these
    // two carry no pair_transaction_id, which linkUser1Transfers sets for the
    // PayPal top-ups alone.
    private function rewriteBothLegs(User $user, Transaction $settlement, int $chargedMinor): void
    {
        $currency = $settlement->settled_currency;

        $this->rewrite([$settlement->id], $chargedMinor, $currency);

        $cardLegIds = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('source_ref', 'like', DemoTransactionRef::IcsSettlementCardSide->pattern())
            ->whereDate('posted_at', $settlement->posted_at->toDateString())
            ->pluck('id')
            ->all();

        $this->rewrite($cardLegIds, -$chargedMinor, $currency);
    }

    // The amount is one of the eight columns the digest is composed over, so
    // it is composed here and written in the same statement: a row is never
    // left keying a figure it no longer holds, not even between two writes.
    // The tuple reads the columns being written, so it describes exactly them.
    /**
     * @param  list<int>  $ids
     */
    private function rewrite(array $ids, int $amountMinor, string $currency): void
    {
        $columns = TransactionAmount::relate($amountMinor, $currency, $amountMinor, $currency)->toColumns();

        foreach (Transaction::query()->whereIn('id', $ids)->get() as $row) {
            Transaction::query()->where('id', $row->id)->update($columns + [
                'fingerprint' => $this->fingerprints->composeTuple(new FingerprintTuple(
                    userId: (int) $row->user_id,
                    accountId: (int) $row->account_id,
                    postedAtDate: substr((string) $row->getRawOriginal('posted_at'), 0, 10),
                    bookedAtDateTime: (string) $row->getRawOriginal('booked_at'),
                    amountMinor: $columns['amount_minor'],
                    currency: $columns['currency'],
                    counterpartyNormalized: (string) $row->counterparty_normalized,
                    occurrenceOrdinal: (int) $row->occurrence_ordinal,
                )),
            ]);
        }
    }
}
