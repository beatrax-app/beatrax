<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use RuntimeException;

/**
 * Thrown when a provider's incremental-sync cursor has aged out and
 * must be re-baselined. Maps to Gmail's `users.history.list` returning
 * 404 (the historyId is older than ~7 days of retained history) and to
 * Microsoft Graph's `$delta` returning 410 / `syncStateNotFound` (the
 * delta token is no longer valid).
 *
 * Catch this exception inside the incremental-scan path and fall back
 * to a date-bounded re-scan rather than treating it as a hard error.
 */
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
