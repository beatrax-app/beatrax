<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\DeleteCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * The `/rules` route landing — the categorization-rule CRUD page.
 *
 * Reads via CategorizationRuleQuery (user-scoped); mutates via the
 * three Public actions (Create/Update/Delete). The page itself owns
 * the table render + the per-row Edit / Delete chip flow; the actual
 * form lives in RuleFormModal which is mounted globally inside
 * `app.blade.php` and dispatched via the `rule-form:open` Livewire
 * event.
 *
 * Inline two-step delete confirmation: clicking Delete sets
 * `$confirmingDeleteId` to the row id; the Blade renders the
 * `[Delete?] [Yes, delete] [Cancel]` chip pair in place of the
 * default `[Edit] [Delete]` row. Cancel clears the flag; Yes invokes
 * DeleteCategorizationRule and re-renders the row list.
 *
 * Constructor-free Livewire component; service collaborators arrive
 * as parameters on action methods + render(). The strict-rules
 * ruleset forbids property-based constructor injection on Component
 * subclasses, and `auth()` / `Auth::user()` / facade lookups are
 * out of bounds project-wide.
 */
final class RulesPage extends Component
{
    public ?int $confirmingDeleteId = null;

    public string $flashMessage = '';

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
        ($delete)($currentUser->user(), $ruleId);
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

    public function render(CurrentUser $currentUser, CategorizationRuleQuery $query, ViewFactory $views): View
    {
        $rules = $query->forUser($currentUser->user());

        $view = $views->make('categorization::livewire.rules-page', [
            'rules' => $rules,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Rules · diederik']);

        return $view;
    }
}
