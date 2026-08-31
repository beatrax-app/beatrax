<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LogicException;

// Both renderings of an instant live here because the defect is choosing
// between them by accident: a `Z` suffix asserts UTC, a stored DATETIME is read
// back at the app's own offset, and a caller that formats its own string ships
// whichever frame the value happened to arrive in.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#an-instant-rendered-in-a-frame-it-was-not-produced-in
 */
final class Instant
{
    private const string ZULU_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    private const string STORED_PATTERN = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    // Converts AND asserts, in one call: a writer that only asserted could
    // still hand in a local moment, which is how a column filled with +02:00
    // under a comment promising UTC.
    /**
     * @throws LogicException
     */
    public static function zulu(DateTimeInterface $moment): string
    {
        $stamp = DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        if (preg_match(self::ZULU_PATTERN, $stamp) !== 1) {
            throw new LogicException(
                "Instant::zulu: '{$stamp}' is not zero-padded UTC Zulu ISO8601. "
                .'A lexical expires_at comparison requires the Zulu form.'
            );
        }

        return $stamp;
    }

    /**
     * @throws LogicException
     */
    public static function appLocal(DateTimeInterface $moment): string
    {
        $stamp = self::inAppZone($moment)->format('Y-m-d H:i:s');

        if (preg_match(self::STORED_PATTERN, $stamp) !== 1) {
            throw new LogicException(
                "Instant::appLocal: '{$stamp}' is not a zero-padded Y-m-d H:i:s stamp. "
                .'A DATETIME column is read back with CarbonImmutable::parse, which requires that shape.'
            );
        }

        return $stamp;
    }

    // The app zone is taken from the default the framework sets out of
    // app.timezone, which is by construction the zone CarbonImmutable::now()
    // — and therefore Clock::now() — lands in. Reading config here instead
    // would let the two drift apart under a test that moves one of them.
    public static function inAppZone(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
