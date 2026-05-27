<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/chains` — the overview surface that lists every non-rejected
 * chain_link for the user.
 *
 * The other Chains surfaces are scope-narrower:
 *
 *  - `/chains/review` only shows `state='candidate'` rows that the
 *    user can confirm or reject.
 *  - `/chains/hints` only shows `to_transaction_id IS NULL` candidate
 *    rows (the matcher hint shape).
 *
 * The user needs a page that answers "which chains do I have?"
 * regardless of state or hint shape. This component renders that —
 * one calm scrolling card per chain link, both legs side-by-side, a
 * confidence chip, and a link to drill into the funded transaction
 * for the chain drawer.
 *
 * No row-level Confirm / Reject affordances here. Candidates surface
 * via the badge on the row plus the existing `/chains/review` queue;
 * splitting the two purposes keeps the overview a "what I have" page
 * rather than a triage queue.
 *
 * Service collaborators arrive as parameters on render(). Constructor
 * injection is banned on Livewire Component subclasses by the
 * project's phpstan-strict-rules convention.
 */
final class ChainsIndex extends Component
{
    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $chains = $query->allChainsForUser($user, limit: 100);

        $view = $views->make('chains::livewire.chains-index', [
            'chains' => $chains,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Chains · beatrax']);

        return $view;
    }
}
