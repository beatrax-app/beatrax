<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

// Thrown when a provider's incremental-sync cursor has aged out:
// Gmail's users.history.list returning 404 (historyId older than the
// ~7-day retention window), or Graph's $delta returning 410 /
// syncStateNotFound. Callers fall back to a date-bounded re-scan.
final class CursorExpiredException extends RuntimeException
{
    public static function gmail(string $detail = ''): self
    {
        return new self(
            $detail === ''
                ? 'Gmail historyId cursor expired (404 from users.history.list).'
                : 'Gmail historyId cursor expired (404 from users.history.list): '.$detail,
        );
    }

    public static function graph(string $detail = ''): self
    {
        return new self(
            $detail === ''
                ? 'Microsoft Graph delta cursor expired (410 syncStateNotFound).'
                : 'Microsoft Graph delta cursor expired (410 syncStateNotFound): '.$detail,
        );
    }
}
