<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Internal\Presentation\SettlementGroup;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;

// /chains lists every non-rejected chain_link, unlike /chains/review
// (candidates only) and /chains/hints (NULL endpoints only).
final class ChainsIndex extends Component
{
    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $chains = $query->allChainsForUser($user, limit: 100);

// Grouped by the charge each link settles into: one flat row per link
// repeated the same settlement once per card.
        $view = $views->make('chains::livewire.chains-index', [
            'settlements' => SettlementGroup::fromRows($chains),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('chains::index.page_title').' · Beatrax']);

        return $view;
    }
}
