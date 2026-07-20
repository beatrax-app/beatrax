<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class PairingStateMachine
{
    // A pairing token moves through pending -> awaiting_confirm -> confirmed,
    // and may fall to `expired` at any point its TTL elapses. This class
    // holds NO state and performs NO I/O — a deterministic decision helper
    // PairingTokenService and the Livewire flow share.
    public const string PENDING = 'pending';

    public const string AWAITING_CONFIRM = 'awaiting_confirm';

    public const string CONFIRMED = 'confirmed';

    public const string EXPIRED = 'expired';

    // A token may only be accepted while still pending — the single-use
    // guard: once accepted it leaves `pending`, so a replayed accept of the
    // same token can never advance it again.
    public function canAccept(string $state): bool
    {
        return $state === self::PENDING;
    }

    public function bothConfirmed(?string $initiatorConfirmedAt, ?string $responderConfirmedAt): bool
    {
        return $initiatorConfirmedAt !== null && $responderConfirmedAt !== null;
    }

    public function isExpired(string $expiresAt, CarbonImmutable $now): bool
    {
        return $now->greaterThan(CarbonImmutable::parse($expiresAt));
    }

    public function nextAfterConfirm(?string $initiatorConfirmedAt, ?string $responderConfirmedAt): string
    {
        return $this->bothConfirmed($initiatorConfirmedAt, $responderConfirmedAt)
            ? self::CONFIRMED
            : self::AWAITING_CONFIRM;
    }
}
