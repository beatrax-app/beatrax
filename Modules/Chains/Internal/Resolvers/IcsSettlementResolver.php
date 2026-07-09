<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Ledger\Models\Account;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * Decomposes the ASN-side `transfer_out` that funds an ICS bulk-iDEAL
 * settlement into the per-expense `chain_links` rows that explain
 * which ICS purchases the bulk settlement actually paid.
 *
 * ## Algorithm
 *
 * The ICS-side leg of an ASN→ICS iDEAL settlement exists ONLY as a
 * statement-level `card_statements` row, not as a per-row transaction:
 * the Mijn ICS PDF does not carry a per-row settlement entry — the
 * bulk settlement only exists on the ASN side as a single
 * `transfer_out` against the ICS institution's IBAN
 * (NL08ABNA0526650664). The resolver therefore iterates the ASN-side
 * `transfer_out` rows directly and uses the alias bridge
 * (`ResolvesKnownCounterpartyIban`) to find the matching ICS account,
 * then matches against the open `card_statements` on that account:
 *
 *   For each unresolved ASN-side `transfer_out` T whose
 *   counterparty_iban resolves via `ResolvesKnownCounterpartyIban` to
 *   an `ics_card`-kind Account A:
 *     1. Find a candidate open / partially_settled `card_statements`
 *        row S on A within ±PERIOD_WINDOW_DAYS of T.posted_at.
 *     2. Pull every `expense` row E inside S's period on A that
 *        doesn't yet carry a confirmed ics_bulk_settle chain_link.
 *     3. Subtract any prior credit carried into S from
 *        `card_statement_credits`.
 *     4. Compute the unaccounted delta:
 *          delta = sum(E.settled) - prior_credits - T.settled_magnitude
 *        (the magnitude is taken because T.settled_amount_minor is
 *        negative on the ASN side — money leaving — while the
 *        algorithm operates in the positive ICS-side sign convention).
 *     5. If |delta| ≤ tolerance — write N confirmed chain_links (one
 *        per E_i) and call the state machine to transition the card
 *        statement. If the resulting state is `overpaid`, emit a
 *        card_statement_credits row of reason='overpayment'.
 *     6. Else — write ONE candidate chain_link with to_transaction_id
 *        NULL and evidence.tolerance_used='exceeded' for the review
 *        queue to surface.
 *
 *   After the main pass, walk every `refund` row that posted inside a
 *   closed (settled / overpaid) statement and chain it back to the
 *   original purchase plus emit a card_statement_credits row of
 *   reason='refund_after_close'. The refund pass stays ICS-side
 *   because the Mijn ICS PDF DOES carry per-row refund entries — only
 *   the bulk-settlement entry is absent from the ICS side.
 *
 * The resolver writes to chain_links, card_statements (via
 * CardStatementStateMachine), and card_statement_credits only. It
 * NEVER mutates `transactions`. The
 * BoundaryArchTest::noResolverWritesTransactions rule binds the
 * invariant at CI time.
 *
 * Cross-user safety (FND-03): every query filters on
 * `->where('user_id', $user->id)` first. A cross-user leak is
 * structurally impossible.
 *
 * Concurrency: this resolver is invoked from ResolveChainLinksJob,
 * which is keyed unique-per-user via ShouldBeUniqueUntilProcessing.
 * Parallel passes for the same user are not possible; parallel passes
 * for different users are safe because every query is user-scoped.
 *
 * CR-03/D-06 (decrypt-then-match — Core Value): `transactions.counterparty_iban`
 * is a `SensitiveFieldRegistry` ciphertext column under encryption.
 * `resolveForUser()`'s `whereNotNull('counterparty_iban')` SQL narrowing
 * stays (null semantics are preserved by the codec), but the value is
 * decrypted before it is handed to {@see ResolvesKnownCounterpartyIban},
 * which resolves it against the plaintext
 * `known_counterparty_ibans.real_iban` column. The candidate set (this
 * user's bank-account `transfer_out` rows not yet carrying a confirmed
 * `ics_bulk_settle` chain_link) is already bounded per user, so no
 * additional decrypt-scan limit is required here.
 *
 * @internal Driven by the ResolveChainLinksJob — not called from
 *           public action classes directly.
 */
final class IcsSettlementResolver
{
    /** Symmetric tolerance arm: ±€5 absolute. (D-97) */
    public const AMOUNT_TOLERANCE_MINOR = 500;

