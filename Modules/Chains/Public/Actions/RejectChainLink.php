<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RejectChainLink
{
    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            // Thrown explicitly so the contract is testable outside the
            // HTTP layer, where the framework isn't there to convert it.
            throw new NotFoundHttpException('Chain link not found.');
        }

        if ($link->to_transaction_id === null) {
            // Symmetric guard with ConfirmChainLink: hint-shaped rows
            // permit a NULL endpoint only while candidate; the schema
            // trigger refuses to flip them to rejected. Trip the typed
            // exception before the save so the caller renders a readable message.
            throw ChainLinkRequiresConcretePartnerException::from($link);
        }

        $link->state = ChainLinkState::Rejected->value;
        $link->save();
    }
}
