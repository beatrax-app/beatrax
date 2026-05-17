<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Actions\UpdateCategorizationRule;
use Modules\Categorization\Public\Services\CategorizationRuleQuery;
use Modules\Categorization\Public\Services\CategoryOptionsQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Global rule-form modal SFC. Mounted once in `app.blade.php` so the
 * `rule-form:open` Livewire event can open the modal from anywhere
 * (the `/rules` page rows + the transaction detail provenance panel
 * both dispatch the same event).
 *
 * The form lays out as a natural-language sentence per UI-SPEC §
 * Form field flow:
 *
 *     When the [merchant ▼] [contains ▼] the value [_______________]
 *     Assign category [Subscriptions / Streaming ▼]
 *                                              [Save rule] [Cancel]
 *
 * Service collaborators arrive as parameters on action methods +
 * the render() / open() listener — never via constructor injection.
 * The strict-rules ruleset banishes constructor DI from Livewire
 * components.
 *
 * Cross-user safety: the open() listener pulls the rule via
 * CategorizationRuleQuery::findForUser which is user-scoped; a
 * foreign ruleId returns null and the modal opens in create mode
 * (no foreign rule data ever lands on the form).
 */
final class RuleFormModal extends Component
{
    public ?int $editingRuleId = null;

    public string $field = '';

    public string $match = '';

    public string $value = '';

    public ?int $categoryId = null;

    public string $errorValue = '';

    public string $errorCategory = '';

    #[On('rule-form:open')]
    public function open(
        CurrentUser $currentUser,
        CategorizationRuleQuery $query,
        ?int $ruleId = null,
    ): void {
        $this->errorValue = '';
        $this->errorCategory = '';

        if ($ruleId === null) {
            $this->editingRuleId = null;
            $this->field = '';
            $this->match = '';
            $this->value = '';
            $this->categoryId = null;

            return;
        }

        $existing = $query->findForUser($currentUser->user(), $ruleId);
        if ($existing === null) {
            // Foreign / missing rule -> fall back to create mode so the
            // modal never renders another user's rule values.
            $this->editingRuleId = null;
            $this->field = '';
            $this->match = '';
            $this->value = '';
            $this->categoryId = null;

            return;
        }

        $this->editingRuleId = $existing->id;
        $this->field = $existing->field;
        $this->match = $existing->match;
        $this->value = $existing->value;
        $this->categoryId = $existing->categoryId;
    }

    public function save(
        CurrentUser $currentUser,
        CreateCategorizationRule $create,
        UpdateCategorizationRule $update,
    ): void {
        $this->errorValue = '';
        $this->errorCategory = '';

        $trimmed = trim($this->value);
        if ($trimmed === '') {
            $this->errorValue = 'Value is required.';

            return;
        }
        if ($this->categoryId === null || $this->categoryId === 0) {
            $this->errorCategory = 'Pick a category to assign.';

            return;
        }
        if ($this->field === '' || $this->match === '') {
            $this->errorValue = 'Value is required.';

            return;
        }

        $user = $currentUser->user();

        try {
            if ($this->editingRuleId === null) {
                $ruleId = ($create)($user, $this->field, $this->match, $trimmed, $this->categoryId);
                $action = 'created';
            } else {
                ($update)($user, $this->editingRuleId, [
                    'field' => $this->field,
                    'match' => $this->match,
                    'value' => $trimmed,
                    'category_id' => $this->categoryId,
                ]);
                $ruleId = $this->editingRuleId;
                $action = 'updated';
            }
        } catch (ValidationException $e) {
            $messages = $e->errors();
            if (isset($messages['value']) && is_array($messages['value']) && $messages['value'] !== []) {
                $first = $messages['value'][0];
                $this->errorValue = is_string($first) ? $first : 'Validation failed.';
            }

            return;
        }

        $this->dispatch('rule-form:saved', ruleId: $ruleId, action: $action);
        $this->dispatch('modal-hide', name: 'rule-form');

        $this->editingRuleId = null;
        $this->field = '';
        $this->match = '';
        $this->value = '';
        $this->categoryId = null;
    }

    public function cancel(): void
    {
        $this->dispatch('modal-hide', name: 'rule-form');
    }

    public function render(
        CurrentUser $currentUser,
        CategoryOptionsQuery $options,
        ViewFactory $views,
    ): View {
        return $views->make('categorization::livewire.rule-form-modal', [
            'categories' => $options->for($currentUser->user()),
            'isEditMode' => $this->editingRuleId !== null,
        ]);
    }
}
