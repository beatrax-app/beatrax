<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use RuntimeException;

// Raised when the on-device network-policy file cannot be persisted: its
// parent directory could not be created, or the write itself failed. Both
// are local I/O faults the caller surfaces rather than silently dropping,
// since a lost toggle would let sync run on a paused connection.
final class NetworkPolicyException extends RuntimeException
{
    public static function directoryNotCreatable(string $dir): self
    {
        return new self(sprintf('Cannot create network-policy directory: %s', $dir));
    }

    public static function notWritable(string $path): self
    {
        return new self(sprintf('Cannot write network policy to: %s', $path));
    }
}
