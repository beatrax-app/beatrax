<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Enums;

// open_banking_connections.last_attempt_status stays a string column, written by
// the scheduled job and the "Sync now" button and read back by the transparency
// panel; this enum is the one spelling all three share.
enum SyncAttemptStatus: string
{
    case Ok = 'ok';

    case Error = 'error';

    case ConsentFailed = 'consent_failed';

    // The fetch itself worked; the walk over the bank's pages did not reach the
    // end of them. Recorded rather than folded into Ok, because the rows the
    // reader can see are a part of the window and not the whole of it.
    case Truncated = 'truncated';

    // The bank answered with rows and not one of them could be filed. Apart
    // from Error because nothing errored — the connection, the consent and the
    // walk all worked — and apart from Ok because no money arrived.
    case NothingImported = 'nothing_imported';

    // Null is "no attempt has run yet", which the panel must not draw as a
    // failure the reader cannot explain.
    public static function failedIn(?string $rawStatus): bool
    {
        if ($rawStatus === null || $rawStatus === '') {
            return false;
        }

        return self::tryFrom($rawStatus) !== self::Ok;
    }
}
