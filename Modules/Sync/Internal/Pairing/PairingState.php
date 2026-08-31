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

    // Only a ceremony still underway lapses with its row's TTL. A confirmed
    // token's trust already lives in device_registry, and confirm() refuses a
    // row whose TTL has lapsed or whose state is terminal, so a confirmed row
    // past its expiry confirmed while it was live.
    public function lapsesOnTtl(): bool
    {
        return match ($this) {
            self::Pending, self::AwaitingConfirm => true,
            self::Confirmed, self::Expired => false,
        };
    }

    // A ceremony still underway. Confirmed and expired are verdicts: prune()
    // deletes them, confirm() refuses them, and a repeated scan may not adopt
    // one. Spelled here because every reader was spelling the same literal
    // pair, and a third in-flight state would have to find each of them.
    /**
     * @return list<string>
     */
    public static function inFlightValues(): array
    {
        return [self::Pending->value, self::AwaitingConfirm->value];
    }
}
