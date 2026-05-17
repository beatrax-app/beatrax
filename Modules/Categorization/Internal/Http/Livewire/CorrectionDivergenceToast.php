<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;

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
        if ($rule !== null) {
            $this->ruleSummary = sprintf(
                '%s %s "%s"',
                $rule->field,
                $rule->match,
                $rule->value,
            );
            $this->oldCategoryPath = $rule->categoryPath;
        } else {
            $this->ruleSummary = '';
            $this->oldCategoryPath = '';
        }
    }

    public function update(
        CurrentUser $currentUser,
        UpdateCategorizationRule $updateRule,
    ): void {
        if ($this->ruleId === null || $this->newCategoryId === null) {
            return;
        }
        if (! $currentUser->isAuthenticated()) {
            return;
        }

        try {
            ($updateRule)($currentUser->user(), $this->ruleId, [
                'category_id' => $this->newCategoryId,
            ]);
        } catch (ValidationException) {
            // UpdateCategorizationRule raises ValidationException on
            // UNIQUE-violation. A category_id-only payload cannot trip
            // that constraint today, but the catch is defence-in-depth
            // against a future allowed-keys expansion. Surface a calm
            // message and keep the toast visible so the user can
            // dismiss manually.
            $this->flashMessage = 'Could not update rule.';

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
