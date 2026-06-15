<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use Carbon\CarbonImmutable;

/**
 * Pure transition logic for the device-pairing handshake (D-07/D-13).
 *
 * A pairing token moves through:
 *   pending → awaiting_confirm → confirmed
 * and may fall to `expired` at any point its TTL elapses.
 *
 * The CONFIRMED transition is the trust gate: it is only reachable once BOTH
 * the initiator and responder have confirmed the safety-number on their own
 * screens (D-07, mandatory both-screen confirmation). Plan 04 consumes
 * {@see self::nextAfterConfirm()} to decide when device_registry.confirmed_at
 * may be set.
 *
 * This class holds NO state and performs NO I/O — it is a deterministic
 * decision helper the PairingTokenService and the Livewire flow share.
 */
final class PairingStateMachine
{
    public const string PENDING = 'pending';

    public const string AWAITING_CONFIRM = 'awaiting_confirm';

    public const string CONFIRMED = 'confirmed';

    public const string EXPIRED = 'expired';

    /**
     * A token may only be accepted while it is still pending — this is the
     * single-use guard (T-12-10): once accepted it leaves `pending`, so a
     * replayed accept of the same token can never advance it again.
     */
    public function canAccept(string $state): bool
    {
        return $state === self::PENDING;
    }

    /**
     * Both sides have confirmed the safety-number — the precondition for trust.
     */
    public function bothConfirmed(?string $initiatorConfirmedAt, ?string $responderConfirmedAt): bool
    {
        return $initiatorConfirmedAt !== null && $responderConfirmedAt !== null;
    }

    /**
     * Whether the token's TTL has elapsed relative to the injected clock.
     */
    public function isExpired(string $expiresAt, CarbonImmutable $now): bool
    {
        return $now->greaterThan(CarbonImmutable::parse($expiresAt));
    }

    /**
     * The state a token should hold after a confirm action: CONFIRMED once both
     * sides have confirmed, otherwise it stays in AWAITING_CONFIRM.
     */
    public function nextAfterConfirm(?string $initiatorConfirmedAt, ?string $responderConfirmedAt): string
    {
        return $this->bothConfirmed($initiatorConfirmedAt, $responderConfirmedAt)
            ? self::CONFIRMED
            : self::AWAITING_CONFIRM;
    }
}
