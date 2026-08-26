<?php

declare(strict_types=1);

namespace Modules\Chains\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

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
        $this->seedFundedByCardHintCandidate($user);
        $this->seedRefundOfHintCandidate($user);

        return $this->countDemoLinks($users);
    }

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
                kind: ChainLinkKind::PaypalFunding->value,
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
                kind: ChainLinkKind::IcsBulkSettle->value,
                evidence: [
                    'statement_period_end' => $settlement->posted_at->toDateString(),
                    'unaccounted_delta_minor' => 0,
                    'resolver_step' => 'demo-seed',
                ],
                confidence: '0.900',
            );
        }
    }

    private function seedFundedByCardHintCandidate(User $user): void
    {
        $expense = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', 'expense')
            ->where('description', 'COOLBLUE ROTTERDAM')
            ->orderBy('posted_at')
            ->first();

        if ($expense === null) {
            return;
        }

        $this->upsertHintCandidateLink(
            userId: $user->id,
            fromTransactionId: $expense->id,
            kind: ChainLinkKind::FundedByCardHint->value,
            evidence: [
                'card_last4' => '1234',
                'source_receipt' => 'demo-coolblue-receipt.eml',
                'resolver_step' => 'demo-seed',
            ],
            confidence: '0.700',
        );
    }

    private function seedRefundOfHintCandidate(User $user): void
    {
        $refund = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', 'refund')
            ->where('description', 'Retour Bol.com')
            ->orderBy('posted_at')
            ->first();

        if ($refund === null) {
            return;
        }

        $this->upsertHintCandidateLink(
            userId: $user->id,
            fromTransactionId: $refund->id,
            kind: ChainLinkKind::RefundOfHint->value,
            evidence: [
                'original_reference_id' => 'ORD-DEMO-99',
                'source_receipt' => 'demo-bol-refund-receipt.eml',
                'resolver_step' => 'demo-seed',
            ],
            confidence: '0.750',
        );
    }

    /**
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

    // No UNIQUE backs (user_id, from_transaction_id, kind); the seeder writes
    // one link per cycle, so the read-then-insert is enough here.
    /**
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
            'state' => ChainLinkState::Confirmed->value,
            'confidence' => $confidence,
            'resolver' => 'auto',
            'evidence' => $evidence,
        ]);
    }

    // Separate from upsertChainLink because the schema's NULL-endpoint guard
    // permits to_transaction_id = NULL only for candidate hint kinds.
    /**
     * @param  array<string, mixed>  $evidence
     */
    private function upsertHintCandidateLink(
        int $userId,
        int $fromTransactionId,
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
            'to_transaction_id' => null,
            'kind' => $kind,
            'state' => ChainLinkState::Candidate->value,
            'confidence' => $confidence,
            'resolver' => 'receipt_hint',
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
