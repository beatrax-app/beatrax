<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Public\Enums\CardStatementCreditReason;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/architecture/chain-resolution.md
 *
 * @internal Driven by ResolveChainLinksJob — not called from Public
 *           action classes directly.
 */
final class IcsSettlementResolver
{
    use CoercesScalars;

    // The tolerance is the larger of the two arms, not both applied.
    public const AMOUNT_TOLERANCE_MINOR = 500;

    public const AMOUNT_TOLERANCE_PERCENT = 2;

    public const PERIOD_WINDOW_DAYS = 10;

    public const SETTLED_TOLERANCE_MINOR = 1;

    private const EXCEEDED_CONFIDENCE_FLOOR = 0.6;

    private const EXCEEDED_CONFIDENCE_CEILING = 0.99;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CardStatementStateMachine $stateMachine,
        private readonly ChainLinkInsertHelper $inserter,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    public function resolveForUser(User $user): void
    {
        $connection = $this->db->connection();

        $candidateTransfers = $connection
            ->table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', ChainLinkKind::IcsBulkSettle->value)
                    ->where('chain_links.state', '=', ChainLinkState::Confirmed->value);
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', AccountKind::Bank->value)
            ->where('transactions.type', 'transfer_out')
            ->whereNotNull('transactions.counterparty_iban')
            ->whereNull('chain_links.id')
            ->orderBy('transactions.posted_at')
            ->get([
                'transactions.id as tx_id',
                'transactions.account_id as bank_account_id',
                'transactions.counterparty_iban as counterparty_iban',
                'transactions.settled_amount_minor as settled_amount_minor',
                'transactions.amount_minor as amount_minor',
                'transactions.posted_at as posted_at',
                'transactions.booked_at as booked_at',
            ]);

        foreach ($candidateTransfers as $transfer) {
            /** @var stdClass $transfer */
            $storedCounterpartyIban = self::toString($transfer->counterparty_iban ?? null);
            $counterpartyIban = $storedCounterpartyIban === ''
                ? ''
                : $this->codec->decryptValue('transactions', 'counterparty_iban', $storedCounterpartyIban, $user->id, ($this->session)())['value'];
            $aliasAccount = $this->aliasResolver->resolveAccount($counterpartyIban, $user->id);
            if ($aliasAccount === null || $aliasAccount->kind !== AccountKind::IcsCard->value) {
                // Other transfer_out shapes have their own resolvers.
                continue;
            }
            $this->resolveOne($transfer, $aliasAccount, $user);
        }

        // Must follow the main pass: it only walks refunds inside statements
        // the main pass has already moved to settled/overpaid.
        $this->resolveRefundsAfterClose($user);
    }

