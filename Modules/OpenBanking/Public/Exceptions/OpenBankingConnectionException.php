<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Public\Exceptions;

use RuntimeException;

// A connection row exists in a state a fetch cannot run against, or does not
// exist for this user at all. Every one of these is a refusal to start rather
// than a failure part-way through, so nothing has been written and the caller
// can present the reason to the user without any cleanup.
/**
 * @link ../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingConnectionException extends RuntimeException
{
    public static function notFound(int $connectionId, int $userId): self
    {
        return new self("No open_banking_connections row {$connectionId} for user {$userId}.");
    }

    public static function notFetchable(int $connectionId): self
    {
        return new self(
            "Connection {$connectionId} is not enabled or its consent has expired — refusing to fetch.",
        );
    }

    public static function accountNotResolved(int $connectionId): self
    {
        return new self(
            "Connection {$connectionId} has no resolved account_uid yet — the consent dance must "
            .'capture accounts[].uid before a fetch can run.',
        );
    }

    // The secrets file holds exactly one live session at a time, so a re-link
    // to a different institution would otherwise pair one bank's credentials
    // with another bank's account_uid.
    public static function institutionMismatch(int $connectionId, string $connectionInstitution, string $sessionInstitution): self
    {
        return new self(
            "Connection {$connectionId}'s institution ({$connectionInstitution}) does not match the "
            ."currently active Enable Banking session's institution ({$sessionInstitution}). Only one "
            .'Enable Banking connection can be live at a time — re-link '.$connectionInstitution
            .' before syncing this connection.',
        );
    }
}
