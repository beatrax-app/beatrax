<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Expression;
use Modules\Categorization\Models\RuleAction;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\NoteMode;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\SafeExceptionContext;
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
 * @link ../../../../.docs/features/categorization/field-provenance.md
 */
final class RuleApplier
{
    /** @var array<string, string> Action `type` → `field_provenance` key. */
    private const array PROVENANCE_KEY = [
        ActionType::Category->value => 'category_id',
        ActionType::Counterparty->value => 'counterparty_id',
        ActionType::Note->value => 'note',
        ActionType::TaxTag->value => 'tax_tag',
    ];

    // Keyed rather than a single slot: one instance is reused across every
    // chunk of a ReapplyRulesJob run, and could be reused across two users.
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
        private readonly SessionFactory $session,
    ) {}

    /**
     * @param  list<MatchedRule>  $matched
     *
     * @link ../../../../.docs/features/categorization/rule-evaluation-order.md
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
     * @param  list<MatchedRule>  $matched
     * @return array<string, mixed> the fields actually changed (dirtyFields shape)
     *
     * @link ../../../../.docs/features/categorization/rule-evaluation-order.md
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
                ActionType::Category->value => $this->applyCategory($entry['ruleId'], $entry['action'], $transactionId, $user),
                ActionType::Counterparty->value => $this->applyCounterparty($entry['ruleId'], $entry['action'], $transactionId, $user),
                ActionType::Note->value => $this->applyNote($entry['ruleId'], $entry['action'], $transactionId, $user),
                ActionType::TaxTag->value => $this->applyTaxTag($entry['ruleId'], $entry['action'], $transactionId, $userId),
                default => null,
            };

            if ($result !== null) {
                [$field, $value] = $result;
                $changed[$field] = $value;
                $this->bumpHits($entry['ruleId'], $userId);
            }
        }

        return $changed;
    }

    // ApplyAutoCategoryStage was the only place this column ever moved, so a
    // rule applied across a whole history still read "0 hits" on /rules. Bumped
    // here only when the row genuinely changed, because re-running is a no-op
    // by contract and a second pass over unchanged data must not inflate it.
    private function bumpHits(int $ruleId, int $userId): void
    {
        $this->db->connection()
            ->table('categorization_rules')
            ->where('id', $ruleId)
            ->where('user_id', $userId)
            ->update(['hits_count' => new Expression('hits_count + 1')]);
    }

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
            $this->logSkip($ruleId, ActionType::Category->value, 'missing/non-numeric category_id payload');

            return null;
        }

        // Zero covers unchanged, locked and cross-user alike: the action's own
        // write-only-on-change guard is what keeps a repeat re-apply silent.
        if (($this->updateCategory)($transactionId, $categoryId, $user) === 0) {
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
            $this->logSkip($ruleId, ActionType::Counterparty->value, 'missing/non-numeric counterparty_id payload');

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

    // Under `append` the final text is only known after the write, so the
    // dirtyFields value has to come from a re-read rather than the payload.
    /**
     * @return array{0: string, 1: mixed}|null
     */
    private function applyNote(int $ruleId, RuleAction $action, int $transactionId, User $user): ?array
    {
        $text = self::stringFromPayload($action->payload, 'text');
        if ($text === null || $text === '') {
            $this->logSkip($ruleId, ActionType::Note->value, 'missing/empty text payload');

            return null;
        }

        $mode = NoteMode::coerce(self::stringFromPayload($action->payload, 'mode'))->value;

        $affected = ($this->setNote)($transactionId, $text, $mode, $user);
        if ($affected === 0) {
            return null;
        }

        $finalNoteRaw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->value('note');
        // Decrypt before this becomes an op-log dirtyFields value, or the
        // op-log's own encrypt-on-write would double-encrypt it.
        $finalNote = is_string($finalNoteRaw)
            ? $this->codec->decryptValue('transactions', 'note', $finalNoteRaw, $user->id, ($this->session)())['value']
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

    // TagTransaction::execute() dispatches its own event and stamps its own
    // provenance, so this path deliberately does neither — doing both would
    // report the same change twice into the op-log.
    /**
     * @return array{0: string, 1: mixed}|null
     */
    private function applyTaxTag(int $ruleId, RuleAction $action, int $transactionId, int $userId): ?array
    {
        $deductionCategoryId = self::intFromPayload($action->payload, 'deduction_category_id');
        if ($deductionCategoryId === null) {
            $this->logSkip($ruleId, ActionType::TaxTag->value, 'missing/non-numeric deduction_category_id payload');

            return null;
        }
        $year = self::intFromPayload($action->payload, 'year');

        if (! $this->writeTaxTag($ruleId, $transactionId, $userId, $deductionCategoryId, $year)) {
            return null;
        }

        return ['tax_tag', $deductionCategoryId];
    }

    private function writeTaxTag(int $ruleId, int $transactionId, int $userId, int $deductionCategoryId, ?int $year): bool
    {
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

        if ($currentDeductionCategoryId === $deductionCategoryId && $currentYear === $year) {
            return false;
        }

        // TagTransaction::updateExisting() rewrites note/category/year
        // together the instant any one is non-null, so passing a literal null
        // here would silently wipe a user-authored tax note.
        $currentNote = $current !== null && is_string($current->note)
            ? $this->codec->decryptValue('tax_transaction_tags', 'note', $current->note, $userId, ($this->session)())['value']
            : null;

        try {
            $this->tagTransaction->execute($userId, $transactionId, $deductionCategoryId, $currentNote, $year, null, 'rule');
        } catch (Throwable $e) {
            $this->logger->warning('RuleApplier skipped a tax_tag action.', [
                'rule_id' => $ruleId,
                'action_type' => ActionType::TaxTag->value,
                ...SafeExceptionContext::describe($e),
            ]);

            return false;
        }

        return true;
    }

    private function logSkip(int $ruleId, string $actionType, string $reason): void
    {
        $this->logger->warning('RuleApplier skipped a malformed action.', [
            'rule_id' => $ruleId,
            'action_type' => $actionType,
            'reason' => $reason,
        ]);
    }

    // `tax_tag` does nothing at import: there is no persisted transaction_id
    // to tag yet, so tax tagging waits for the re-apply pass.
    private function foldImportAction(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        return match ($action->type) {
            ActionType::Category->value => $this->foldCategory($ruleId, $action, $tx),
            ActionType::Counterparty->value => $this->foldCounterparty($ruleId, $action, $tx),
            ActionType::Note->value => $this->foldNote($ruleId, $action, $tx),
            ActionType::TaxTag->value => $tx,
            default => $this->skipped($ruleId, $action->type, 'unrecognised action type', $tx),
        };
    }

    private function foldCategory(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $categoryId = self::intFromPayload($action->payload, 'category_id');
        if ($categoryId === null) {
            return $this->skipped($ruleId, ActionType::Category->value, 'missing/non-numeric category_id payload', $tx);
        }

        return $tx->withCategoryId($categoryId);
    }

    private function foldCounterparty(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $counterpartyId = self::intFromPayload($action->payload, 'counterparty_id');
        if ($counterpartyId === null) {
            return $this->skipped($ruleId, ActionType::Counterparty->value, 'missing/non-numeric counterparty_id payload', $tx);
        }

        return $tx->withCounterpartyId($counterpartyId);
    }

    // At import there is no prior stored note, so `set` and `append` both
    // resolve to the payload text outright.
    private function foldNote(int $ruleId, RuleAction $action, CanonicalTransaction $tx): CanonicalTransaction
    {
        $text = self::stringFromPayload($action->payload, 'text');
        if ($text === null || $text === '') {
            return $this->skipped($ruleId, ActionType::Note->value, 'missing/empty text payload', $tx);
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
