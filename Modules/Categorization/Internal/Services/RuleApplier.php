<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Models\RuleAction;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Contracts\SetsTransactionNote;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Actions\TagTransaction;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The dual-mode action executor that turns a `list<MatchedRule>` into
 * effects (D-06), with same-field conflicts resolved last-writer-wins
 * by execution order (Req 3).
 *
 * `applyAtImport()` folds `category`/`counterparty`/`note` actions onto
 * a `CanonicalTransaction` DTO via withers before persistence — no
 * op-log, no DB write, no event dispatch. `tax_tag` cannot fire at
 * import (there is no persisted `transaction_id` yet for
 * `tax_transaction_tags` to reference — RESEARCH Pitfall 4) and is
 * silently skipped.
 *
 * `applyAtReapply()` (Plan 05 Task 2) DELEGATES every field write to
 * the Ledger Public guarded actions — this service NEVER writes
 * `transactions` raw (CLAUDE.md module boundary).
 *
 * Fail-open (T-13.4-15): a malformed action payload (missing/non-numeric
 * embedded id, unrecognised action type) skips just that one action —
 * logged, never thrown — so one broken action can never abort a whole
 * import row or re-apply pass.
 */
final class RuleApplier
{
    /** @var array<string, string> Action `type` → `field_provenance` key. */
    private const PROVENANCE_KEY = [
        'category' => 'category_id',
        'counterparty' => 'counterparty_id',
        'note' => 'note',
        'tax_tag' => 'tax_tag',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly UpdatesTransactionCategory $updateCategory,
        private readonly ReassignsCounterparty $reassignCounterparty,
        private readonly SetsTransactionNote $setNote,
        private readonly TagTransaction $tagTransaction,
        private readonly FieldProvenanceWriter $provenance,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * Iterates `$matched` in the given (already priority/id-ordered)
     * order; within each rule, actions are iterated in their
     * position-ordered sequence (`RuleEngine::actionsFor()` already
     * orders them). Folding sequentially over a mutable `$tx` local
     * makes last-writer-wins automatic: a later action targeting the
     * same field simply overwrites the earlier wither's result.
     *
     * `tax_tag` actions are ignored outright (no crash, no effect on
     * the DTO) — import-time tax tagging is deferred to re-apply.
     *
     * @param  list<MatchedRule>  $matched
     */
    public function applyAtImport(array $matched, CanonicalTransaction $tx): CanonicalTransaction
    {
        foreach ($matched as $rule) {
            foreach ($rule->actions as $action) {
                $tx = $this->foldImportAction($rule->ruleId, $action, $tx);
            }
        }

        return $tx;
    }

    /**
     * Applies `$matched` against an already-persisted transaction.
     * Every field write is DELEGATED to a Ledger Public guarded action
     * (or, for `tax_tag`, to `TagTransaction`) — this method never
     * writes `transactions` raw (CLAUDE.md module boundary).
     *
     * Reduce-to-desired: rules are folded in the given (priority/id-
     * ordered) sequence, and each rule's actions in position order,
     * into a single desired action PER action `type` — a later action
     * targeting the same type simply replaces the earlier one in the
     * map, so last-writer-wins resolves to exactly one write attempt
     * per field (Req 6: at most one op-log entry per genuinely-changed
     * field).
     *
     * Manual-preservation (D-04/T-13.4-14): `field_provenance` is read
     * once up front; any field already stamped `'manual'` is skipped
     * entirely — no read, no write, no dispatch.
     *
     * Fail-open (T-13.4-15): a malformed or dangling payload id skips
     * just that one field (logged), never aborts the whole pass.
     *
     * @param  list<MatchedRule>  $matched
     * @return array<string, mixed> the fields actually changed (dirtyFields shape)
     */
    public function applyAtReapply(array $matched, int $transactionId, int $userId): array
    {
        /** @var array<string, array{ruleId: int, action: RuleAction}> $desiredByType */
        $desiredByType = [];
        foreach ($matched as $rule) {
            foreach ($rule->actions as $action) {
                $desiredByType[$action->type] = ['ruleId' => $rule->ruleId, 'action' => $action];
            }
        }

        if ($desiredByType === []) {
            return [];
        }

        $user = User::query()->findOrFail($userId);
        $provenance = $this->provenance->provenanceFor($userId, $transactionId);

        $changed = [];
        foreach ($desiredByType as $type => $entry) {
            if (($provenance[self::PROVENANCE_KEY[$type] ?? ''] ?? null) === 'manual') {
                continue;
            }

            $result = match ($type) {
                'category' => $this->applyCategory($entry['ruleId'], $entry['action'], $transactionId, $user),
                'counterparty' => $this->applyCounterparty($entry['ruleId'], $entry['action'], $transactionId, $user),
                'note' => $this->applyNote($entry['ruleId'], $entry['action'], $transactionId, $user),
                'tax_tag' => $this->applyTaxTag($entry['ruleId'], $entry['action'], $transactionId, $userId),
                default => null,
            };

            if ($result !== null) {
                [$field, $value] = $result;
                $changed[$field] = $value;
            }
        }

        return $changed;
    }

    /**
     * @return array{0: string, 1: mixed}|null a [field, value] pair when a genuine
     *                                         write happened, null otherwise
     */
    private function applyCategory(int $ruleId, RuleAction $action, int $transactionId, User $user): ?array
    {
        $categoryId = self::intFromPayload($action->payload, 'category_id');
        if ($categoryId === null) {
            $this->logSkip($ruleId, 'category', 'missing/non-numeric category_id payload');

            return null;
        }

        // Unlike ReassignCounterparty/SetTransactionNote, UpdateTransactionCategory
        // has no internal write-only-on-change guard — an UPDATE with an
        // unchanged category_id still reports 1 affected row on SQLite
        // (it counts matched rows, not changed rows). Read-before-write
        // here so re-apply idempotency (Req 4/6) holds for this field too.
        $currentCategoryId = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->value('category_id');
        $currentCategoryId = is_numeric($currentCategoryId) ? (int) $currentCategoryId : null;
        if ($currentCategoryId === $categoryId) {
            return null;
        }

        $affected = ($this->updateCategory)($transactionId, $categoryId, $user);
        if ($affected === 0) {
            return null;
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['category_id' => $categoryId],
        ));
        $this->provenance->stamp($user->id, $transactionId, ['category_id' => 'rule']);

        return ['category_id', $categoryId];
    }

