<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Actions\AssignCategory;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategorizationProvenancePanel extends Component
{
    use HoldsFlashMessage;

    public int $transactionId = 0;

    public string $variant = 'none';

    public ?int $ruleId = null;

    public string $conditionSummary = '';

    public string $categoryPath = '';

    public bool $confirmingRemove = false;

    public function mount(
        int $transactionId,
        DatabaseManager $db,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
    ): void {
        $this->transactionId = $transactionId;
        $this->hydrateFromProvenance($db, $currentUser, $rules);
    }

    public function updateRule(): void
    {
        if ($this->ruleId === null) {
            return;
        }
        $this->dispatch('rule-form:open', ruleId: $this->ruleId);
    }

    public function confirmRemove(): void
    {
        $this->confirmingRemove = true;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemove = false;
    }

    public function removeRule(
        CurrentUser $currentUser,
        DeleteCategorizationRule $delete,
        DatabaseManager $db,
        CategorizationRuleQuery $rules,
    ): void {
        if ($this->ruleId === null) {
            return;
        }

        // NotFoundHttpException here means the rule vanished in another tab or
        // the payload carried a foreign id — a calm flash, never a 500.
        try {
            ($delete)($currentUser->user(), $this->ruleId);
        } catch (NotFoundHttpException) {
            $this->flashMessage = Lang::get('categorization::detail.flash_rule_gone');
            $this->confirmingRemove = false;
            $this->hydrateFromProvenance($db, $currentUser, $rules);

            return;
        }

        $this->confirmingRemove = false;

        $this->hydrateFromProvenance($db, $currentUser, $rules);
    }

    public function overrideMemory(): void
    {
        $this->dispatch('inline-category-picker:open', transactionId: $this->transactionId);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('categorization::livewire.categorization-provenance-panel', [
            'variant' => $this->variant,
            'transactionId' => $this->transactionId,
            'ruleId' => $this->ruleId,
            'conditionSummary' => $this->conditionSummary,
            'categoryPath' => $this->categoryPath,
            'confirmingRemove' => $this->confirmingRemove,
            'flashMessage' => $this->flashMessage,
        ]);
    }

    private function hydrateFromProvenance(
        DatabaseManager $db,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
    ): void {
        // readPriorProvenance returns null for a missing, empty, or corrupt
        // payload, so a poisoned column renders 'none' instead of throwing.
        $decoded = AssignCategory::readPriorProvenance($db, $this->transactionId, $currentUser->user()->id);
        if ($decoded === null) {
            $this->applyEmptyVariant('none');

            return;
        }

        $source = $decoded['source'] ?? null;
        if ($source === 'rule' && $this->hydrateRuleVariant($decoded, $currentUser, $rules)) {
            return;
        }

        // A 'rule' whose row no longer resolves lands here too: the deleted
        // rule_id still sits in the JSON, so it degrades to 'none'.
        $this->applyEmptyVariant($source === 'memory' ? 'memory' : 'none');
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function hydrateRuleVariant(
        array $decoded,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
    ): bool {
        $ruleIdRaw = $decoded['rule_id'] ?? null;
        $ruleId = is_numeric($ruleIdRaw) ? (int) $ruleIdRaw : 0;
        if ($ruleId === 0) {
            return false;
        }

        $dto = $rules->findForUser($currentUser->user(), $ruleId);
        if ($dto === null) {
            return false;
        }

        $this->variant = 'rule';
        $this->ruleId = $dto->id;
        $this->conditionSummary = $dto->conditions === []
            ? ''
            : RulesPage::conditionFragment($dto->conditions[0]);
        $this->categoryPath = self::categoryPathOf($dto->actions);

        return true;
    }

    /**
     * @param  list<RuleActionDto>  $actions
     */
    private static function categoryPathOf(array $actions): string
    {
        foreach ($actions as $action) {
            if ($action->type === 'category') {
                return $action->categoryPath ?? '';
            }
        }

        return '';
    }

    private function applyEmptyVariant(string $variant): void
    {
        $this->variant = $variant;
        $this->ruleId = null;
    }
}
