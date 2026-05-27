<?php

declare(strict_types=1);

namespace Modules\Chains\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

/**
 * Builds two pre-resolved chain examples for the primary demo user so
 * the `/chains` review surface has data to render against:
 *
 *   1. **PayPal → ASN funding chain.** For each of the three monthly
 *      Bol.com PayPal purchases the demo dataset emits, a
 *      `paypal_funding` ChainLink rows from the downstream Bol.com
 *      expense to the ASN→PayPal top-up that funded it on the same
 *      date. Mirrors the production PayPal funding-chain resolver
 *      output for a "user buys on Bol.com, PayPal pulled from ASN
 *      bank account" flow.
 *
 *   2. **ICS → ASN bulk settlement chain.** For each ICS card
 *      transaction, an `ics_bulk_settle` ChainLink rows from the
 *      downstream ICS expense to the ASN→ICS bulk-settlement
 *      transfer_out posted at the end of the same statement period.
 *      Captures the real-world ICS billing pattern: many small card
 *      charges are paid by one large monthly transfer from the bank
 *      account, and the chain link is how the dashboard rolls the
 *      bulk row's underlying detail back into per-merchant lines.
 *
 * Idempotency: every link upserts via `updateOrCreate` keyed on the
 * `(user_id, from_transaction_id, kind)` composite — re-running the
 * seeder returns the same chain_links row without issuing a second
 * INSERT. The schema's chain_links_state_check trigger pair enforces
 * `state ∈ {candidate, confirmed, rejected}`; the trigger pair on
 * `kind` enforces `paypal_funding|ics_bulk_settle`. Demo rows ship as
 * `state = confirmed, resolver = auto` so the review surface treats
 * them like resolver-emitted rows the user has not re-evaluated yet.
 */
final class DemoChainsSeeder
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        if (! isset($users['demo-1@beatrax.local'], $accounts['demo-1@beatrax.local'])) {
            return $this->countDemoLinks($users);
        }

        $user = $users['demo-1@beatrax.local'];

        $this->seedPaypalFundingChain($user);
        $this->seedIcsBulkSettleChain($user, $accounts['demo-1@beatrax.local']);

        return $this->countDemoLinks($users);
    }

    /**
     * Each monthly Bol.com PayPal purchase is funded by the ASN→PayPal
     * top-up that posted on the same date — the canonical PayPal
     * funding chain. The seeder finds the (downstream, funder) pair
     * by description match (the demo transactions use stable
     * descriptions, so the lookup is deterministic).
     */
    private function seedPaypalFundingChain(User $user): void
    {
        $bolPaypalCharges = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', 'expense')
            ->where('description', 'Bol.com via PayPal')
            ->orderBy('posted_at')
            ->get();

        foreach ($bolPaypalCharges as $charge) {
            $funder = Transaction::query()
                ->where('user_id', $user->id)
                ->where('source_format', 'demo')
                ->where('type', 'transfer_out')
                ->where('description', 'PayPal top-up')
                ->whereDate('posted_at', $charge->posted_at->toDateString())
                ->first();

            if ($funder === null) {
                continue;
            }

            $this->upsertChainLink(
                userId: $user->id,
                fromTransactionId: $charge->id,
                toTransactionId: $funder->id,
                kind: 'paypal_funding',
                evidence: [
                    'amount_matched_minor' => abs($charge->amount_minor),
                    'date_offset_days' => 0,
                    'resolver_step' => 'demo-seed',
                ],
                confidence: '0.950',
            );
        }
    }

    /**
     * Every ICS card expense in the window is settled by the next
     * ASN→ICS bulk transfer that posted at or after its booking.
     * Builds the `ics_bulk_settle` chain links for every (downstream,
     * funder) pair the data supports.
     *
     * @param  array<string, Account>  $userAccounts
     */
    private function seedIcsBulkSettleChain(User $user, array $userAccounts): void
    {
        if (! isset($userAccounts['ics-demo-1'])) {
            return;
        }

        $icsAccount = $userAccounts['ics-demo-1'];

        $icsExpenses = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('account_id', $icsAccount->id)
            ->where('type', 'expense')
            ->orderBy('posted_at')
            ->get();

        $settlements = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', 'transfer_out')
            ->where('description', 'ICS afrekening MasterCard')
            ->orderBy('posted_at')
            ->get();

        foreach ($icsExpenses as $expense) {
            $settlement = $this->nextSettlementAfter($settlements, $expense->posted_at);
            if ($settlement === null) {
                continue;
            }

            $this->upsertChainLink(
                userId: $user->id,
                fromTransactionId: $expense->id,
                toTransactionId: $settlement->id,
                kind: 'ics_bulk_settle',
                evidence: [
                    'statement_period_end' => $settlement->posted_at->toDateString(),
                    'unaccounted_delta_minor' => 0,
                    'resolver_step' => 'demo-seed',
                ],
                confidence: '0.900',
            );
        }
    }

    /**
     * Return the first settlement whose posted_at is at or after the
     * expense's posted_at. Iterating in-memory is cheap because the
     * demo window only carries three settlements.
     *
     * @param  iterable<Transaction>  $settlements
     */
    private function nextSettlementAfter(iterable $settlements, CarbonImmutable $after): ?Transaction
    {
        foreach ($settlements as $settlement) {
            if ($settlement->posted_at->greaterThanOrEqualTo($after)) {
                return $settlement;
            }
        }

        return null;
    }

    /**
     * Idempotent chain-link upsert. Keys on `(user_id,
     * from_transaction_id, kind)` so a second seed run reuses the
     * existing row rather than inserting a duplicate. The schema
     * does not enforce a UNIQUE on that triple — the resolver in
     * production may produce multiple candidate links per downstream
     * row — but the demo seeder produces only one per cycle, so the
     * (downstream, kind) tuple is a stable identity key.
     *
     * @param  array<string, mixed>  $evidence
     */
    private function upsertChainLink(
        int $userId,
        int $fromTransactionId,
        int $toTransactionId,
        string $kind,
        array $evidence,
        string $confidence,
    ): void {
        $existing = $this->db->connection()
            ->table('chain_links')
            ->where('user_id', $userId)
            ->where('from_transaction_id', $fromTransactionId)
            ->where('kind', $kind)
            ->first();

        if ($existing !== null) {
            return;
        }

        ChainLink::query()->create([
            'user_id' => $userId,
            'from_transaction_id' => $fromTransactionId,
            'to_transaction_id' => $toTransactionId,
            'kind' => $kind,
            'state' => 'confirmed',
            'confidence' => $confidence,
            'resolver' => 'auto',
            'evidence' => $evidence,
        ]);
    }

    /**
     * @param  array<string, User>  $users
     */
    private function countDemoLinks(array $users): int
    {
        return ChainLink::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }
}
