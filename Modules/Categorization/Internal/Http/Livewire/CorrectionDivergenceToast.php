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
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Global Livewire SFC for the correction-divergence toast.
 * Mounted once in app.blade.php so any page can fire the
 * `correction-divergence:fire` Livewire-local event and surface the
 * toast in the same request lifecycle.
 *
 * Event bridge contract — Livewire-local, NOT broadcaster, NOT
 * session-flash:
 *  - `TransactionDetail::reclassify()` invokes `AssignCategory`
 *    synchronously. AssignCategory dispatches the framework-level
 *    `CategorizationDiverged` event for analytics + audit listeners.
 *  - Immediately afterward, TransactionDetail re-emits a Livewire-
 *    local `correction-divergence:fire` event carrying every field
 *    of the CategorizationDiverged payload (including userId) so
 *    this SFC can render the toast in the same request.
 *
 * The dual-channel pattern keeps the framework event reusable for
 * non-UI consumers (a "audit divergence frequency" listener wouldn't
 * need a Livewire SFC) while the Livewire-local bridge gives the
 * toast a same-request render path. Echo/broadcaster would require
 * Reverb; session-flash would require a redirect, which Livewire's
 * reclassify() does not trigger.
 *
 * Cross-user defence: handleDiverged() compares the
 * payload's `$userId` (5th positional parameter) against
 * `$currentUser->user()->id` (the 6th, method-DI). A mismatch is a
 * silent no-op — local Livewire events should never carry a foreign
 * userId, but the guard makes any future regression fail-safe.
 *
 * Services arrive as method parameters via Livewire's container
 * resolution — no constructor (Component subclasses ban it), no
 * facade usage anywhere (auth() / Auth:: / config() / view() are
 * out of bounds).
 */
final class CorrectionDivergenceToast extends Component
{
    public bool $visible = false;

    public ?int $transactionId = null;

    public ?int $ruleId = null;

    public ?int $oldCategoryId = null;

    public ?int $newCategoryId = null;

    public string $ruleSummary = '';

    public string $oldCategoryPath = '';

    public string $newCategoryPath = '';

    public string $flashMessage = '';

    public function mount(): void
    {
        // No mount-time DB pull — the toast is a transient surface
        // for an in-request divergence event. The hidden default
        // posture is correct first render.
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
        // Cross-user defence: the 5th positional parameter $userId is
        // the assertion subject (the event-carried owner of the rule +
        // transaction). The 6th parameter $currentUser is the oracle
        // (the active authenticated user). A mismatch is a silent
        // no-op.
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

        // Resolve the OLD category's own path directly (rather than
        // reading the rule's current category action) — a rule may
        // carry zero, one, or several category actions, and by the
        // time this fires the rule may already differ from what
        // produced $oldCategoryId at import time.
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
        if ($this->ruleId === null || $this->newCategoryId === null) {
            return;
        }
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        $user = $currentUser->user();
        $rule = $rules->findForUser($user, $this->ruleId);
        if ($rule === null) {
            $this->flashMessage = 'Rule no longer exists.';
            $this->visible = false;
            $this->resetPayload();

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
            ($updateRule)(
                $user,
                $this->ruleId,
                $rule->priority,
                $rule->combinator,
                $rule->active,
                $rule->notes,
                $conditionsPayload,
                $actionsPayload,
            );
        } catch (ValidationException) {
            // UpdateCategorizationRule raises ValidationException on
            // UNIQUE-violation. A category_id-only payload cannot trip
            // that constraint today, but the catch is defence-in-depth
            // against a future allowed-keys expansion. Surface a calm
            // message and keep the toast visible so the user can
            // dismiss manually.
            $this->flashMessage = 'Could not update rule.';

            return;
        } catch (NotFoundHttpException) {
            // UpdateCategorizationRule raises NotFoundHttpException
            // when the supplied ruleId no longer maps to a row visible
            // to the user — deleted in another tab, or a tampered
            // event payload. Hide the toast so the page doesn't keep
            // offering an action that can't complete.
            $this->flashMessage = 'Rule no longer exists.';
            $this->visible = false;
            $this->resetPayload();

            return;
        } catch (InvalidArgumentException) {
            // UpdateCategorizationRule raises InvalidArgumentException
            // when the supplied newCategoryId is not visible to the
            // caller. Unreachable from the normal flow — the divergence
            // event always carries the user's own newCategoryId — but
            // a tampered payload could land here. Surface a calm
            // message and keep the toast.
            $this->flashMessage = 'Invalid category — please refresh the page.';

            return;
        }

        $this->flashMessage = 'Rule updated.';
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
