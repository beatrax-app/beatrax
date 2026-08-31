<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Chains\Internal\Exceptions\ChainLinkNotDismissableException;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DismissChainLinkHint
{
    public function __construct(private Dispatcher $events) {}

    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            throw new NotFoundHttpException('Chain link not found.');
        }

        if ($link->to_transaction_id !== null) {
            // Only hint-shaped rows are dismissable; concrete rows
            // route through ConfirmChainLink / RejectChainLink.
            throw new ChainLinkNotDismissableException($chainLinkId);
        }

        $linkId = $link->id;
        $link->delete();

        // A dismissal deletes the row rather than flagging it, so the op the
        // peer needs is the tombstone: without it the hint the reader waved
        // away on the desktop is still sitting in the phone's queue.
        $this->events->dispatch(new EntityMutated(
            table: 'chain_links',
            pk: $linkId,
            userId: $user->id,
            mutationType: 'delete',
        ));
    }
}
