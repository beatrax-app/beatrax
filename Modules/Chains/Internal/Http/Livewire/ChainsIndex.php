<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;

// /chains lists every non-rejected chain_link ("which chains do I have?"),
// unlike /chains/review (state='candidate' only) or /chains/hints
// (to_transaction_id IS NULL only). No row-level Confirm/Reject here —
// candidates surface via a badge linking to /chains/review instead.
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
        $view->extends('layouts.app', ['title' => Lang::get('chains::index.page_title').' · Beatrax']);

        return $view;
    }
}
