<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Categorization\Internal\Actions\AssignCategory;
use Modules\Categorization\Internal\Http\Livewire\RulesPage;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Dto\CategoryOption;
use Modules\Categorization\Public\Dto\RuleActionDto;
use Modules\Categorization\Public\Enums\ActionType;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CategorizationProvenancePanel extends Component
{
    use HoldsFlashMessage;

    // Both are mount-assigned and server-derived, and removeRule() deletes by
    // the rule id it finds here. An action method runs before render(), so an
    // unlocked pair would let the browser name the row it acts on.
    #[Locked]
    public int $transactionId = 0;

    public string $variant = 'none';

    #[Locked]
    public ?int $ruleId = null;

    public string $conditionSummary = '';

    public string $categoryPath = '';

    public bool $confirmingRemove = false;

    public bool $overriding = false;

    public ?int $overrideCategoryId = null;

    // The category the reader put the row in, when it is not the one the rule
    // named and the rule is still active. Empty otherwise: the card renders
    // identically on agreement and on divergence without it, so the reader who
    // overruled a live rule was shown exactly what the reader who agreed was.
    public string $divergedFromRuleInto = '';

    // Hydration-scoped, not Livewire state: findForUser() answers for a rule
    // whether or not it can still fire, and the flag it carries was read and
    // dropped. A disabled rule is not a contradiction to raise.
    private bool $ruleCanStillFire = false;

    public function mount(
        int $transactionId,
        DatabaseManager $db,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
        CategoryOptionsQuery $categories,
    ): void {
        $this->transactionId = $transactionId;
        $this->hydrateFromProvenance($db, $currentUser, $rules, $categories);
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
        CategoryOptionsQuery $categories,
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
            $this->hydrateFromProvenance($db, $currentUser, $rules, $categories);

            return;
        }

        $this->confirmingRemove = false;

        $this->hydrateFromProvenance($db, $currentUser, $rules, $categories);
    }

    // Reveals the picker in place rather than announcing it. This used to
    // dispatch `inline-category-picker:open`; the picker mounts per row on
    // the transactions list and declares no listener, so the only action the
    // memory card offers reached nothing and did nothing.
    public function overrideMemory(DatabaseManager $db, CurrentUser $currentUser): void
    {
        $persisted = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->value('category_id');

        $this->overrideCategoryId = is_numeric($persisted) ? (int) $persisted : null;
        $this->overriding = true;
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
            'overriding' => $this->overriding,
            'overrideCategoryId' => $this->overrideCategoryId,
            'divergedFromRuleInto' => $this->divergedFromRuleInto,
            'flashMessage' => $this->flashMessage,
        ]);
    }

    private function hydrateFromProvenance(
        DatabaseManager $db,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
        CategoryOptionsQuery $categories,
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
            $this->divergedFromRuleInto = $this->divergenceLabel($decoded, $db, $currentUser, $categories);

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
        $this->ruleCanStillFire = $dto->active;
        $this->conditionSummary = $dto->conditions === []
            ? ''
            : RulesPage::conditionFragment($dto->conditions[0]);
        $this->categoryPath = self::categoryPathOf($dto->actions);

        return true;
    }

    // The rule's own answer against the row's, and only while the rule can
    // still fire: a rule the reader disabled is not a contradiction, and
    // memory divergence is silent by design because memory relearns. Returns
    // the reader's category so the sentence can name both sides.
    /**
     * @param  array<string, mixed>  $decoded
     */
    private function divergenceLabel(
        array $decoded,
        DatabaseManager $db,
        CurrentUser $currentUser,
        CategoryOptionsQuery $categories,
    ): string {
        $user = $currentUser->user();
        $ruleCategoryRaw = $decoded['category_id'] ?? null;
        $ruleCategoryId = is_numeric($ruleCategoryRaw) ? (int) $ruleCategoryRaw : null;

        $chosenRaw = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $user->id)
            ->value('category_id');
        $chosenId = is_numeric($chosenRaw) ? (int) $chosenRaw : null;

        if (! $this->ruleCanStillFire || $ruleCategoryId === null || $chosenId === null || $chosenId === $ruleCategoryId) {
            return '';
        }

        return self::pathOf($categories->for($user), $chosenId);
    }

    /**
     * @param  list<CategoryOption>  $options
     */
    private static function pathOf(array $options, int $categoryId): string
    {
        foreach ($options as $option) {
            if ($option->id === $categoryId) {
                return $option->path;
            }
        }

        return '';
    }

    /**
     * @param  list<RuleActionDto>  $actions
     */
    private static function categoryPathOf(array $actions): string
    {
        foreach ($actions as $action) {
            if ($action->type === ActionType::Category->value) {
                return $action->categoryPath ?? '';
            }
        }

        return '';
    }

    private function applyEmptyVariant(string $variant): void
    {
        $this->variant = $variant;
        $this->ruleId = null;
        $this->ruleCanStillFire = false;
        $this->divergedFromRuleInto = '';
    }
}
