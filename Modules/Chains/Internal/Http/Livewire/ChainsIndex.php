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

// /chains lists the newest settlements and the non-rejected links that fed
// them, unlike /chains/review (candidates only) and /chains/hints (NULL
// endpoints only). The card's count and totals come from the second read: it
// covers every leg, while the first stops at the legs the card lists.
final class ChainsIndex extends Component
{
    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $view = $views->make('chains::livewire.chains-index', [
            'settlements' => SettlementGroup::fromRows(
                $query->allChainsForUser($user),
                $query->settlementTotalsForUser($user),
            ),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('chains::index.page_title').' · Beatrax']);

        return $view;
    }
}
