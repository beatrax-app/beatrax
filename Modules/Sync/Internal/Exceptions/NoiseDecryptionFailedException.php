<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;
use Throwable;

// A Noise transport message did not authenticate. Unlike an encryption
// failure this says nothing is wrong with the build — the ciphertext was
// altered, replayed, or sent under a different key — so it is a statement
// about the peer, and the message must be dropped rather than retried.
final class NoiseDecryptionFailedException extends RuntimeException
{
    public static function aeadRejected(?Throwable $previous = null): self
    {
        return new self(
            'Noise AEAD decryption failed — invalid ciphertext or wrong key.',
            0,
            $previous,
        );
    }
}
