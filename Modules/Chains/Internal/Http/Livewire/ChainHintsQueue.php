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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Hint-shaped chain_links (to_transaction_id IS NULL) get their own
// queue because Confirm/Reject on /chains/review would trip the
// schema's NULL-endpoint check triggers.
final class ChainHintsQueue extends Component
{
    public ?string $statusMessage = null;

    public function dismiss(int $chainLinkId, CurrentUser $currentUser, DismissChainLinkHint $dismiss): void
    {
        $this->statusMessage = null;
        try {
            ($dismiss)($chainLinkId, $currentUser->user());
        } catch (NotFoundHttpException) {
            // Dismissed on another screen between this render and this click.
            // The row is gone either way, so the queue says so and repaints.
            $this->statusMessage = Lang::get('core::errors.no_longer_here');

            return;
        }
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
