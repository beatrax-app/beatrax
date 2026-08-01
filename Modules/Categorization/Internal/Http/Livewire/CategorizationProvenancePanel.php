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
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Three variants keyed on the prior auto-category provenance: `rule`
// (rule summary + Update/Remove), `memory` (Override action), `none`
// (render nothing). Remove rule uses RulesPage's two-step inline
// confirmation pattern for the same destructive-action posture.
final class CategorizationProvenancePanel extends Component
{
    public int $transactionId = 0;

    public string $variant = 'none';

    public ?int $ruleId = null;

    public string $conditionSummary = '';

    public string $categoryPath = '';

    public bool $confirmingRemove = false;

    public string $flashMessage = '';

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

        // DeleteCategorizationRule throws NotFoundHttpException when the
        // rule was deleted in another tab or a tampered payload carries a
        // foreign id. The catch re-hydrates the panel and surfaces a calm
        // flash message instead of a 500 — it only affects the UI surface.
        try {
            ($delete)($currentUser->user(), $this->ruleId);
        } catch (NotFoundHttpException) {
            $this->flashMessage = Lang::get('categorization::detail.flash_rule_gone');
            $this->confirmingRemove = false;
            $this->hydrateFromProvenance($db, $currentUser, $rules);

            return;
        }

        $this->confirmingRemove = false;

        // The panel falls back to memory/none because the rule no
        // longer resolves via findForUser, even though the historical
        // rule_id still sits in auto_category_provenance.
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
        // AssignCategory::readPriorProvenance owns the read-and-decode shape,
        // returning null for a missing, empty, or corrupt payload — the panel
        // then renders the empty 'none' variant rather than throwing mid
        // transaction-detail render.
        $decoded = AssignCategory::readPriorProvenance($db, $this->transactionId, $currentUser->user()->id);
        if ($decoded === null) {
            $this->applyEmptyVariant('none');

            return;
        }

        $source = $decoded['source'] ?? null;
        if ($source === 'rule' && $this->hydrateRuleVariant($decoded, $currentUser, $rules)) {
            return;
        }

        // A 'memory' provenance shows the Override action; a 'rule' whose row no
        // longer resolves (deleted — the historical rule_id still sits in the
        // JSON) falls through here to 'none' alongside every unknown source.
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
