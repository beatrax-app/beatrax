<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Categorization\Public\Actions\CreateCategorizationRule;
use Modules\Categorization\Public\Dto\RuleInput;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Services\PatternGeneralizer;

/**
 * @link ../../../../../.docs/features/import/architecture.md#rename-counterparty-popover
 */
final class RenameCounterpartyPopover extends Component
{
    public string $raw = '';

    public string $friendly = '';

    public bool $remember = true;

    public string $generalized = '';

    public ?int $categoryHint = null;

    public int $rowIndex = -1;

    #[On('rename-counterparty:open')]
    public function open(
        string $raw,
        int $rowIndex,
        PatternGeneralizer $generalizer,
        ?int $currentCategoryId = null,
    ): void {
        $this->resetErrorBag();

        $this->raw = $raw;
        $this->rowIndex = $rowIndex;
        $this->friendly = '';
        $this->remember = true;
        $this->generalized = $generalizer->generalize($raw);
        $this->categoryHint = $currentCategoryId;

        $this->dispatch('modal-show', name: 'rename-counterparty');
    }

    public function save(
        CreateMerchantAlias $createAlias,
        CreateCategorizationRule $createRule,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): void {
        $this->validate([
            'friendly' => ['required', 'string', 'min:1', 'max:255'],
            'raw' => ['required', 'string', 'min:1'],
            'generalized' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $currentUser->user();
        $friendlyTrimmed = trim($this->friendly);
        $generalizedTrimmed = trim($this->generalized);

        if ($this->remember) {
            ($createAlias)(
                $user,
                $this->raw,
                $generalizedTrimmed === '' ? null : $generalizedTrimmed,
                $friendlyTrimmed,
            );
        }

        if ($this->categoryHint !== null && $this->categoryHint > 0 && $generalizedTrimmed !== '') {
            // Gap-numbered priority default (13.4-UI-SPEC.md § Priority
            // field): appends after every existing rule for this user,
            // mirroring RuleFormModal's create-mode default.
            $maxPriority = $db->connection()
                ->table('categorization_rules')
                ->where('user_id', $user->id)
                ->max('priority');
            $priority = (is_numeric($maxPriority) ? (int) $maxPriority : 0) + 10;

            try {
                ($createRule)($user, new RuleInput(
                    priority: $priority,
                    combinator: 'all',
                    active: true,
                    notes: null,
                    conditions: [['field' => 'description', 'op' => 'contains', 'value_type' => 'string', 'value' => $generalizedTrimmed]],
                    actions: [['type' => 'category', 'payload' => ['category_id' => $this->categoryHint]]],
                ));
            } catch (ValidationException) {
                // A zero-condition/zero-action rejection can't happen
                // here (both are always supplied); a duplicate-rule
                // signal is benign — the alias already persisted, so
                // swallow it and let the popover close calmly.
            } catch (InvalidArgumentException) {
                // CreateCategorizationRule throws InvalidArgumentException
                // when the embedded category_id fails assertCategoryVisible()
                // — reachable if a stale/tampered categoryHint arrives.
                // Swallow it; the alias already persisted successfully.
            }
        }

        $this->dispatch(
            'rename-counterparty:saved',
            rowIndex: $this->rowIndex,
            friendlyName: $friendlyTrimmed,
        );
        $this->dispatch('modal-close', name: 'rename-counterparty');
    }

    public function cancel(): void
    {
        $this->dispatch('modal-close', name: 'rename-counterparty');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('import::livewire.rename-counterparty-popover');
    }
}