    /** Symmetric tolerance arm: ±2% of the statement total. (D-97) */
    public const AMOUNT_TOLERANCE_PERCENT = 2;

    /** Symmetric window for matching transfer.posted_at to statement.period_end. (D-97) */
    public const PERIOD_WINDOW_DAYS = 10;

    /** Floor below which the statement is considered fully settled (±€0.01). (D-95) */
    public const SETTLED_TOLERANCE_MINOR = 1;

    /** Floor confidence applied to exceeded-tolerance candidates. */
    private const EXCEEDED_CONFIDENCE_FLOOR = 0.6;

    /** Ceiling confidence applied to exceeded-tolerance candidates (must stay < 1.0). */
    private const EXCEEDED_CONFIDENCE_CEILING = 0.99;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly CardStatementStateMachine $stateMachine,
        private readonly ChainLinkInsertHelper $inserter,
        private readonly ResolvesKnownCounterpartyIban $aliasResolver,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Run the bulk-settle decomposition pass for one user.
     *
     * The pass is idempotent: re-running produces zero net new
     * chain_links because (a) the candidate-transfer query filters out
     * transfers that already carry a confirmed ics_bulk_settle row,
     * and (b) the ChainLinkInsertHelper's pre-insert pair-uniqueness
     * guard refuses to duplicate rows for the same (from, to, kind,
     * user) tuple regardless of state.
     */
    public function resolveForUser(User $user): void
    {
        $connection = $this->db->connection();

        // Pick up unresolved ASN-side `transfer_out` rows pointing at
        // the user's ICS institution IBAN. The left join on chain_links
        // filters out transfers that already carry a confirmed bulk-
        // settle row.
        $candidateTransfers = $connection
            ->table('transactions')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', 'ics_bulk_settle')
                    ->where('chain_links.state', '=', 'confirmed');
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', 'bank')
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
            // D-06/CR-03: counterparty_iban is a SensitiveFieldRegistry
            // ciphertext column under encryption — decrypt before it is
            // handed to ResolvesKnownCounterpartyIban, which compares
            // against the plaintext known_counterparty_ibans.real_iban
            // column. Pass-through no-op when encryption is not enabled
            // for this user. The candidate set is already bounded per
            // user (bank-account transfer_out rows not yet carrying a
            // confirmed ics_bulk_settle link), so no additional scan
            // limit is needed here (LOW-MED risk per the audit).
            $storedCounterpartyIban = self::toString($transfer->counterparty_iban ?? null);
            $counterpartyIban = $storedCounterpartyIban === ''
                ? ''
                : $this->codec->decryptValue('transactions', 'counterparty_iban', $storedCounterpartyIban, $user->id, $this->session)['value'];
            $aliasAccount = $this->aliasResolver->resolveAccount($counterpartyIban, $user->id);
            if ($aliasAccount === null || $aliasAccount->kind !== 'ics_card') {
                // Not an ASN→ICS hop — skip. Other transfer_out rows
                // (e.g. bank-to-bank transfers between two of the
                // user's accounts, ASN→PayPal funding) are not the
                // bulk-settle pattern and have their own resolvers.
                continue;
            }
            $this->resolveOne($transfer, $aliasAccount, $user);
        }

