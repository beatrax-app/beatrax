<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Exceptions\ChainLinkNotDismissableException;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DismissChainLinkHint
{
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

        $link->delete();
    }
}
