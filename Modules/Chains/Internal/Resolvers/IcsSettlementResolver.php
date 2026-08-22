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
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Enums\TransactionType;
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

    public const AMOUNT_TOLERANCE_MINOR = 500;

    public const AMOUNT_TOLERANCE_PERCENT = 2;

    public const PERIOD_WINDOW_DAYS = 10;

    public const SETTLED_TOLERANCE_MINOR = 1;

    private const TRANSFER_CHUNK = 100;

    private const CREDIT_CHUNK = 100;

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
        // Only the ids are carried for the whole candidate set; the seven
        // columns each row needs come back a chunk at a time, and every read
        // is closed before the pass writes its first chain_link.
        $candidateIds = $this->candidateTransferIds($user);
        $ibans = $this->accountIbans($user);

        /** @var array<string, Account|null> $aliasAccounts */
        $aliasAccounts = [];

        foreach (array_chunk($candidateIds, self::TRANSFER_CHUNK) as $chunk) {
            foreach ($this->transfersById($chunk, $user) as $transfer) {
                $storedCounterpartyIban = self::toString($transfer->counterparty_iban ?? null);
                $counterpartyIban = $storedCounterpartyIban === ''
                    ? ''
                    : $this->codec->decryptValue('transactions', 'counterparty_iban', $storedCounterpartyIban, $user->id, ($this->session)())['value'];

                // A user has a handful of distinct counterparty IBANs and one
                // alias row each, so the three-query resolve runs once per
                // IBAN rather than once per transfer.
                if (! array_key_exists($counterpartyIban, $aliasAccounts)) {
                    $aliasAccounts[$counterpartyIban] = $this->aliasResolver->resolveAccount($counterpartyIban, $user->id);
                }
                $aliasAccount = $aliasAccounts[$counterpartyIban];

                if ($aliasAccount === null || $aliasAccount->kind !== AccountKind::IcsCard->value) {
                    continue;
                }
                $this->resolveOne($transfer, $aliasAccount, $user, $ibans);
            }
        }

        // Must follow the main pass: it only walks refunds inside statements
        // the main pass has already moved to settled/overpaid.
        $this->resolveRefundsAfterClose($user, $ibans);
    }

    /**
     * @return list<int> in posted_at order, id breaking a tie, which is the
     *                   order each transfer claims the period's expenses in
     */
    private function candidateTransferIds(User $user): array
    {
        $rows = $this->db->connection()
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
            ->where('transactions.type', TransactionType::TransferOut->value)
            ->whereNotNull('transactions.counterparty_iban')
            ->whereNull('chain_links.id')
            ->orderBy('transactions.posted_at')
            ->orderBy('transactions.id')
            ->get(['transactions.id as tx_id']);

        $ids = [];
        foreach ($rows as $row) {
            $id = self::toInt($row->tx_id ?? null);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $ids
     * @return list<stdClass>
     */
    private function transfersById(array $ids, User $user): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->get([
                'id as tx_id',
                'account_id as bank_account_id',
                'counterparty_iban as counterparty_iban',
                'settled_amount_minor as settled_amount_minor',
                'amount_minor as amount_minor',
                'posted_at as posted_at',
                'booked_at as booked_at',
            ]);

        $byId = [];
        foreach ($rows as $row) {
            $byId[self::toInt($row->tx_id ?? null)] = $row;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    // The signature hash names the account by its IBAN, and the refund pass
    // names it again; one read covers every account the user owns.
    /**
     * @return array<int, string>
     */
    private function accountIbans(User $user): array
    {
        $rows = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'iban']);

        $ibans = [];
        foreach ($rows as $row) {
            $ibans[self::toInt($row->id ?? null)] = self::toString($row->iban ?? null);
        }

        return $ibans;
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
     * @param  array<int, string>  $ibans  account id to IBAN, read once per pass
     */
    private function resolveOne(stdClass $transfer, Account $icsAccount, User $user, array $ibans): void
    {
        $connection = $this->db->connection();
        $transferId = self::toInt($transfer->tx_id ?? null);
        $accountId = $icsAccount->id;
        $settled = abs(self::toInt($transfer->settled_amount_minor ?? null));
        $postedAt = self::toString($transfer->posted_at ?? null);

        $statement = $this->findCandidateStatement($accountId, $postedAt, $user);
        if ($statement === null) {
            return;
        }

        $statementId = self::toInt($statement->id ?? null);
        $statementTotal = self::toInt($statement->total_amount_minor ?? null);
        $periodStart = self::toString($statement->period_start ?? null);
        $periodEnd = self::toString($statement->period_end ?? null);
        $statementCurrency = self::currencyOrDefault($statement->currency ?? null);

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

        $signatureHash = self::signatureHash($ibans, $accountId, $periodEnd, $user);

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

            // Flushed here rather than at the end of the pass: pullExpenses()
            // skips an expense that already carries a confirmed link, so the
            // next transfer must see what this one just claimed.
            $links = [];
            foreach ($expenses as $expense) {
                $links[] = [
                    'from_transaction_id' => $transferId,
                    'to_transaction_id' => self::toInt($expense->id ?? null),
                    'kind' => ChainLinkKind::IcsBulkSettle->value,
                    'state' => ChainLinkState::Confirmed->value,
                    'confidence' => '1.000',
                    'resolver' => 'auto',
                    'evidence' => $evidenceBase,
                ];
            }
            $this->inserter->insertMissing($links, $user);

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
                    'currency' => $statementCurrency,
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

    /**
     * @param  array<int, string>  $ibans
     */
    private function resolveRefundsAfterClose(User $user, array $ibans): void
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
            ->where('transactions.type', TransactionType::Refund->value)
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
                'card_statements.currency as statement_currency',
            ]);

        // Every matched refund contributes one link and one credit, so the
        // pass costs two writes rather than two per refund.
        $links = [];
        $credits = [];
        foreach ($refunds as $refund) {
            $resolved = $this->resolveOneRefund($refund, $user, $ibans);
            if ($resolved === null) {
                continue;
            }
            $links[] = $resolved['link'];
            $credits[] = $resolved['credit'];
        }

        $this->inserter->insertMissing($links, $user);
        foreach (array_chunk($credits, self::CREDIT_CHUNK) as $chunk) {
            $connection->table('card_statement_credits')->insert($chunk);
        }
    }

    /**
     * @param  array<int, string>  $ibans
     * @return array{link: array<string, mixed>, credit: array<string, mixed>}|null
     */
    private function resolveOneRefund(stdClass $refund, User $user, array $ibans): ?array
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
            ->where('type', TransactionType::Expense->value)
            ->where('counterparty_normalized', $merchant)
            ->where('settled_amount_minor', -$refundAmount)
            ->whereBetween('posted_at', [$periodStart, $periodEnd])
            ->orderByDesc('posted_at')
            ->first(['id']);

        if ($original === null) {
            // No candidate row: a NULL to_transaction_id is reserved for the
            // exceeded-tolerance case, so an unmatched refund stays unlinked.
            return null;
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

        $signatureHash = self::signatureHash($ibans, $accountId, $periodEnd, $user);
        $now = $this->clock->now()->toDateTimeString();

        return [
            'link' => [
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
            ],
            'credit' => [
                'user_id' => $user->id,
                'from_statement_id' => $closedStatementId,
                'to_statement_id' => $nextStatementId,
                'amount_minor' => abs($refundAmount),
                'currency' => self::currencyOrDefault($refund->statement_currency ?? null),
                'reason' => CardStatementCreditReason::RefundAfterClose->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    // Binds on period alone, deliberately without an amount check, so an
    // out-of-tolerance transfer still lands as a candidate in resolveOne().
    private function findCandidateStatement(int $accountId, string $postedAt, User $user): ?stdClass
    {
        $connection = $this->db->connection();

        // The window is computed in PHP rather than with SQLite date() arithmetic,
        // so the query stays portable across every driver the app can run on.
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
                'currency',
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
            ->where('transactions.type', TransactionType::Expense->value)
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

    // A credit is denominated in the statement it came off, never in
    // whatever the reading side would otherwise have assumed.
    private static function currencyOrDefault(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? $currency : Currency::Eur->value;
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

    /**
     * @param  array<int, string>  $ibans
     */
    private static function signatureHash(array $ibans, int $accountId, string $periodEnd, User $user): string
    {
        return hash(
            'sha256',
            ($ibans[$accountId] ?? '').'|'.$periodEnd.'|user='.$user->id,
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

    private function formatConfidence(float $value): string
    {
        return number_format($value, 3, '.', '');
    }
}
