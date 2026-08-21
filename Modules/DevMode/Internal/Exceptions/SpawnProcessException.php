<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Exceptions;

use RuntimeException;

// Raised while CommandSpawner detaches a child process. Each named
// constructor pins one failure mode of that launch.
final class SpawnProcessException extends RuntimeException
{
    public static function bashWrapperFailed(string $stderr): self
    {
        return new self('CommandSpawner: bash wrapper exited non-zero. stderr: '.$stderr);
    }

    public static function pidUncapturable(string $got): self
    {
        return new self("CommandSpawner: failed to capture child PID from bash wrapper. Got: `{$got}`");
    }

    public static function runsDirectoryUnwritable(string $path): self
    {
        return new self("CommandSpawner: failed to create runs directory at `{$path}`.");
    }
}
