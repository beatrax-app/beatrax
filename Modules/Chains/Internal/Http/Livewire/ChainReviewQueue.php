<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Chains\Public\Actions\ConfirmChainLink;
use Modules\Chains\Public\Actions\RejectChainLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * `/chains/review` page — the batch review surface for
 * `state='candidate'` chain_links across all the user's transactions
 * (CHN-03 / D-86 / D-87).
 *
 * Rows are sorted by `confidence DESC, id DESC` and cursor-paginated
 * via `ChainLinkQuery::candidatesForReview`. Each row renders the
 * from / to counterparties, a `kind_label` ("PayPal funding" /
 * "Bulk iDEAL settlement"), and Confirm + Reject buttons.
 *
 * The Confirm / Reject buttons delegate to the same Public action
 * classes the chain drawer uses (D-86 dual-surface contract):
 * `ConfirmChainLink::__invoke($chainLinkId, $user)` /
 * `RejectChainLink::__invoke($chainLinkId, $user)`. Both raise
 * `NotFoundHttpException` (404) when the target row belongs to a
 * different user — verified by `CrossUserChainLinkIsolationTest`.
 *
 * Auto-promotion hint (D-87): when a row's `confirmsRemaining === 1`
 * (the user has two prior same-signature confirmations and this would
 * be the third), the Blade renders the inline copy
 * "One more confirm and similar links auto-confirm." The hint is the
 * only proactive UX nudge about the learning loop.
 *
 * Service collaborators arrive as parameters on action methods + the
 * `render()` method. Constructor injection is banned on Livewire
 * Component subclasses by phpstan-strict-rules.
 */
final class ChainReviewQueue extends Component
{
    /**
     * Cursor: the previous page's tail `chain_link.id`. Null = first
     * page. Used in combination with `$cursorConfidence` to keep the
     * sort stable when multiple rows share the same confidence value.
     */
    public ?int $cursorId = null;

    /**
     * Cursor: the previous page's tail `confidence` (formatted to 3
     * decimal places to match the column type). Null = first page.
     */
    public ?string $cursorConfidence = null;

    public function confirm(int $chainLinkId, CurrentUser $currentUser, ConfirmChainLink $confirm): void
    {
        ($confirm)($chainLinkId, $currentUser->user());
    }

    public function reject(int $chainLinkId, CurrentUser $currentUser, RejectChainLink $reject): void
    {
        ($reject)($chainLinkId, $currentUser->user());
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

        $view = $views->make('chains::livewire.chain-review-queue', [
            'candidates' => $candidates,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Review chains · diederik']);

        return $view;
    }
}
