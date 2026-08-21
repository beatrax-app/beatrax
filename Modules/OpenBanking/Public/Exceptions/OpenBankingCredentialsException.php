<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;
use Throwable;

// Distinct from an API failure: no bank is involved and no retry helps: the
// user finishes the wizard, or the on-disk file is repaired.
final class OpenBankingCredentialsException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('No Enable Banking application credentials are persisted.');
    }

    // Only the path: the decoded or raw payload would leak credential material
    // into every logging surface above this.
    public static function unreadable(string $path, Throwable $previous): self
    {
        return new self("Failed to parse the Enable Banking secrets file at {$path}.", 0, $previous);
    }
}
