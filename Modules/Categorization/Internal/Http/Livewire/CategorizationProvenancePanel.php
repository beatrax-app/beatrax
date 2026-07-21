<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Livewire\Component;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;
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
            $this->flashMessage = 'Rule no longer exists (it may have been deleted in another tab).';
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

        // auto_category_provenance is best-effort audit metadata — a
        // corrupt JSON payload must NOT crash the transaction detail
        // render; the JsonException catch falls back to the 'none'
        // variant so the panel renders empty instead of throwing.
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->variant = 'none';
            $this->ruleId = null;

            return;
        }
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
                    $this->conditionSummary = $dto->conditions === []
                        ? ''
                        : RulesPage::conditionFragment($dto->conditions[0]);

                    $categoryPath = '';
                    foreach ($dto->actions as $action) {
                        if ($action->type === 'category') {
                            $categoryPath = $action->categoryPath ?? '';

                            break;
                        }
                    }
                    $this->categoryPath = $categoryPath;

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
