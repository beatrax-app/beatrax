<?php

declare(strict_types=1);

namespace Modules\Chains\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

final class DemoChainsSeeder
{
    public function __construct(
        private readonly ChainLinkInsertHelper $inserter,
    ) {}

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<string, Account>>  $accounts
     */
    public function run(array $users, array $accounts): int
    {
        if (! isset($users['demo-1'], $accounts['demo-1'])) {
            return $this->countDemoLinks($users);
        }

        $user = $users['demo-1'];

        $this->seedPaypalFundingChain($user);
        $this->seedIcsBulkSettleChain($user, $accounts['demo-1']);
        $this->seedFundedByCardHintCandidate($user);
        $this->seedRefundOfHintCandidate($user);
        $this->seedRefundOfCandidate($user);

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

            $this->seedTwoEndedLink(
                userId: $user->id,
                settlementTransactionId: $funder->id,
                legTransactionId: $charge->id,
                kind: ChainLinkKind::PaypalFunding,
                state: ChainLinkState::Confirmed,
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

            $this->seedTwoEndedLink(
                userId: $user->id,
                settlementTransactionId: $settlement->id,
                legTransactionId: $expense->id,
                kind: ChainLinkKind::IcsBulkSettle,
                state: ChainLinkState::Confirmed,
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

        $this->seedHintCandidate(
            userId: $user->id,
            fromTransactionId: $expense->id,
            kind: ChainLinkKind::FundedByCardHint,
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

        $this->seedHintCandidate(
            userId: $user->id,
            fromTransactionId: $refund->id,
            kind: ChainLinkKind::RefundOfHint,
            evidence: [
                'original_reference_id' => 'ORD-DEMO-99',
                'source_receipt' => 'demo-bol-refund-receipt.eml',
                'resolver_step' => 'demo-seed',
            ],
            confidence: '0.750',
        );
    }

    // The only seeded row /chains/review and its badge can see: both filter a
    // NULL to-side out as unactionable, and every other candidate here is a
    // one-ended hint. The partial amount is why it is a candidate and not a
    // confirm.
    private function seedRefundOfCandidate(User $user): void
    {
        $refund = $this->demoTransactionByDescription($user, 'refund', 'Retour Coolblue');
        $charge = $this->demoTransactionByDescription($user, 'expense', 'COOLBLUE ROTTERDAM');

        if ($refund === null || $charge === null) {
            return;
        }

        $this->seedTwoEndedLink(
            userId: $user->id,
            settlementTransactionId: $charge->id,
            legTransactionId: $refund->id,
            kind: ChainLinkKind::RefundOfHint,
            state: ChainLinkState::Candidate,
            evidence: [
                'original_reference_id' => 'ORD-DEMO-41',
                'amount_matched_minor' => abs($refund->amount_minor),
                'resolver_step' => 'demo-seed',
            ],
            confidence: '0.620',
        );
    }

    private function demoTransactionByDescription(User $user, string $type, string $description): ?Transaction
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('type', $type)
            ->where('description', $description)
            ->orderBy('posted_at')
            ->first();
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

    // Named by role, not by column: the caller says which row is the
    // settlement and which is the leg, and the kind decides which endpoint
    // each becomes. Seeded the other way round, /chains drew one card per ICS
    // charge, each claiming the whole settlement.
    /**
     * @param  array<string, mixed>  $evidence
     */
    private function seedTwoEndedLink(
        int $userId,
        int $settlementTransactionId,
        int $legTransactionId,
        ChainLinkKind $kind,
        ChainLinkState $state,
        array $evidence,
        string $confidence,
    ): void {
        $settlementIsFrom = $kind->settlementIsFromSide();

        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $settlementIsFrom ? $settlementTransactionId : $legTransactionId,
            'to_transaction_id' => $settlementIsFrom ? $legTransactionId : $settlementTransactionId,
            'kind' => $kind->value,
            'state' => $state->value,
            'confidence' => $confidence,
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => $evidence,
        ], $userId);
    }

    // Separate from seedConfirmedLink because the schema's NULL-endpoint guard
    // permits to_transaction_id = NULL only for candidate hint kinds, which
    // have no settlement side to place.
    /**
     * @param  array<string, mixed>  $evidence
     */
    private function seedHintCandidate(
        int $userId,
        int $fromTransactionId,
        ChainLinkKind $kind,
        array $evidence,
        string $confidence,
    ): void {
        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $fromTransactionId,
            'to_transaction_id' => null,
            'kind' => $kind->value,
            'state' => ChainLinkState::Candidate->value,
            'confidence' => $confidence,
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => $evidence,
        ], $userId);
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
