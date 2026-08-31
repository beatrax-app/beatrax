<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Recovery;

use Illuminate\Contracts\Session\Session;

/**
 * @link ../../../../.docs/features/auth/pending-recovery-codes-lifetime.md
 */
final class PendingRecoveryCodes
{
    public const string SESSION_KEY = 'auth.signup.recovery_codes_plain';

    private const string RENEWED_SESSION_KEY = 'auth.signup.recovery_codes_renewed';

    /**
     * @param  list<string>  $codes
     */
    public static function store(Session $session, array $codes): void
    {
        $session->put(self::SESSION_KEY, $codes);
    }

    // Said by every page load that IS the ceremony. The first one that stays
    // silent ends it, whatever route it was for and whether or not the reader
    // chose to make it.
    public static function renew(Session $session): void
    {
        $session->put(self::RENEWED_SESSION_KEY, true);
    }

    // Pulled rather than read: the claim is about the request making it, so
    // leaving it behind would hand the next request a free pass.
    public static function consumeRenewal(Session $session): bool
    {
        return $session->pull(self::RENEWED_SESSION_KEY) === true;
    }

    /**
     * @return list<string>
     */
    public static function read(Session $session): array
    {
        $pending = $session->get(self::SESSION_KEY);

        return is_array($pending) ? array_values(array_filter($pending, is_string(...))) : [];
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