    /**
     * @param  stdClass  $transfer  Raw row from the candidate-transfer query;
     *                              settled_amount_minor is negative (ASN-side
     *                              money leaving), so the magnitude is taken
     *                              to match the algorithm's positive sign
     *                              convention.
     * @param  Account  $icsAccount  Alias-resolved ICS account — the
     *                               statement lookup runs against this
     *                               account, not the ASN account the
     *                               transfer_out sits on.
     */
    private function resolveOne(stdClass $transfer, Account $icsAccount, User $user): void
    {
        $connection = $this->db->connection();
        $transferId = self::toInt($transfer->tx_id ?? null);
        $accountId = $icsAccount->id;
        $settled = abs(self::toInt($transfer->settled_amount_minor ?? null));
        $postedAt = self::toString($transfer->posted_at ?? null);

        $statement = $this->findCandidateStatement($accountId, $postedAt, $user);
        if ($statement === null) {
            // The statement may roll in on a later import; the next pass retries.
            return;
        }

        $statementId = self::toInt($statement->id ?? null);
        $statementTotal = self::toInt($statement->total_amount_minor ?? null);
        $periodStart = self::toString($statement->period_start ?? null);
        $periodEnd = self::toString($statement->period_end ?? null);

        $expenses = $this->pullExpenses($accountId, $periodStart, $periodEnd, $user);
        $priorCredits = $this->priorCreditsMinor($statementId, $user);

        $expenseSum = 0;
        foreach ($expenses as $expense) {
            /** @var stdClass $expense */
            $expenseSum += self::toInt($expense->settled_amount_minor ?? null);
        }

        // Expenses and the statement total are negative settled amounts while
        // $settled is a magnitude; positive delta = overpaid, negative = under.
        $delta = -$expenseSum - $priorCredits - $settled;

        $absStatementTotal = abs($statementTotal);
        $percentTolerance = (int) floor($absStatementTotal * (self::AMOUNT_TOLERANCE_PERCENT / 100));
        $tolerance = max(self::AMOUNT_TOLERANCE_MINOR, $percentTolerance);

        $signatureHash = $this->signatureHash($accountId, $periodEnd, $user);

        if (abs($delta) <= $tolerance) {
            $toleranceUsed = abs($delta) <= self::AMOUNT_TOLERANCE_MINOR
                ? 'amount_5eur'
                : 'percent_2';

            $coveredCount = $expenses->count();
            $evidenceBase = [
                'statement_id' => $statementId,
                'unaccounted_delta_minor' => $delta,
                'tolerance_used' => $toleranceUsed,
                'covered_count' => $coveredCount,
                'credits_applied_minor' => $priorCredits,
                'signature_hash' => $signatureHash,
            ];

            foreach ($expenses as $expense) {
                /** @var stdClass $expense */
                $this->inserter->insertIfNotExists([
                    'from_transaction_id' => $transferId,
                    'to_transaction_id' => self::toInt($expense->id ?? null),
                    'kind' => ChainLinkKind::IcsBulkSettle->value,
                    'state' => ChainLinkState::Confirmed->value,
                    'confidence' => '1.000',
                    'resolver' => 'auto',
                    'evidence' => $evidenceBase,
                ], $user);
            }

            // CardStatementStateMachine is the only sanctioned mutator of
            // card_statements.state.
            $settlement = $this->stateMachine->applySettlement($statementId, $settled, $user);

            if ($settlement->newState === CardStatementState::Overpaid->value) {
                $surplus = abs($settlement->newOpenMinor);
                $now = $this->clock->now()->toDateTimeString();
                $connection->table('card_statement_credits')->insert([
                    'user_id' => $user->id,
                    'from_statement_id' => $statementId,
                    'to_statement_id' => null,
                    'amount_minor' => $surplus,
                    'reason' => CardStatementCreditReason::Overpayment->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return;
        }

        // The NULL to_transaction_id is legal only for this exceeded-tolerance
        // candidate — the chain_links NULL-endpoint trigger rejects every other.
        $confidence = $this->computeExceededConfidence($delta, $statementTotal);
        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $transferId,
            'to_transaction_id' => null,
            'kind' => ChainLinkKind::IcsBulkSettle->value,
            'state' => ChainLinkState::Candidate->value,
            'confidence' => $this->formatConfidence($confidence),
            'resolver' => 'auto',
            'evidence' => [
                'statement_id' => $statementId,
                'unaccounted_delta_minor' => $delta,
                'tolerance_used' => 'exceeded',
                'covered_count' => $expenses->count(),
                'credits_applied_minor' => $priorCredits,
                'signature_hash' => $signatureHash,
            ],
        ], $user);
    }

    private function resolveRefundsAfterClose(User $user): void
    {
        $connection = $this->db->connection();

        $refunds = $connection
            ->table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->join('card_statements', function ($join): void {
                /** @var JoinClause $join */
                $join->on('card_statements.account_id', '=', 'transactions.account_id')
                    ->on('card_statements.user_id', '=', 'transactions.user_id')
                    ->whereRaw('transactions.posted_at BETWEEN card_statements.period_start AND card_statements.period_end')
                    ->whereIn('card_statements.state', [CardStatementState::Settled->value, CardStatementState::Overpaid->value]);
            })
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', ChainLinkKind::IcsBulkSettle->value);
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', AccountKind::IcsCard->value)
            ->where('transactions.type', 'refund')
            ->whereNull('chain_links.id')
            ->get([
                'transactions.id as refund_id',
                'transactions.account_id as account_id',
                'transactions.settled_amount_minor as settled_amount_minor',
                'transactions.posted_at as posted_at',
                'transactions.counterparty_normalized as counterparty_normalized',
                'card_statements.id as statement_id',
                'card_statements.period_start as period_start',
                'card_statements.period_end as period_end',
            ]);

        foreach ($refunds as $refund) {
            /** @var stdClass $refund */
            $this->resolveOneRefund($refund, $user);
        }
    }

    private function resolveOneRefund(stdClass $refund, User $user): void
    {
        $connection = $this->db->connection();
        $refundId = self::toInt($refund->refund_id ?? null);
        $accountId = self::toInt($refund->account_id ?? null);
        $refundAmount = self::toInt($refund->settled_amount_minor ?? null);
        $closedStatementId = self::toInt($refund->statement_id ?? null);
        $periodStart = self::toString($refund->period_start ?? null);
        $periodEnd = self::toString($refund->period_end ?? null);
        $merchant = self::toString($refund->counterparty_normalized ?? null);

        $original = $connection
            ->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->where('type', 'expense')
            ->where('counterparty_normalized', $merchant)
            ->where('settled_amount_minor', -$refundAmount)
            ->whereBetween('posted_at', [$periodStart, $periodEnd])
            ->orderByDesc('posted_at')
            ->first(['id']);

        if ($original === null) {
            // No candidate row: a NULL to_transaction_id is reserved for the
            // exceeded-tolerance case, so an unmatched refund stays unlinked.
            return;
        }

        $originalId = self::toInt($original->id ?? null);

        $nextStatement = $connection
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
            ->where('period_start', '>', $periodEnd)
            ->orderBy('period_start')
            ->first(['id']);

        $nextStatementId = $nextStatement !== null
            ? self::toInt($nextStatement->id ?? null)
            : null;

        $signatureHash = $this->signatureHash($accountId, $periodEnd, $user);

        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $refundId,
            'to_transaction_id' => $originalId,
            'kind' => ChainLinkKind::IcsBulkSettle->value,
            'state' => ChainLinkState::Confirmed->value,
            'confidence' => '1.000',
            'resolver' => 'auto',
            'evidence' => [
                'statement_id' => $closedStatementId,
                'unaccounted_delta_minor' => 0,
                'tolerance_used' => CardStatementCreditReason::RefundAfterClose->value,
                'covered_count' => 1,
                'credits_applied_minor' => 0,
                'signature_hash' => $signatureHash,
                'reason' => CardStatementCreditReason::RefundAfterClose->value,
            ],
        ], $user);

