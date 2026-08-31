<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ChainReviewQueue extends Component
{
    // One row beyond the page is read and then dropped: it is the only thing
    // that separates "the page is full" from "there is a next page", and
    // rendering it was what put a Show more button on an exactly-full page
    // whose next page came back empty.
    private const int PAGE_SIZE = 25;

    // Cursor pair: previous page's tail chain_link.id + confidence
    // (3 decimals, matching the column type). Null on the first page;
    // paired together so the sort stays stable across ties on confidence.
    public ?int $cursorId = null;

    public ?string $cursorConfidence = null;

    // Set when an action raises {@see ChainLinkRequiresConcretePartnerException}
    // (a hint-shaped candidate with to_transaction_id NULL); cleared before
    // every subsequent confirm/reject attempt.
    public ?string $actionError = null;

    // The second catch is the two-tab case: the candidate was confirmed or
    // rejected on another screen between this render and this click, and the
    // action answers a gone row by throwing. The queue re-renders without it.
    public function confirm(int $chainLinkId, CurrentUser $currentUser, ConfirmChainLink $confirm): void
    {
        $this->actionError = null;
        try {
            ($confirm)($chainLinkId, $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = Lang::get('chains::review.errors.confirm_hint');
        } catch (NotFoundHttpException) {
            $this->actionError = Lang::get('core::errors.no_longer_here');
        }
    }

    public function reject(int $chainLinkId, CurrentUser $currentUser, RejectChainLink $reject): void
    {
        $this->actionError = null;
        try {
            ($reject)($chainLinkId, $currentUser->user());
        } catch (ChainLinkRequiresConcretePartnerException) {
            $this->actionError = Lang::get('chains::review.errors.reject_hint');
        } catch (NotFoundHttpException) {
            $this->actionError = Lang::get('core::errors.no_longer_here');
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
        $page = $query->candidatesForReview(
            user: $user,
            cursorId: $this->cursorId,
            cursorConfidence: $this->cursorConfidence,
            limit: self::PAGE_SIZE + 1,
        );
        $hasMore = count($page) > self::PAGE_SIZE;
        $candidates = array_slice($page, 0, self::PAGE_SIZE);
        // Gates the /chains/hints link in the queue header, so it is never a dead
        // link to an empty page.
        $hintCount = $query->hintCount($user);

        $view = $views->make('chains::livewire.chain-review-queue', [
            'candidates' => $candidates,
            'hasMore' => $hasMore,
            'hintCount' => $hintCount,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('chains::review.page_title').' · Beatrax']);

        return $view;
    }
}