        // Refund-after-close pass. Runs after the main pass so any
        // refund whose chained-back link was just written above gets
        // skipped here. The refund pass stays ICS-side because the
        // Mijn ICS PDF DOES carry per-row refund entries.
        $this->resolveRefundsAfterClose($user);
    }

    /**
     * Locate the candidate statement, build the per-expense link set,
     * compute the tolerance arm, and either confirm + apply the
     * settlement or write a single candidate row for the review queue.
     *
     * The candidate transfer is an ASN-side `transfer_out` with a
     * negative `settled_amount_minor` (money leaving the bank); the
     * algorithm operates in the positive (ICS-side) sign convention,
     * so the magnitude is taken on entry. The statement lookup runs
     * against the alias-resolved `ics_card` Account where the
     * card_statement actually lives, NOT against the ASN account
     * where the transfer_out sits.
     *
     * @param  stdClass  $transfer  Raw row from the candidate-transfer query.
     * @param  Account  $icsAccount  Alias-resolved ICS account.
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
            // Half-state. Statement may roll in on a future import; the
            // next resolver pass will pick this transfer up again.
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

        // Sign convention:
        //   - Expenses are stored as negative settled_amount_minor.
        //   - The transfer_in's settled_amount_minor is positive (money
        //     moving INTO the ICS account from ASN).
        //   - Statement total is negative (money owed).
        //
        // delta = -sum(expenses) - prior_credits - transfer
        //   positive delta = user overpaid (transfer covered more
        //                    than the outstanding charges)
        //   negative delta = user underpaid
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
                    'kind' => 'ics_bulk_settle',
                    'state' => 'confirmed',
                    'confidence' => '1.000',
                    'resolver' => 'auto',
                    'evidence' => $evidenceBase,
                ], $user);
            }

            // Drive the state machine — the SINGLE legal mutator of
            // card_statements.state (D-95). Apply the positive
            // settlement delta against the open balance.
            $settlement = $this->stateMachine->applySettlement($statementId, $settled, $user);

            if ($settlement->newState === 'overpaid') {
                // Overpayment surplus carries forward. to_statement_id
                // is set on the next resolver pass if/when the next
                // statement period lands — write NULL here to keep the
                // audit row complete without speculation.
                $surplus = abs($settlement->newOpenMinor);
                $now = $this->clock->now()->toDateTimeString();
                $connection->table('card_statement_credits')->insert([
                    'user_id' => $user->id,
                    'from_statement_id' => $statementId,
                    'to_statement_id' => null,
                    'amount_minor' => $surplus,
                    'reason' => 'overpayment',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return;
        }

        // Exceeded tolerance — surface one candidate row for the
        // review queue. to_transaction_id is NULL per the Wave 1
        // schema rule (chain_links_to_transaction_id_check_*).
        $confidence = $this->computeExceededConfidence($delta, $statementTotal);
        $this->inserter->insertIfNotExists([
            'from_transaction_id' => $transferId,
            'to_transaction_id' => null,
            'kind' => 'ics_bulk_settle',
            'state' => 'candidate',
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
     * Refund-after-close pass (D-98). Walks every `refund` row that
     * posted inside an already-closed statement and links it to the
     * original purchase plus emits a card_statement_credits row.
     */
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
                    ->whereIn('card_statements.state', ['settled', 'overpaid']);
            })
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.from_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', 'ics_bulk_settle');
            })
            ->where('transactions.user_id', $user->id)
            ->where('accounts.kind', 'ics_card')
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

    /**
     * Chain one refund back to its original purchase and write the
     * carry-forward credit.
     */
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

        // Look up the original purchase: same merchant + opposite-sign
        // amount inside the closed period. Most-recent wins when the
        // merchant repeats.
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
            // No matching original — skip silently. The review queue
            // can still display the refund via its evidence shape, but
            // a chain link with NULL to_transaction_id is reserved for
            // the exceeded-tolerance ICS bulk-settle case per the
            // Wave 1 schema trigger. Refund rows without a matching
            // original simply stay unlinked until the user manually
            // resolves them in a future wave's review surface.
            return;
        }

        $originalId = self::toInt($original->id ?? null);

        // Find the next open / partially_settled statement on the same
        // account so the refund credit carries forward to it.
        $nextStatement = $connection
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', ['open', 'partially_settled'])
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
            'kind' => 'ics_bulk_settle',
            'state' => 'confirmed',
            'confidence' => '1.000',
            'resolver' => 'auto',
            'evidence' => [
                'statement_id' => $closedStatementId,
                'unaccounted_delta_minor' => 0,
                'tolerance_used' => 'refund_after_close',
                'covered_count' => 1,
                'credits_applied_minor' => 0,
                'signature_hash' => $signatureHash,
                'reason' => 'refund_after_close',
            ],
        ], $user);

        // Emit the carry-forward credit. abs() ensures the magnitude
        // is positive regardless of which sign the refund row carries
        // (ICS refunds are positive in the settled_amount_minor column
        // because they offset a negative expense, but defensively
        // abs()-ing keeps the column invariant intact).
        $now = $this->clock->now()->toDateTimeString();
        $connection->table('card_statement_credits')->insert([
            'user_id' => $user->id,
            'from_statement_id' => $closedStatementId,
            'to_statement_id' => $nextStatementId,
            'amount_minor' => abs($refundAmount),
            'reason' => 'refund_after_close',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Find the card_statement candidate within ±PERIOD_WINDOW_DAYS of
     * the transfer's posted_at. Returns the open / partially_settled
     * statement on the same account whose period_end is closest to
     * the transfer's posted_at; ties broken by id ascending for
     * deterministic ordering.
     *
     * The amount-tolerance check is intentionally NOT applied here.
     * The matcher binds the statement by period alone so that
     * out-of-tolerance transfers still land as exceeded-tolerance
     * candidate rows for the review queue (per Step 9 of the algorithm
     * + Test 4 expectations). The tolerance arm is applied in
     * `resolveOne()` against the COMPUTED delta — that's the signal
     * the user judges, not the raw statement-total / transfer-amount
     * difference.
     */
    private function findCandidateStatement(int $accountId, string $postedAt, User $user): ?stdClass
    {
        $connection = $this->db->connection();

        // Compute the period_end window in PHP rather than relying on
        // SQLite's date() arithmetic — keeps the resolver portable to
        // other drivers and surfaces the window math in one place.
        $posted = CarbonImmutable::parse($postedAt);
        $windowStart = $posted->subDays(self::PERIOD_WINDOW_DAYS)->startOfDay()->toDateTimeString();
        $windowEnd = $posted->addDays(self::PERIOD_WINDOW_DAYS)->endOfDay()->toDateTimeString();

        $rows = $connection
            ->table('card_statements')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereIn('state', ['open', 'partially_settled'])
            ->whereBetween('period_end', [$windowStart, $windowEnd])
            ->orderBy('id')
            ->get([
                'id',
                'total_amount_minor',
                'open_balance_minor',
                'period_start',
                'period_end',
            ]);

        // Compare distances in seconds (Carbon's float-precision diff)
        // rather than integer days. Integer-day truncation collapsed
        // sub-day-distinct candidates to the same bucket and let the
        // SQL orderBy('id') tiebreak pick the older row even when a
        // newer-id row was actually closer to the transfer's
        // posted_at. The seconds-precision compare keeps the
        // closest-period-end semantic precise; PHP_FLOAT_MAX is the
        // ceiling sentinel so the float comparison stays correct
        // for the very first row in the loop.
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
     * Pull the per-period expense rows that still lack a confirmed
     * ics_bulk_settle chain_link.
     *
     * @return Collection<int, stdClass>
     */
    private function pullExpenses(int $accountId, string $periodStart, string $periodEnd, User $user): Collection
    {
        return $this->db->connection()
            ->table('transactions')
            ->leftJoin('chain_links', function ($join): void {
                /** @var JoinClause $join */
                $join->on('chain_links.to_transaction_id', '=', 'transactions.id')
                    ->where('chain_links.kind', '=', 'ics_bulk_settle')
                    ->where('chain_links.state', '=', 'confirmed');
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

    /**
     * Sum of all card_statement_credits.amount_minor rows pointing AT
     * this statement (i.e. credits already carried forward into it).
     */
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
     * Degenerate per D-88: ICS bulk-settle signatures hash
     * (account_iban|period_end) so the auto-promotion counter (Wave 3)
     * sees them as already-stable patterns. The user's id is folded in
     * for cross-user isolation when two users happen to hold the same
     * account.iban literal (e.g. the synthetic 'ICS-CARD' string).
     */
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

    /**
     * Confidence band for exceeded-tolerance candidates: floor at
     * 0.6, ceiling at 0.99. Larger delta → lower confidence.
     */
    private function computeExceededConfidence(int $delta, int $statementTotal): float
    {
        $base = abs($statementTotal);
        if ($base <= 0) {
            return self::EXCEEDED_CONFIDENCE_FLOOR;
        }
        $raw = 1.0 - abs($delta) / $base;

        return max(self::EXCEEDED_CONFIDENCE_FLOOR, min(self::EXCEEDED_CONFIDENCE_CEILING, $raw));
    }

    /**
     * Format a float confidence value as a fixed-precision decimal
     * string matching the chain_links.confidence column's decimal(4,3)
     * shape. Avoids floating-point string drift in test assertions.
     */
    private function formatConfidence(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /**
     * Numeric coercion for raw query-builder column values. SQLite
     * returns scalars as strings via stdClass attributes; the strict-
     * rules `cast.int` lint stays happy by guarding the int cast.
     */
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * String coercion mirroring `CardStatementStateMachine::toString()`.
     */
    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
    }
}
