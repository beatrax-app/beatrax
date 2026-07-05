<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Services;

use Modules\Categorization\Models\RuleAction;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;

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
    public function __construct(
        private readonly LoggerInterface $logger,
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