        $now = $this->clock->now()->toDateTimeString();
        $connection->table('card_statement_credits')->insert([
            'user_id' => $user->id,
            'from_statement_id' => $closedStatementId,
            'to_statement_id' => $nextStatementId,
            'amount_minor' => abs($refundAmount),
            'reason' => CardStatementCreditReason::RefundAfterClose->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Binds on period alone, deliberately without an amount check, so an
    // out-of-tolerance transfer still lands as a candidate in resolveOne().
    private function findCandidateStatement(int $accountId, string $postedAt, User $user): ?stdClass
    {
        $connection = $this->db->connection();

        // Computed in PHP, not SQLite date() arithmetic, to stay driver-portable.
        $posted = CarbonImmutable::parse($postedAt);
        $windowStart = $posted->subDays(self::PERIOD_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $posted->addDays(self::PERIOD_WINDOW_DAYS)->endOfDay()->toDateTimeString();

        $rows = $connection
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
            ->whereBetween('period_end', [$windowStart, $windowEnd])
            ->orderBy('id')
            ->get([
                'id',
                'total_amount_minor',
                'open_balance_minor',
                'period_start',
                'period_end',
            ]);

        // Compares seconds-precision distance rather than integer days —
        // day-truncation previously let orderBy('id') pick an older row
        // over one that was actually closer to the transfer's posted_at.
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $periodEnd = CarbonImmutable::parse(self::toString($row->period_end ?? null));
            $distance = abs($periodEnd->diffInSeconds($posted, true));
            if ($distance < $bestDistance) {
                $best = $row;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @return Collection<int, stdClass>
     */
    private function pullExpenses(int $accountId, string $periodStart, string $periodEnd, User $user): Collection
    {
        return $this->db->connection()
            ->table('transactions')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.to_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', ChainLinkKind::IcsBulkSettle->value)
                    ->where('chain_links.state', '=', ChainLinkState::Confirmed->value);
            })
            ->where('transactions.user_id', $user->id)
            ->where('transactions.account_id', $accountId)
            ->where('transactions.type', 'expense')
            ->whereBetween('transactions.posted_at', [$periodStart, $periodEnd])
            ->whereNull('chain_links.id')
            ->orderBy('transactions.posted_at')
            ->get([
                'transactions.id',
                'transactions.settled_amount_minor',
                'transactions.posted_at',
                'transactions.counterparty_normalized',
            ]);
    }

    private function priorCreditsMinor(int $statementId, User $user): int
    {
        $sum = $this->db->connection()
            ->table('card_statement_credits')
            ->where('user_id', $user->id)
            ->where('to_statement_id', $statementId)
            ->sum('amount_minor');

        return self::toInt($sum);
    }

    private function signatureHash(int $accountId, string $periodEnd, User $user): string
    {
        $iban = $this->db->connection()
            ->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->value('iban');

        return hash(
            'sha256',
            self::toString($iban).'|'.$periodEnd.'|user='.$user->id,
        );
    }

    private function computeExceededConfidence(int $delta, int $statementTotal): float
    {
        $base = abs($statementTotal);
        if ($base <= 0) {
            return self::EXCEEDED_CONFIDENCE_FLOOR;
        }
        $raw = 1.0 - abs($delta) / $base;

        return max(self::EXCEEDED_CONFIDENCE_FLOOR, min(self::EXCEEDED_CONFIDENCE_CEILING, $raw));
    }

    // Matches the chain_links.confidence decimal(4,3) column shape.
    private function formatConfidence(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
