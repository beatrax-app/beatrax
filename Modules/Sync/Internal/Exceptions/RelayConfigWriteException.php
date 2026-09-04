<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// The relay's own settings file could not be created or written. Distinct
// from SecretFileException, which covers the paired token: this file holds
// the endpoint URL rather than key material, so a failure here is reported
// without any of the handling a leaked secret would need.
final class RelayConfigWriteException extends RuntimeException
{
    public static function couldNotCreateDirectory(string $directory): self
    {
        return new self("Cannot create relay config directory: {$directory}");
    }

    public static function couldNotWrite(string $path): self
    {
        return new self("Cannot write relay config to: {$path}");
    }

    // Refused rather than written over: both setters rewrite the whole file, so
    // a body that will not parse is the endpoint or the pin this write is about
    // to replace with a blank.
    public static function couldNotReadBeforeWriting(string $path): self
    {
        return new self("Cannot read the relay config already at {$path} — refusing to overwrite it with a blank field.");
    }
}
