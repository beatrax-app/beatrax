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
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Enums\ConditionOperator;
use Modules\Categorization\Public\Enums\ConditionValueType;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The `/rules` CRUD page. Reads via CategorizationRuleQuery
// (priority/id-ordered — the same order RuleEngine::match() executes
// rules in, so the table visually IS the execution order); the
// create/edit form lives in RuleFormModal, dispatched via `rule-form:open`.
final class RulesPage extends Component
{
    public ?int $confirmingDeleteId = null;

    public string $flashMessage = '';

    // True from the moment triggerReapply dispatches the job until
    // render() observes a status='done' cache payload; drives the
    // disabled "Re-applying..." button and the poll progress strip.
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
        // A tampered Livewire payload would otherwise surface as a 500;
        // catching NotFoundHttpException here only affects the UI
        // surface — the action's own ownership guard is the real boundary.
        try {
            ($delete)($currentUser->user(), $ruleId);
        } catch (NotFoundHttpException) {
            $this->confirmingDeleteId = null;
            $this->flashMessage = Lang::get('categorization::rules.flash_not_found');

            return;
        }
        $this->confirmingDeleteId = null;
        $this->flashMessage = Lang::get('categorization::rules.flash_deleted');
    }

    #[On('rule-form:saved')]
    public function handleSaved(): void
    {
        $this->flashMessage = Lang::get('categorization::rules.flash_saved');
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    // Always reads the target user id from CurrentUser. Runs via
    // dispatchSync: the job's decryption KEK is only reachable through
    // the live Session on THIS request — a queued dispatch would hand it
    // to the KEK-less queue:work daemon.
    public function triggerReapply(CurrentUser $currentUser, Dispatcher $bus): void
    {
        $bus->dispatchSync(new ReapplyRulesJob($currentUser->user()->id));
        $this->reapplyDispatched = true;
        $this->flashMessage = Lang::get('categorization::rules.flash_reapplying');
    }

    // Intentionally a no-op — Livewire re-renders the component on every
    // poll tick, which re-queries the live progress payload in render().
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
        $view->extends('layouts.app', ['title' => Lang::get('categorization::rules.page_title').' · Beatrax']);

        return $view;
    }

    public static function conditionFragment(RuleConditionDto $condition): string
    {
        $displayField = $condition->valueType === ConditionValueType::Text->value ? $condition->field : $condition->valueType;
        $opLabel = RuleFormModal::operatorOptionsFor($displayField)[$condition->op] ?? $condition->op;

        if ($condition->valueType !== ConditionValueType::Text->value) {
            $value = self::readableValue($condition->valueType, $condition->value);
            $and = Lang::get('categorization::rule_form.between_and');

            if ($condition->op === ConditionOperator::Between->value && $condition->value2 !== null) {
                $upper = self::readableValue($condition->valueType, $condition->value2);

                return "{$displayField} {$opLabel} {$value} {$and} {$upper}";
            }

            return "{$displayField} {$opLabel} {$value}";
        }

        return "{$displayField} {$opLabel} \"{$condition->value}\"";
    }

    // Amount conditions are stored in minor units, and the list printed them
    // raw: a rule the user entered as 10,00-25,00 read back as "1000 and
    // 2500", which could be ten euros or a thousand.
    private static function readableValue(string $valueType, string $value): string
    {
        if ($valueType !== ConditionValueType::Amount->value || ! is_numeric($value)) {
            return $value;
        }

        return MoneyInput::formatMinor((int) $value);
    }

    public static function actionChipLabel(RuleActionDto $action): string
    {
        return match ($action->type) {
            ActionType::Category->value => Lang::get('categorization::rules.chip_category', ['path' => $action->categoryPath ?? '—']),
            ActionType::Counterparty->value => Lang::get('categorization::rules.chip_counterparty', ['path' => $action->counterpartyName ?? '—']),
            ActionType::Note->value => Lang::get('categorization::rules.chip_note'),
            default => Lang::get('categorization::rules.chip_tax_tag'),
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
            return Lang::get('categorization::rules.summary_no_changes');
        }

        $base = Lang::get('categorization::rules.summary_updated', [
            'fields' => $fieldsUpdated,
            'transactions' => $transactionsUpdated,
        ]);

        if ($reconciledSkipped > 0) {
            return $base.' '.Lang::get('categorization::rules.summary_reconciled_skipped', ['count' => $reconciledSkipped]);
        }

        return $base;
    }
}
