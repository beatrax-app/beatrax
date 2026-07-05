<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Internal\Jobs\ReapplyRulesJob;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/rules` route landing — the categorization-rule CRUD page.
 *
 * Reads via CategorizationRuleQuery (user-scoped, priority/id-ordered —
 * the same order `RuleEngine::match()` executes rules in, so the table
 * visually *is* the execution order); mutates via the Public actions.
 * The page itself owns the table render + the per-row Edit / Delete
 * chip flow + the re-apply-to-history trigger; the create/edit form
 * lives in RuleFormModal, mounted globally inside `app.blade.php` and
 * dispatched via the `rule-form:open` Livewire event.
 *
 * Inline two-step delete confirmation: clicking Delete sets
 * `$confirmingDeleteId` to the row id; the Blade renders the
 * `[Delete?] [Yes, delete] [Cancel]` chip pair in place of the
 * default `[Edit] [Delete]` row. Cancel clears the flag; Yes invokes
 * DeleteCategorizationRule and re-renders the row list.
 *
 * Re-apply-to-history (Req 4, 13.4-UI-SPEC.md § Re-apply trigger):
 * `triggerReapply` dispatches `ReapplyRulesJob` for the AUTHENTICATED
 * user ONLY — the method takes no client-suppliable user id parameter,
 * so a tampered Livewire wire payload has no id to forge (T-13.4-26).
 * While a run is in flight, `render()` reads the job's cache-backed
 * progress payload (`ReapplyRulesJob::progressCacheKey($userId)`) fresh
 * on every request/poll tick and surfaces the live "N of M checked"
 * strip; once the payload flips to `status = 'done'`, render() folds
 * the summary into the existing flash-message banner and clears the
 * in-flight flag — no separate completion-detection method needed,
 * mirroring `InboxesPage`'s "poll handler is a no-op, render() re-reads
 * live state" idiom.
 *
 * Constructor-free Livewire component; service collaborators arrive
 * as parameters on action methods + render(). The strict-rules
 * ruleset forbids property-based constructor injection on Component
 * subclasses; `auth()` / `Auth::user()` / facade lookups are out of
 * bounds project-wide.
 */
final class RulesPage extends Component
{
    public ?int $confirmingDeleteId = null;

    public string $flashMessage = '';

    /**
     * True from the moment `triggerReapply` dispatches the job until
     * render() observes a `status = 'done'` cache payload. Drives both
     * the disabled "Re-applying…" button state and the `wire:poll.2s`
     * progress strip's visibility.
     */
    public bool $reapplyDispatched = false;

    public function openCreateModal(): void
    {
        $this->dispatch('rule-form:open');
    }

    public function openEditModal(int $ruleId): void
    {
        $this->dispatch('rule-form:open', ruleId: $ruleId);
    }

    public function confirmDelete(int $ruleId): void
    {
        $this->confirmingDeleteId = $ruleId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function deleteRule(int $ruleId, CurrentUser $currentUser, DeleteCategorizationRule $delete): void
    {
        // DeleteCategorizationRule throws NotFoundHttpException on
        // cross-user / missing rules. Without this catch a tampered
        // Livewire payload would surface as a 500 / framework error
        // page; catching it lets the page render a calm flash
        // instead. The action's own ownership guard already prevents
        // the delete from succeeding cross-user — the catch only
        // affects the UI surface, not the security boundary.
        try {
            ($delete)($currentUser->user(), $ruleId);
        } catch (NotFoundHttpException) {
            $this->confirmingDeleteId = null;
            $this->flashMessage = 'Rule not found (it may have been deleted in another tab).';

            return;
        }
        $this->confirmingDeleteId = null;
        $this->flashMessage = 'Rule deleted.';
    }

    #[On('rule-form:saved')]
    public function handleSaved(): void
    {
        $this->flashMessage = 'Rule saved.';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    /**
     * Dispatch the user-triggered re-apply-to-history job (Req 4).
     * ALWAYS reads the target user id from CurrentUser — never from a
     * client-suppliable parameter — so a tampered wire payload has no
     * way to trigger a re-apply for a foreign user (T-13.4-26).
     */
    public function triggerReapply(CurrentUser $currentUser, Dispatcher $bus): void
    {
        $bus->dispatch(new ReapplyRulesJob($currentUser->user()->id));
        $this->reapplyDispatched = true;
        $this->flashMessage = 'Re-applying rules to your history…';
    }

    /**
     * Polling target for the re-apply progress strip. Intentionally a
     * no-op — Livewire re-renders the component on every poll tick,
     * which re-queries the live progress payload inside render().
     */
    public function refreshReapplyProgress(): void {}

    public function render(CurrentUser $currentUser, CategorizationRuleQuery $query, Repository $cache, ViewFactory $views): View
    {
        $user = $currentUser->user();
        $rules = $query->forUser($user);

        $rawProgress = $cache->get(ReapplyRulesJob::progressCacheKey($user->id));
        /** @var array<string, mixed>|null $progress */
        $progress = is_array($rawProgress) ? $rawProgress : null;

        $reapplyInFlight = false;
        if ($this->reapplyDispatched) {
            if ($progress !== null && ($progress['status'] ?? null) === 'done') {
                $this->flashMessage = self::summaryMessage($progress);
                $this->reapplyDispatched = false;
            } else {
                $reapplyInFlight = true;
            }
        }

        $view = $views->make('categorization::livewire.rules-page', [
            'rules' => $rules,
            'reapplyInFlight' => $reapplyInFlight,
            'reapplyProgress' => $reapplyInFlight ? $progress : null,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Rules · beatrax']);

        return $view;
    }

    /** Renders a rule's first condition as a natural-language fragment. */
    public static function conditionFragment(RuleConditionDto $condition): string
    {
        $displayField = $condition->valueType === 'string' ? $condition->field : $condition->valueType;
        $opLabel = RuleFormModal::operatorOptionsFor($displayField)[$condition->op] ?? $condition->op;

        if ($condition->valueType !== 'string') {
            if ($condition->op === 'between' && $condition->value2 !== null) {
                return "{$displayField} {$opLabel} {$condition->value} and {$condition->value2}";
            }

            return "{$displayField} {$opLabel} {$condition->value}";
        }

        return "{$displayField} {$opLabel} \"{$condition->value}\"";
    }

    /** Renders one rule_actions row as its neutral `.chip` label. */
    public static function actionChipLabel(RuleActionDto $action): string
    {
        return match ($action->type) {
            'category' => 'Category: '.($action->categoryPath ?? '—'),
            'counterparty' => 'Counterparty: '.($action->counterpartyName ?? '—'),
            'note' => 'Note',
            default => 'Tax tag',
        };
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private static function summaryMessage(array $progress): string
    {
        $fieldsUpdated = is_numeric($progress['fields_updated'] ?? null) ? (int) $progress['fields_updated'] : 0;
        $transactionsUpdated = is_numeric($progress['transactions_updated'] ?? null) ? (int) $progress['transactions_updated'] : 0;
        $reconciledSkipped = is_numeric($progress['reconciled_skipped'] ?? null) ? (int) $progress['reconciled_skipped'] : 0;

        if ($fieldsUpdated === 0 && $transactionsUpdated === 0) {
            return 'No changes — your history already matches your rules.';
        }

        $base = "Updated {$fieldsUpdated} fields across {$transactionsUpdated} transactions.";

        if ($reconciledSkipped > 0) {
            return $base." {$reconciledSkipped} reconciled transactions were skipped.";
        }

        return $base;
    }
}
