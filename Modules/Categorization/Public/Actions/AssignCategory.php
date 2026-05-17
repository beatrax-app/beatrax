<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\CategorizationDiverged;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;

/**
 * Default implementation of the Categorization Public `AssignsCategory`
 * contract. Routes the write through Ledger's `UpdatesTransactionCategory`
 * action so Ledger remains the only mutator of `transactions`, and fires
 * the `TransactionCategorized` event after a successful write so other
 * modules can react without coupling.
 *
 * Divergence detection: BEFORE invoking the Ledger updater, the action
 * reads the row's existing `auto_category_provenance` (user-scoped).
 * After a successful write, if the prior provenance had source='rule'
 * AND the new categoryId differs from the rule's category_id AND the
 * provenance carried a non-null rule_id, dispatch
 * `CategorizationDiverged` so the CorrectionDivergenceToast SFC can
 * offer the user the choice between Update rule / Keep current rule.
 *
 * Memory-provenance overrides do NOT dispatch CategorizationDiverged
 * — memory grows automatically via MerchantMemoryWriter on every
 * TransactionCategorized event, so a memory-driven suggestion that the
 * user overrides updates memory transparently without a confirmation
 * surface.
 */
final class AssignCategory implements AssignsCategory
{
    public function __construct(
        private readonly UpdatesTransactionCategory $updater,
        private readonly Dispatcher $events,
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $priorProvenance = $this->readPriorProvenance($transactionId, $user->id);

        $affected = ($this->updater)($transactionId, $categoryId, $user);

        if ($affected > 0) {
            $this->events->dispatch(new TransactionCategorized(
                transactionId: $transactionId,
                categoryId: $categoryId,
                userId: $user->id,
            ));

            if ($categoryId !== null && $priorProvenance !== null) {
                $this->maybeDispatchDivergence($priorProvenance, $transactionId, $categoryId, $user->id);
            }
        }

        return $affected;
    }

    /**
     * Reads transactions.auto_category_provenance (already cast as an
     * array by the Eloquent model) via the raw query builder so the
     * action stays decoupled from the model's casting layer. The user
     * scope is enforced here defensively; the updater's own
     * cross-user guard provides the canonical authorisation.
     *
     * @return array<string, mixed>|null
     */
    private function readPriorProvenance(int $transactionId, int $userId): ?array
    {
        $raw = $this->db->connection()
            ->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->value('auto_category_provenance');

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $priorProvenance
     */
    private function maybeDispatchDivergence(
        array $priorProvenance,
        int $transactionId,
        int $newCategoryId,
        int $userId,
    ): void {
        $source = $priorProvenance['source'] ?? null;
        if ($source !== 'rule') {
            return;
        }

        $ruleIdRaw = $priorProvenance['rule_id'] ?? null;
        if (! is_int($ruleIdRaw) && ! is_numeric($ruleIdRaw)) {
            return;
        }
        $ruleId = (int) $ruleIdRaw;
        if ($ruleId === 0) {
            return;
        }

        $oldCategoryRaw = $priorProvenance['category_id'] ?? null;
        if (! is_int($oldCategoryRaw) && ! is_numeric($oldCategoryRaw)) {
            return;
        }
        $oldCategoryId = (int) $oldCategoryRaw;

        if ($newCategoryId === $oldCategoryId) {
            return;
        }

        $this->events->dispatch(new CategorizationDiverged(
            transactionId: $transactionId,
            ruleId: $ruleId,
            oldCategoryId: $oldCategoryId,
            newCategoryId: $newCategoryId,
            userId: $userId,
        ));
    }
}
