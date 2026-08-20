<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleConditionDto;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Mounted once in app.blade.php. TransactionDetail::reclassify() invokes
// AssignCategory, which dispatches CategorizationDiverged, then re-emits
// a Livewire-local `correction-divergence:fire` event this SFC listens
// for — Echo would require Reverb, session-flash would require a redirect.
final class CorrectionDivergenceToast extends Component
{
    use HoldsFlashMessage;

    public bool $visible = false;

    public ?int $transactionId = null;

    public ?int $ruleId = null;

    public ?int $oldCategoryId = null;

    public ?int $newCategoryId = null;

    public string $ruleSummary = '';

    public string $oldCategoryPath = '';

    public string $newCategoryPath = '';

    public function mount(): void
    {
        $this->visible = false;
    }

    #[On('correction-divergence:fire')]
    public function handleDiverged(
        int $transactionId,
        int $ruleId,
        int $oldCategoryId,
        int $newCategoryId,
        int $userId,
        CurrentUser $currentUser,
        CategorizationRuleQuery $rules,
        CategoryOptionsQuery $categoryOptions,
    ): void {
        // Cross-user defence: $userId is the event-carried owner of the
        // rule + transaction; $currentUser is the active authenticated
        // user. A mismatch is a silent no-op.
        if (! $currentUser->isAuthenticated() || $currentUser->id() !== $userId) {
            return;
        }

        $this->transactionId = $transactionId;
        $this->ruleId = $ruleId;
        $this->oldCategoryId = $oldCategoryId;
        $this->newCategoryId = $newCategoryId;
        $this->visible = true;
        $this->flashMessage = '';

        $rule = $rules->findForUser($currentUser->user(), $ruleId);
        $this->ruleSummary = $rule !== null && $rule->conditions !== []
            ? RulesPage::conditionFragment($rule->conditions[0])
            : '';

        // Resolve the OLD category's own path directly rather than
        // reading the rule's current category action — by the time this
        // fires the rule may already differ from what produced
        // $oldCategoryId at import time.
        $this->oldCategoryPath = '';
        foreach ($categoryOptions->for($currentUser->user()) as $option) {
            if ($option->id === $oldCategoryId) {
                $this->oldCategoryPath = $option->path;

                break;
            }
        }
    }

    public function update(
        CurrentUser $currentUser,
        UpdateCategorizationRule $updateRule,
        CategorizationRuleQuery $rules,
    ): void {
        if ($this->ruleId === null || $this->newCategoryId === null || ! $currentUser->isAuthenticated()) {
            return;
        }

        $user = $currentUser->user();
        $rule = $rules->findForUser($user, $this->ruleId);
        if ($rule === null) {
            $this->dismissWith(Lang::get('categorization::detail.flash_rule_gone_short'));

            return;
        }

        $conditionsPayload = array_map(static fn (RuleConditionDto $c): array => [
            'id' => $c->id,
            'field' => $c->field,
            'op' => $c->op,
            'value_type' => $c->valueType,
            'value' => $c->value,
            'value2' => $c->value2,
        ], $rule->conditions);

        // Re-target every category-type action at the new category —
        // the divergence event's whole premise is "the rule assigned
        // the wrong category"; every other action/condition on the
        // rule is preserved verbatim.
        $actionsPayload = [];
        foreach ($rule->actions as $index => $action) {
            $payload = $action->type === 'category'
                ? ['category_id' => $this->newCategoryId]
                : $action->payload;

            $actionsPayload[] = [
                'id' => $action->id,
                'position' => $index,
                'type' => $action->type,
                'payload' => $payload,
            ];
        }

        try {
            ($updateRule)($user, $this->ruleId, new RuleInput(
                priority: $rule->priority,
                combinator: $rule->combinator,
                active: $rule->active,
                notes: $rule->notes,
                conditions: $conditionsPayload,
                actions: $actionsPayload,
            ));
            $this->dismissWith(Lang::get('categorization::detail.flash_rule_updated'));
        } catch (ValidationException|NotFoundHttpException|InvalidArgumentException $e) {
            // NotFoundHttpException dismisses the toast — the ruleId maps to no
            // visible row (deleted elsewhere, or tampered). ValidationException
            // (a future allowed-keys expansion) and InvalidArgumentException (a
            // tampered category id) are unreachable normally and leave it open.
            if ($e instanceof NotFoundHttpException) {
                $this->dismissWith(Lang::get('categorization::detail.flash_rule_gone_short'));
            } else {
                $this->flashMessage = $e instanceof ValidationException
                    ? Lang::get('categorization::detail.flash_could_not_update')
                    : Lang::get('categorization::detail.flash_invalid_category');
            }
        }
    }

    private function dismissWith(string $message): void
    {
        $this->flashMessage = $message;
        $this->visible = false;
        $this->resetPayload();
    }

    public function dismiss(): void
    {
        $this->visible = false;
        $this->resetPayload();
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('categorization::livewire.correction-divergence-toast', [
            'visible' => $this->visible,
            'ruleSummary' => $this->ruleSummary,
            'oldCategoryPath' => $this->oldCategoryPath,
            'newCategoryPath' => $this->newCategoryPath,
            'flashMessage' => $this->flashMessage,
        ]);
    }

    private function resetPayload(): void
    {
        $this->transactionId = null;
        $this->ruleId = null;
        $this->oldCategoryId = null;
        $this->newCategoryId = null;
        $this->ruleSummary = '';
        $this->oldCategoryPath = '';
        $this->newCategoryPath = '';
    }
}
