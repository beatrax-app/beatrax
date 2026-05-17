<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

/**
 * Shared single-line + length-capped message normaliser for provider
 * error strings.
 *
 * The OAuth provider wrappers (GoogleOAuthProvider,
 * MicrosoftOAuthProvider) and the Microsoft Graph API client all
 * forward provider error messages into typed exceptions whose text
 * may eventually land in a session flash, a queue worker log, or the
 * UI. Multi-line error bodies and verbose stack traces would
 * contaminate any of those surfaces; this utility caps every message
 * at one line and a configurable byte ceiling.
 *
 * The default cap is 300 bytes — long enough to surface the actual
 * provider hint (e.g. "invalid_grant: refresh token has been expired
 * or revoked"), short enough to fit the flash session payload alongside
 * other state.
 */
final class SafeMessage
{
    /**
     * Reduce a raw provider message string to a single line capped
     * at $max bytes. Multiple consecutive whitespace characters
     * (newlines, tabs, repeated spaces) collapse to one space so the
     * surface is consistently single-line regardless of how the
     * provider formatted the original.
     */
    public static function cap(string $raw, int $max = 300): string
    {
        $oneLine = (string) preg_replace('/\s+/', ' ', $raw);

        return substr($oneLine, 0, $max);
    }
}
