<?php

declare(strict_types=1);

namespace Modules\Transfers\Internal\Listeners;

use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Internal\Exceptions\MismatchedTransferUserException;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

/**
 * @link ../../../../.docs/features/transfers/architecture.md
 * @see PairsTransferLegs::pairOne()
 */
final readonly class PairTransferCandidates
{
    public function __construct(
        private PairsTransferLegs $pairer,
    ) {}

    public function handle(TransactionImported $event): void
    {
        if ($event->transaction->user_id !== $event->user->id) {
            throw new MismatchedTransferUserException(
                'TransactionImported.user.id does not match transaction.user_id — refusing to pair.'
            );
        }

        $this->pairer->pairOne($event->transaction, $event->user);
    }
}