    /**
     * @return array{0: string, 1: mixed}|null
     */
    private function applyCounterparty(int $ruleId, RuleAction $action, int $transactionId, User $user): ?array
    {
        $counterpartyId = self::intFromPayload($action->payload, 'counterparty_id');
        if ($counterpartyId === null) {
            $this->logSkip($ruleId, 'counterparty', 'missing/non-numeric counterparty_id payload');

            return null;
        }

        $affected = ($this->reassignCounterparty)($transactionId, $counterpartyId, $user);
        if ($affected === 0) {
            // Cross-user/unknown target, reconciled lock, or unchanged
            // value — all resolve to a silent skip (fail-open, T-13.4-15).
            return null;
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['counterparty_id' => $counterpartyId],
        ));
        $this->provenance->stamp($user->id, $transactionId, ['counterparty_id' => 'rule']);

        return ['counterparty_id', $counterpartyId];
    }

    /**
     * Delegates to `SetsTransactionNote`, which resolves `set`/`append`
     * mode internally. Because `append` concatenates onto whatever the
     * current note happens to be, the final text is only known AFTER a
     * genuine write — re-read here so `dirtyFields`/the returned
     * changed-map always carry the value actually persisted.
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function applyNote(int $ruleId, RuleAction $action, int $transactionId, User $user): ?array
    {
        $text = self::stringFromPayload($action->payload, 'text');
        if ($text === null || $text === '') {
            $this->logSkip($ruleId, 'note', 'missing/empty text payload');

            return null;
        }

        $mode = self::stringFromPayload($action->payload, 'mode') === 'append' ? 'append' : 'set';

        $affected = ($this->setNote)($transactionId, $text, $mode, $user);
        if ($affected === 0) {
            return null;
        }

        $finalNoteRaw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->value('note');
        // D-07: the re-read note is ciphertext under an encrypted user
        // (SetTransactionNote's own write hook) — decrypt before it becomes
        // an op-log dirtyFields value, or the op-log's own encrypt-on-write
        // would double-encrypt it.
        $finalNote = is_string($finalNoteRaw)
            ? $this->codec->decryptValue('transactions', 'note', $finalNoteRaw, $user->id, $this->session)['value']
            : null;

        $this->events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['note' => $finalNote],
        ));
        $this->provenance->stamp($user->id, $transactionId, ['note' => 'rule']);

        return ['note', $finalNote];
    }

    /**
     * Delegates to `TagTransaction::execute(..., 'rule')` — an
     * idempotent upsert that dispatches its own `TransactionTagged`
     * event and stamps its own `field_provenance.tax_tag` internally
     * (it accepts the provenance source as a trailing param), so this
     * method does NOT re-dispatch `TransactionMutated` or re-stamp
     * provenance itself — that would double-report the change.
     *
     * Idempotency: reads the current whole-transaction tag row first
     * so a second identical re-apply is a genuine no-op (no call to
     * `TagTransaction` at all) rather than relying on the upsert's own
     * internal idempotency, which still rewrites `updated_at` on every
     * call. A dangling `deduction_category_id` (row deleted after the
     * rule was authored) is caught as a thrown `NotFoundHttpException`
     * and fail-opens to a skip (T-13.4-15).
     *
     * @return array{0: string, 1: mixed}|null
     */
    private function applyTaxTag(int $ruleId, RuleAction $action, int $transactionId, int $userId): ?array
    {
        $deductionCategoryId = self::intFromPayload($action->payload, 'deduction_category_id');
        if ($deductionCategoryId === null) {
            $this->logSkip($ruleId, 'tax_tag', 'missing/non-numeric deduction_category_id payload');

            return null;
        }
        $year = self::intFromPayload($action->payload, 'year');

        $current = $this->db->connection()
            ->table('tax_transaction_tags')
            ->where('user_id', $userId)
            ->where('transaction_id', $transactionId)
            ->whereNull('transaction_split_id')
            ->first(['deduction_category_id', 'tax_year_override', 'note']);

        $currentDeductionCategoryId = $current !== null && is_numeric($current->deduction_category_id)
            ? (int) $current->deduction_category_id
            : null;
        $currentYear = $current !== null && is_numeric($current->tax_year_override)
            ? (int) $current->tax_year_override
            : null;
        // CR-02: TagTransaction::updateExisting() rewrites note/category/year
        // TOGETHER the instant any one of the three is non-null — since
        // $deductionCategoryId is always non-null here, passing a literal
        // `null` note would silently wipe a user-authored tax note on every
        // rule-driven category/year change. Read the existing note and pass
        // it through unchanged so a rule re-apply never touches it.
        $currentNote = $current !== null && is_string($current->note) ? $current->note : null;

        if ($currentDeductionCategoryId === $deductionCategoryId && $currentYear === $year) {
            return null;
        }

        try {
            $this->tagTransaction->execute($userId, $transactionId, $deductionCategoryId, $currentNote, $year, null, 'rule');
        } catch (Throwable $e) {
            $this->logger->warning('RuleApplier skipped a tax_tag action.', [
                'rule_id' => $ruleId,
                'action_type' => 'tax_tag',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        return ['tax_tag', $deductionCategoryId];
    }

    private function logSkip(int $ruleId, string $actionType, string $reason): void
    {
        $this->logger->warning('RuleApplier skipped a malformed action.', [
            'rule_id' => $ruleId,
            'action_type' => $actionType,
            'reason' => $reason,
        ]);
    }

    private function foldImportAction(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        return match ($action->type) {
            'category' => $this->foldCategory($ruleId, $action, $tx),
            'counterparty' => $this->foldCounterparty($ruleId, $action, $tx),
            'note' => $this->foldNote($ruleId, $action, $tx),
            'tax_tag' => $tx, // import-deferred (Pitfall 4) — not a failure, no log.
            default => $this->skipped($ruleId, $action->type, 'unrecognised action type', $tx),
        };
    }

    private function foldCategory(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $categoryId = self::intFromPayload($action->payload, 'category_id');
        if ($categoryId === null) {
            return $this->skipped($ruleId, 'category', 'missing/non-numeric category_id payload', $tx);
        }

        return $tx->withCategoryId($categoryId);
    }

    private function foldCounterparty(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $counterpartyId = self::intFromPayload($action->payload, 'counterparty_id');
        if ($counterpartyId === null) {
            return $this->skipped($ruleId, 'counterparty', 'missing/non-numeric counterparty_id payload', $tx);
        }

        return $tx->withCounterpartyId($counterpartyId);
    }

    /**
     * At import there is no prior stored note, so `set` and `append`
     * both resolve to the payload text outright (append onto a null
     * base collapses to a plain set).
     */
    private function foldNote(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $text = self::stringFromPayload($action->payload, 'text');
        if ($text === null || $text === '') {
            return $this->skipped($ruleId, 'note', 'missing/empty text payload', $tx);
        }

        return $tx->withNote($text);
    }

    private function skipped(int $ruleId, string $actionType, string $reason, CanonicalTransaction $tx): CanonicalTransaction
    {
        $this->logger->warning('RuleApplier skipped a malformed action.', [
            'rule_id' => $ruleId,
            'action_type' => $actionType,
            'reason' => $reason,
        ]);

        return $tx;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intFromPayload(array $payload, string $key): ?int
    {
        return isset($payload[$key]) && is_numeric($payload[$key]) ? (int) $payload[$key] : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringFromPayload(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : null;
    }
}
