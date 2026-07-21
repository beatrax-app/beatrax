<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * @link ../../../../../.docs/features/chains/architecture.md
 */
final class ChainReviewQueue extends Component
{
    // Cursor pair: previous page's tail chain_link.id + confidence
    // (3 decimals, matching the column type). Null on the first page;
    // paired together so the sort stays stable across ties on confidence.
    public ?int $cursorId = null;

    public ?string $cursorConfidence = null;

    // Set when an action raises {@see ChainLinkRequiresConcretePartnerException}
    // (a hint-shaped candidate with to_transaction_id NULL); cleared before
    // every subsequent confirm/reject attempt.
    public ?string $actionError = null;

    public function confirm(int $chainLinkId, CurrentUser $currentUser, ConfirmChainLink $confirm): void
    {
        $this->actionError = null;
        try {
            ($confirm)($chainLinkId, $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = 'This candidate is a hint — open it to attach the matching transaction before confirming.';
        }
    }

    public function reject(int $chainLinkId, CurrentUser $currentUser, RejectChainLink $reject): void
    {
        $this->actionError = null;
        try {
            ($reject)($chainLinkId, $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = 'This candidate is a hint — open it to attach the matching transaction before rejecting.';
        }
    }

    public function loadMore(int $nextCursorId, ?string $nextCursorConfidence = null): void
    {
        $this->cursorId = $nextCursorId;
        $this->cursorConfidence = $nextCursorConfidence;
    }

    public function render(
        CurrentUser $currentUser,
        ChainLinkQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $candidates = $query->candidatesForReview(
            user: $user,
            cursorId: $this->cursorId,
            cursorConfidence: $this->cursorConfidence,
            limit: 26,
        );
        // Cheap pre-render badge — surfaces the link to /chains/hints
        // only when the user actually has hints to triage. Avoids
        // polluting the queue header with a dead link.
        $hintCount = $query->hintCount($user);

        $view = $views->make('chains::livewire.chain-review-queue', [
            'candidates' => $candidates,
            'hintCount' => $hintCount,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Review chains · beatrax']);

        return $view;
    }
}
