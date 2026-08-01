<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The lifecycle of a pairing token: pending -> awaiting_confirm -> confirmed,
// dropping to expired whenever its TTL elapses. The `state` column stays a
// string; this enum is the one canonical spelling PairingStateMachine, the
// token service, and the Livewire flow decide against.
enum PairingState: string
{
    case Pending = 'pending';

    case AwaitingConfirm = 'awaiting_confirm';

    case Confirmed = 'confirmed';

    case Expired = 'expired';
}
