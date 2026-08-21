<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

final class PairingStateMachine
{
    public function bothConfirmed(?string $initiatorConfirmedAt, ?string $responderConfirmedAt): bool
    {
        return $initiatorConfirmedAt !== null && $responderConfirmedAt !== null;
    }
}
