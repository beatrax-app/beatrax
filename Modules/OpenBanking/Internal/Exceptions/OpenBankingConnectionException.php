<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Exceptions;

use RuntimeException;

// Every one of these refuses before the fetch starts, so nothing has been
// written and the caller can show the reason without any cleanup.
final class OpenBankingConnectionException extends RuntimeException
{
    public static function notFound(int $connectionId, int $userId): self
    {
        return new self(sprintf('No open_banking_connections row %s for user %s.', $connectionId, $userId));
    }

    public static function notFetchable(int $connectionId): self
    {
        return new self(
            sprintf('Connection %s is not enabled or its consent has expired — refusing to fetch.', $connectionId),
        );
    }

    public static function accountNotResolved(int $connectionId): self
    {
        return new self(
            sprintf('Connection %s has no resolved account_uid yet — the consent dance must ', $connectionId)
            .'capture accounts[].uid before a fetch can run.',
        );
    }
}
