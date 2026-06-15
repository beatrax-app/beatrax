<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Pairing;

use RuntimeException;

/**
 * Thrown when a public key submitted into the pairing flow is not a valid
 * 32-byte (64 lowercase-hex-char) key (WR-01).
 *
 * The Livewire layer catches this and surfaces the generic "invalid code"
 * flash rather than letting a raw SodiumException bubble up as a 500 — a
 * responder that submits malformed key material gets a clean retry, not a
 * crash mid-handshake.
 */
final class InvalidPublicKeyException extends RuntimeException {}
