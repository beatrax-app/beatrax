<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inline provenance panel embedded in the transaction detail page,
 * rendered between the Reclassify section and the View chain section.
 *
 * Three variants keyed on the prior auto-category provenance:
 *  - `rule`   — always-visible "Rule that fired" card with the
 *               rule summary + Update rule / Remove rule actions.
 *  - `memory` — lighter "Auto-categorized from merchant history" card
 *               with a single Override action.
 *  - `none`   — render nothing (manual or absent provenance).
 *
 * Remove rule uses the two-step inline confirmation pattern from
 * RulesPage so the user gets the same destructive-action posture as
 * the dedicated rules page.
 *
 * Service collaborators arrive as parameters on action methods +
 * mount() + render() — constructor injection is banned on Livewire
 * components by the strict-rules plugin. No facade usage anywhere
 * in the class body.
 */
final class CategorizationProvenancePanel extends Component
{
    public int $transactionId = 0;

    /** One of `rule`, `memory`, `none`. */
    public string $variant = 'none';

    public ?int $ruleId = null;

    public ?int $categoryId = null;

    public string $field = '';

    public string $match = '';

    public string $value = '';

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
        // ruleId no longer resolves via the user-scoped lookup inside
        // the action — e.g. the rule was deleted in another tab between
        // the panel render and the user clicking Remove rule, or a
        // tampered Livewire payload carries a foreign id. Without this
        // catch the user lands on a 500 / framework error page; the
        // catch lets the panel re-hydrate from the surviving provenance
        // and surface a calm flash message instead. The action's own
        // ownership guard already prevents the delete from succeeding
        // cross-user — the catch only affects the UI surface, not the
        // security boundary.
        try {
            ($delete)($currentUser->user(), $this->ruleId);
        } catch (NotFoundHttpException) {
            $this->flashMessage = 'Rule no longer exists (it may have been deleted in another tab).';
            $this->confirmingRemove = false;
            $this->hydrateFromProvenance($db, $currentUser, $rules);

            return;
        }

        $this->confirmingRemove = false;

        // After deleting the rule the panel falls back to memory /
        // none variant depending on the row's surviving provenance.
        // The transactions.auto_category_provenance JSON still
        // records the historical rule_id; the variant flip happens
        // because the rule no longer resolves via findForUser.
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
            'field' => $this->field,
            'match' => $this->match,
            'value' => $this->value,
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
        $userId = $currentUser->user()->id;

        $raw = $db->connection()
            ->table('transactions')
            ->where('id', $this->transactionId)
            ->where('user_id', $userId)
            ->value('auto_category_provenance');

        if (! is_string($raw) || $raw === '') {
            $this->variant = 'none';
            $this->ruleId = null;

            return;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->variant = 'none';

            return;
        }

        $source = $decoded['source'] ?? null;
        if ($source === 'rule') {
            $ruleIdRaw = $decoded['rule_id'] ?? null;
            $ruleId = is_numeric($ruleIdRaw) ? (int) $ruleIdRaw : 0;
            if ($ruleId !== 0) {
                $dto = $rules->findForUser($currentUser->user(), $ruleId);
                if ($dto !== null) {
                    $this->variant = 'rule';
                    $this->ruleId = $dto->id;
                    $this->categoryId = $dto->categoryId;
                    $this->field = $dto->field;
                    $this->match = $dto->match;
                    $this->value = $dto->value;
                    $this->categoryPath = $dto->categoryPath;

                    return;
                }
            }
            // Rule no longer exists (deleted) — fall through to
            // memory / none.
        }

        if ($source === 'memory') {
            $this->variant = 'memory';
            $this->ruleId = null;

            return;
        }

        $this->variant = 'none';
        $this->ruleId = null;
    }
}
