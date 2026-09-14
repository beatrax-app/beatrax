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

        Transaction::query()
            ->where('id', $settlement->id)
            ->update(TransactionAmount::relate($chargedMinor, $currency, $chargedMinor, $currency)->toColumns());

        $cardLeg = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('source_ref', 'like', DemoTransactionRef::IcsSettlementCardSide->pattern())
            ->whereDate('posted_at', $settlement->posted_at->toDateString());

        $cardLegIds = $cardLeg->clone()->pluck('id')->all();

        $cardLeg->update(TransactionAmount::relate(-$chargedMinor, $currency, -$chargedMinor, $currency)->toColumns());

        // amount_minor is part of the fingerprint tuple and a builder update
        // writes it without re-deriving the digest. Left alone, both legs of
        // every aligned settlement key values they no longer hold, and a
        // re-import of the same statement would book a second copy of each.
        $this->restamp([$settlement->id, ...$cardLegIds]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function restamp(array $ids): void
    {
        foreach (Transaction::query()->whereIn('id', $ids)->get() as $row) {
            Transaction::query()->where('id', $row->id)->update([
                'fingerprint' => $this->fingerprints->composeTuple(new FingerprintTuple(
                    userId: (int) $row->user_id,
                    accountId: (int) $row->account_id,
                    postedAtDate: substr((string) $row->getRawOriginal('posted_at'), 0, 10),
                    bookedAtDateTime: (string) $row->getRawOriginal('booked_at'),
                    amountMinor: (int) $row->amount_minor,
                    currency: (string) $row->currency,
                    counterpartyNormalized: (string) $row->counterparty_normalized,
                    occurrenceOrdinal: (int) $row->occurrence_ordinal,
                )),
            ]);
        }
    }
}
