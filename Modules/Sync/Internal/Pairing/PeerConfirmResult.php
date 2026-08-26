<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

// The answer to one authenticated PAIR_CONFIRM. A deferral is deliberately not
// a PairingState: the row does not move, and saying so with a state value
// would put a fourth member on an enum the `state` column is written from.
/**
 * @link ../../../../.docs/features/sync/pairing-handshake.md#a-deferred-confirm-is-held-not-dropped
 */
final readonly class PeerConfirmResult
{
    private function __construct(private ?PairingState $state) {}

    // Verified and parked rather than acted on, because the local human has
    // not compared the safety words yet. Nothing was recorded, so this can
    // never be read as progress toward a confirmed pairing.
    public static function deferred(): self
    {
        return new self(null);
    }

    public static function applied(PairingState $state): self
    {
        return new self($state);
    }

    public function isDeferred(): bool
    {
        return $this->state === null;
    }

    // Null for a deferral, which by construction moved no row — so a caller
    // needing a state cannot take one from an answer that never produced one.
    public function stateApplied(): ?PairingState
    {
        return $this->state;
    }
}
