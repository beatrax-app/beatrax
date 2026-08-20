<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;
use Throwable;

// The Enable Banking application credentials could not be produced: none are
// persisted, or the secrets file will not parse. Distinct from every API
// failure because no retry helps and no bank is involved — the user has to
// finish the wizard, or the on-disk file has to be repaired.
final class OpenBankingCredentialsException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('No Enable Banking application credentials are persisted.');
    }

    // The message deliberately names only the path. Including the decoded or
    // raw payload would leak credential material into every logging surface
    // above this point.
    public static function unreadable(string $path, Throwable $previous): self
    {
        return new self("Failed to parse the Enable Banking secrets file at {$path}.", 0, $previous);
    }
}
