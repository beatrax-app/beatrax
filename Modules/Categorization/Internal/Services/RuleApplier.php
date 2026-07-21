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
 * @link ../../../../.docs/features/categorization/architecture.md
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

    // Per-instance User memo, keyed by userId. A single ReapplyRulesJob run
    // resolves this RuleApplier once and folds every chunk + matched row
    // through it, so without memoization applyAtReapply reloads the SAME user
    // from the DB once per matched row. Keyed (not a single slot) so a
    // hypothetically instance-reused across two users stays correct.
    /** @var array<int, User> */
    private array $userCache = [];

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

    // Folding sequentially over a mutable $tx local makes last-writer-wins
    // automatic: a later action targeting the same field simply overwrites
    // the earlier wither's result. `tax_tag` actions are ignored outright
    // — import-time tax tagging is deferred to re-apply.
    /**
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

    // Reduce-to-desired: rules fold into a single desired action per
    // action `type`, so last-writer-wins resolves to exactly one write
    // attempt per field. `field_provenance` is read once up front; any
    // field already stamped 'manual' is skipped entirely.
    /**
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

        $user = $this->resolveUser($userId);
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

    // `??=` only queries when the key is absent, so findOrFail still throws
    // ModelNotFoundException on a missing user on the first resolve and
    // nothing is cached on failure — identical to the pre-memo behavior.
    private function resolveUser(int $userId): User
    {
        return $this->userCache[$userId] ??= User::query()->findOrFail($userId);
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
        // unchanged category_id still reports 1 affected row on SQLite.
        // Read-before-write here so re-apply idempotency holds too.
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
            // value — all resolve to a silent skip (fail-open).
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

    // `append` concatenates onto the current note, so the final text is
    // only known AFTER a genuine write — re-read here so dirtyFields
    // always carries the value actually persisted.
    /**
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
        // The re-read note is ciphertext under an encrypted user; decrypt
        // before it becomes an op-log dirtyFields value, or the op-log's
        // own encrypt-on-write would double-encrypt it.
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

    // TagTransaction::execute() dispatches its own event and stamps its
    // own provenance internally, so this method does NOT re-dispatch or
    // re-stamp. Reads the current tag row first so an identical re-apply
    // is a genuine no-op rather than relying on the upsert's own idempotency.
    /**
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
        // TagTransaction::updateExisting() rewrites note/category/year
        // TOGETHER the instant any one is non-null, so a literal null note
        // would silently wipe a user-authored tax note; read the existing
        // note (decrypted, mirroring applyNote()) and pass it through unchanged.
        $currentNote = $current !== null && is_string($current->note)
            ? $this->codec->decryptValue('tax_transaction_tags', 'note', $current->note, $userId, $this->session)['value']
            : null;

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
            'tax_tag' => $tx,
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

    // At import there is no prior stored note, so `set` and `append` both
    // resolve to the payload text outright.
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
