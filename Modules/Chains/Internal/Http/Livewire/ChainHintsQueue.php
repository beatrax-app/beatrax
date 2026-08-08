<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Actions\DismissChainLinkHint;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;

// /chains/hints reviews hint-shaped chain_links (to_transaction_id IS
// NULL) that /chains/review filters out, since Confirm/Reject there would
// trip the schema's NULL-endpoint check triggers. Dismiss hard-deletes via
// {@see DismissChainLinkHint}; rows surface newest-first (orderByDesc).
final class ChainHintsQueue extends Component
{
    // Transient notice rendered above the queue on dismiss success or a
    // guard rejection; cleared on every subsequent action call.
    public ?string $statusMessage = null;

    public function dismiss(int $chainLinkId, CurrentUser $currentUser, DismissChainLinkHint $dismiss): void
    {
        $this->statusMessage = null;
        ($dismiss)($chainLinkId, $currentUser->user());
        $this->statusMessage = Lang::get('chains::hints.dismissed');
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
        $view->extends('layouts.app', ['title' => Lang::get('chains::hints.page_title').' · Beatrax']);

        return $view;
    }
}
