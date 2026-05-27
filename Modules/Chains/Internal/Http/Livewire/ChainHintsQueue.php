<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/chains/hints` — the dedicated review surface for hint-shaped
 * chain_links (rows whose `to_transaction_id IS NULL`).
 *
 * The standard `/chains/review` page filters these rows out because
 * its Confirm / Reject buttons would trip the schema's
 * chain_links_to_transaction_id_check_* triggers. This component
 * gives the user a way to triage them — currently a single Dismiss
 * action (hard-delete via {@see DismissChainLinkHint}). A future
 * iteration can add a per-kind "Attach a transaction" picker that
 * promotes the hint into a regular candidate the standard queue can
 * confirm.
 *
 * Rows surface oldest-of-current-batch last (orderByDesc) so a fresh
 * import that produced new hints sees them at the top of the page.
 *
 * Service collaborators arrive as parameters on action methods + the
 * `render()` method. Constructor injection is banned on Livewire
 * Component subclasses by the project's phpstan-strict-rules
 * convention.
 */
final class ChainHintsQueue extends Component
{
    /**
     * Transient notice rendered above the queue on dismiss success
     * or a guard rejection. Cleared on every subsequent action call.
     */
    public ?string $statusMessage = null;

    public function dismiss(int $chainLinkId, CurrentUser $currentUser, DismissChainLinkHint $dismiss): void
    {
        $this->statusMessage = null;
        ($dismiss)($chainLinkId, $currentUser->user());
        $this->statusMessage = 'Hint dismissed.';
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $hints = $query->hintsForReview($user);

        $view = $views->make('chains::livewire.chain-hints-queue', [
            'hints' => $hints,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Chain hints · beatrax']);

        return $view;
    }
}
