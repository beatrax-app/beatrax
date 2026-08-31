<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\ConfidenceScale;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Internal\Enums\SettlementToleranceUsed;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Chains\Public\Enums\CardStatementCreditReason;
use Modules\Chains\Public\Enums\CardStatementState;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Support\SettlementTolerance;
use Modules\Chains\Public\Support\StatementDueDate;
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
final readonly class IcsSettlementResolver
{
    use CoercesScalars;

    private const int TRANSFER_CHUNK = 100;

    private const int CREDIT_CHUNK = 100;

    private const float EXCEEDED_CONFIDENCE_FLOOR = 0.6;

    private const float EXCEEDED_CONFIDENCE_CEILING = 0.99;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private CardStatementStateMachine $stateMachine,
        private ChainLinkInsertHelper $inserter,
        private ResolvesKnownCounterpartyIban $aliasResolver,
        private DismissChainLinkHint $dismissHint,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    public function resolveForUser(User $user): void
    {
        // A surplus is recorded before the statement that will absorb it
        // exists, so the credit is written with nowhere to point. Closing that
        // pointer here, ahead of the main pass, is what puts it inside
        // priorCreditsMinor()'s reach on the run the destination lands in.
        $this->attachDanglingCredits($user);

        // Only the ids are carried for the whole candidate set; the seven
        // columns each row needs come back a chunk at a time, and every read
        // is closed before the pass writes its first chain_link.
        $candidateIds = $this->candidateTransferIds($user);
        $ibans = $this->accountIbans($user);

        /** @var array<string, Account|null> $cardAccounts */
        $cardAccounts = [];

        foreach (array_chunk($candidateIds, self::TRANSFER_CHUNK) as $chunk) {
            foreach ($this->transfersById($chunk, $user) as $transfer) {
                $storedCounterpartyIban = self::toString($transfer->counterparty_iban ?? null);
                $counterpartyIban = $storedCounterpartyIban === ''
                    ? ''
                    : $this->codec->decryptValue('transactions', 'counterparty_iban', $storedCounterpartyIban, $user->id, ($this->session)())['value'];

                // A user has a handful of distinct counterparty IBANs and one
                // alias row each, so the three-query resolve runs once per
                // IBAN rather than once per transfer.
                if (! array_key_exists($counterpartyIban, $cardAccounts)) {
                    $cardAccounts[$counterpartyIban] = $this->cardAccountNamedBy($counterpartyIban, $user, $ibans);
                }
                $cardAccount = $cardAccounts[$counterpartyIban];

                if ($cardAccount === null) {
                    continue;
                }
                $this->resolveOne($transfer, $cardAccount, $user, $ibans);
            }
        }

        // Must follow the main pass: it only walks refunds inside statements
        // the main pass has already moved to settled/overpaid.
        $this->resolveRefundsAfterClose($user, $ibans);
    }

    // A card answers to two names and a settlement may carry either: an alias
    // row maps the institution's real IBAN onto the card's kind, and a card
    // whose statement arrives as a PDF carries a synthetic literal in its own
    // `iban` column. Reading only the alias left every such card unsettleable.
    /**
     * @param  array<int, string>  $ibans  account id to IBAN, read once per pass
     */
    private function cardAccountNamedBy(string $counterpartyIban, User $user, array $ibans): ?Account
    {
        if (trim($counterpartyIban) === '') {
            return null;
        }

        $account = $this->aliasResolver->resolveAccount($counterpartyIban, $user->id);
        if ($account === null) {
            $ownAccountId = array_search($counterpartyIban, $ibans, strict: true);
            $account = is_int($ownAccountId) ? Account::query()->find($ownAccountId) : null;
        }

        return $account !== null && $account->kind === AccountKind::IcsCard->value ? $account : null;
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
            // Only the card being settled is excluded. Pinning the payer to
            // `bank` made a statement paid from any other account kind
            // invisible to the whole pass, silently and forever.
            ->where('accounts.kind', '!=', AccountKind::IcsCard->value)
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
                'settled_currency as settled_currency',
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
     * @param  Account  $icsAccount  The ICS card the counterparty IBAN named — the
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
        $settledCurrency = self::currencyOrDefault($transfer->settled_currency ?? null);
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

        // Every term of the delta below is a bare minor unit, so they have to
        // be one currency or the sum is arithmetic on unlike quantities: a
        // USD 500.00 payment closed a EUR 500.00 statement to zero and
        // recorded an unaccounted delta of nothing.
        if ($settledCurrency !== $statementCurrency) {
            return;
        }

        $expenses = $this->pullExpenses($accountId, $periodStart, $periodEnd, $statementCurrency, $user);
        $priorCredits = $this->priorCreditsMinor($statementId, $statementCurrency, $user);

        $expenseSum = 0;
        foreach ($expenses as $expense) {
            /** @var stdClass $expense */
            $expenseSum += self::toInt($expense->settled_amount_minor ?? null);
        }

        // Expenses are negative settled amounts while $settled and the credits
        // are magnitudes, so this reads as paid + credits - owed: positive
        // delta = overpaid, negative = under, the convention the hint's
        // "unaccounted delta" line is written against.
        $delta = $expenseSum + $priorCredits + $settled;

        $tolerance = SettlementTolerance::minorFor($statementTotal);

        $signatureHash = self::signatureHash($ibans, $accountId, $periodEnd, $user);

        if (abs($delta) <= $tolerance) {
            $coveredCount = $expenses->count();
            if ($coveredCount === 0) {
                // With no expense to link, nothing records that this transfer
                // settled this statement: candidateTransferIds() excludes only
                // a transfer carrying a confirmed link, so applySettlement()
                // would subtract the same amount again on every later pass.
                return;
            }

            $toleranceUsed = abs($delta) <= SettlementTolerance::FLOOR_MINOR
                ? SettlementToleranceUsed::AmountFloor
                : SettlementToleranceUsed::Percent;
            $evidenceBase = [
                'statement_id' => $statementId,
                'unaccounted_delta_minor' => $delta,
                'tolerance_used' => $toleranceUsed->value,
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
                    'resolver' => ChainLinkResolver::Auto->value,
                    'evidence' => $evidenceBase,
                ];
            }
            // One transaction over all three writes: a crash after the links
            // is unrecoverable, because candidateTransferIds() then drops the
            // transfer for carrying a confirmed link and the statement it
            // never settled stays open forever.
            $connection->transaction(function () use ($connection, $links, $statementId, $settled, $priorCredits, $statementCurrency, $transferId, $user): void {
                $this->inserter->insertMissing($links, $user->id);

                $this->dismissStaleHint($transferId, $user);

                // CardStatementStateMachine is the only sanctioned mutator of
                // card_statements.state. It is told the credits too: the
                // tolerance test above already spent them, so told the payment
                // alone it left a paid-off statement reading half paid.
                $settlement = $this->stateMachine->applySettlement($statementId, $settled + $priorCredits, $user);

                if ($settlement->newState !== CardStatementState::Overpaid->value) {
                    return;
                }

                $now = $this->clock->now()->toDateTimeString();
                $connection->table('card_statement_credits')->insert([
                    'user_id' => $user->id,
                    'from_statement_id' => $statementId,
                    'to_statement_id' => null,
                    'amount_minor' => abs($settlement->newOpenMinor),
                    'currency' => $statementCurrency,
                    'reason' => CardStatementCreditReason::Overpayment->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

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
            'confidence' => ConfidenceScale::format($confidence),
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => [
                'statement_id' => $statementId,
                'unaccounted_delta_minor' => $delta,
                'tolerance_used' => SettlementToleranceUsed::Exceeded->value,
                'covered_count' => $expenses->count(),
                'credits_applied_minor' => $priorCredits,
                'signature_hash' => $signatureHash,
            ],
        ], $user->id);
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
                    ->whereRaw('transactions.posted_at BETWEEN date(card_statements.period_start) AND date(card_statements.period_end)')
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

        if ($links === []) {
            return;
        }

        // A refund's link and the credit it carries forward are one fact: the
        // link alone excludes the refund from the next pass, so the credit it
        // should have written is never reconsidered.
        $connection->transaction(function () use ($connection, $links, $credits, $user): void {
            $this->inserter->insertMissing($links, $user->id);
            foreach (array_chunk($credits, self::CREDIT_CHUNK) as $chunk) {
                $connection->table('card_statement_credits')->insert($chunk);
            }
        });
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
            ->whereBetween('posted_at', [self::periodDay($periodStart), self::periodDay($periodEnd)])
            ->orderByDesc('posted_at')
            ->first(['id']);

        if ($original === null) {
            // No candidate row: a NULL to_transaction_id is reserved for the
            // exceeded-tolerance case, so an unmatched refund stays unlinked.
            return null;
        }

        $originalId = self::toInt($original->id ?? null);

        $nextStatementId = $this->nextOpenStatementId($accountId, $periodEnd, null, $user);

        $signatureHash = self::signatureHash($ibans, $accountId, $periodEnd, $user);
        $now = $this->clock->now()->toDateTimeString();

        return [
            'link' => [
                'from_transaction_id' => $refundId,
                'to_transaction_id' => $originalId,
                'kind' => ChainLinkKind::IcsBulkSettle->value,
                'state' => ChainLinkState::Confirmed->value,
                'confidence' => '1.000',
                'resolver' => ChainLinkResolver::Auto->value,
                'evidence' => [
                    'statement_id' => $closedStatementId,
                    'unaccounted_delta_minor' => 0,
                    'tolerance_used' => SettlementToleranceUsed::RefundAfterClose->value,
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

    // An earlier pass wrote a hint saying this settlement did not add up; the
    // missing charges have arrived and it does. Left behind, the hint quoted a
    // shortfall against a statement this pass just settled. Routed through the
    // reader's own action so the peer gets the tombstone that sends.
    private function dismissStaleHint(int $transferId, User $user): void
    {
        $hintId = $this->db->connection()
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->where('from_transaction_id', $transferId)
            ->where('kind', ChainLinkKind::IcsBulkSettle->value)
            ->whereNull('to_transaction_id')
            ->value('id');

        if ($hintId === null) {
            return;
        }

        ($this->dismissHint)(self::toInt($hintId), $user);
    }

    // Binds on period alone, deliberately without an amount check, so an
    // out-of-tolerance transfer still lands as a candidate in resolveOne().
    private function findCandidateStatement(int $accountId, string $postedAt, User $user): ?stdClass
    {
        $connection = $this->db->connection();

        // The window is computed in PHP rather than with SQLite date() arithmetic,
        // so the query stays portable across every driver the app can run on.
        $posted = CarbonImmutable::parse($postedAt);
        [$printedStart, $printedEnd] = StatementDueDate::printedDueWindow($posted);
        [$derivedStart, $derivedEnd] = StatementDueDate::derivedDueWindow($posted);

        $rows = $connection
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
            // A statement is reached by the day it printed where it printed
            // one, and by the period it bills where it did not. The committed
            // statement's own deadline is twenty-four days past period_end, so
            // a window over period_end never reached the payment for it.
            ->where(static function ($group) use ($printedStart, $printedEnd, $derivedStart, $derivedEnd): void {
                /** @var QueryBuilder $group */
                $group
                    ->where(static function ($printed) use ($printedStart, $printedEnd): void {
                        /** @var QueryBuilder $printed */
                        $printed->whereNotNull('due_date')
                            ->whereBetween('due_date', [$printedStart, $printedEnd]);
                    })
                    ->orWhere(static function ($derived) use ($derivedStart, $derivedEnd): void {
                        /** @var QueryBuilder $derived */
                        $derived->whereNull('due_date')
                            ->whereBetween('period_end', [$derivedStart, $derivedEnd]);
                    });
            })
            ->orderBy('id')
            ->get([
                'id',
                'total_amount_minor',
                'open_balance_minor',
                'period_start',
                'period_end',
                'due_date',
                'currency',
            ]);

        // Compares seconds-precision distance rather than integer days —
        // day-truncation previously let orderBy('id') pick an older row
        // over one that was actually closer to the transfer's posted_at.
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $due = StatementDueDate::of(
                self::toStringOrNull($row->due_date ?? null),
                self::toString($row->period_end ?? null),
            );
            $distance = abs($due->diffInSeconds($posted, true));
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
    private function pullExpenses(int $accountId, string $periodStart, string $periodEnd, string $currency, User $user): Collection
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
            ->where('transactions.settled_currency', $currency)
            ->whereBetween('transactions.posted_at', [self::periodDay($periodStart), self::periodDay($periodEnd)])
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

    // The overpayment arm writes a credit with a NULL destination because the
    // statement that will absorb it has not been imported yet. Nothing else
    // ever closes that pointer, so until this pass ran the surplus was money
    // the reader had paid and no later statement could ever count.
    private function attachDanglingCredits(User $user): void
    {
        $connection = $this->db->connection();

        $dangling = $connection
            ->table('card_statement_credits as credit')
            ->join('card_statements as source', 'source.id', '=', 'credit.from_statement_id')
            ->where('credit.user_id', $user->id)
            ->whereNull('credit.to_statement_id')
            ->orderBy('credit.id')
            ->get([
                'credit.id as credit_id',
                'credit.currency as credit_currency',
                'source.account_id as account_id',
                'source.period_end as period_end',
            ]);

        /** @var array<string, int|null> $destinations */
        $destinations = [];
        /** @var array<int, list<int>> $creditIdsByStatement */
        $creditIdsByStatement = [];

        foreach ($dangling as $row) {
            /** @var stdClass $row */
            $accountId = self::toInt($row->account_id ?? null);
            $periodEnd = self::toString($row->period_end ?? null);
            $currency = self::currencyOrDefault($row->credit_currency ?? null);
            $key = $accountId.'|'.$periodEnd.'|'.$currency;

            if (! array_key_exists($key, $destinations)) {
                $destinations[$key] = $this->nextOpenStatementId($accountId, $periodEnd, $currency, $user);
            }
            if ($destinations[$key] === null) {
                continue;
            }
            $creditIdsByStatement[$destinations[$key]][] = self::toInt($row->credit_id ?? null);
        }

        $now = $this->clock->now()->toDateTimeString();
        foreach ($creditIdsByStatement as $statementId => $creditIds) {
            foreach (array_chunk($creditIds, self::CREDIT_CHUNK) as $chunk) {
                $connection->table('card_statement_credits')
                    ->where('user_id', $user->id)
                    ->whereIn('id', $chunk)
                    ->update([
                        'to_statement_id' => $statementId,
                        'updated_at' => $now,
                    ]);
            }
        }
    }

    // A NULL $currency takes whichever statement comes next; the carry-forward
    // pass names one, because priorCreditsMinor() sums only credits in the
    // statement's own currency and a destination in another money would strand
    // the surplus where nothing ever reconsiders it.
    private function nextOpenStatementId(int $accountId, string $afterPeriodEnd, ?string $currency, User $user): ?int
    {
        $query = $this->db->connection()
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', [CardStatementState::Open->value, CardStatementState::PartiallySettled->value])
            ->where('period_start', '>', $afterPeriodEnd)
            ->orderBy('period_start')
            ->orderBy('id');

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        $row = $query->first(['id']);

        return $row === null ? null : self::toInt($row->id ?? null);
    }

    // Predicated on the currency as well as the statement: a credit carried
    // forward in another currency is a different quantity, and summing it in
    // pushed a fully-paid statement out of tolerance and left it open.
    private function priorCreditsMinor(int $statementId, string $currency, User $user): int
    {
        $sum = $this->db->connection()
            ->table('card_statement_credits')
            ->where('user_id', $user->id)
            ->where('to_statement_id', $statementId)
            ->where('currency', $currency)
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

    // Comparison only: signatureHash() keeps the stored spelling, which every
    // install's chain_links already carry. The bounds are DATETIME and
    // posted_at is a DATE, so raw they drop the period's FIRST day --
    // '2026-04-17' >= '2026-04-17 00:00:00' is false as a string.
    private static function periodDay(string $value): string
    {
        return substr($value, 0, 10);
    }
}
