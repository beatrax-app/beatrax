<?php

declare(strict_types=1);

namespace Modules\DevMode\Public\Exceptions;

use RuntimeException;

// Raised while CommandSpawner launches a whitelisted artisan command as a
// detached background process — the bash wrapper exiting non-zero, the
// child PID never surfacing on stdout, or the per-run output directory
// failing to materialise. Each named constructor pins one failure mode.
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
