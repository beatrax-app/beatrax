<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// Whether a host names something outside this machine and this network. Fails
// CLOSED: the first spelling of this rule fell through to "contains a dot", so
// every notation FILTER_VALIDATE_IP cannot parse -- 0177.0.0.1, 127.1,
// 0x7f.0x0.0x0.0x1, [::ffff:127.0.0.1] -- was answered public.
/**
 * @link ../../../../.docs/conventions/an-external-url-is-judged-once.md#what-counts-as-a-public-host
 */
final class PublicHost
{
    // Suffixes that resolve inside a network rather than on the internet.
    // `.lan`, `.intranet`, `.corp` and `.private` are here for the same reason
    // as the rest: ICANN never delegated them, so a resolver answers them from
    // whatever the local network says.
    private const array RESERVED_SUFFIXES = [
        '.corp',
        '.home.arpa',
        '.internal',
        '.intranet',
        '.invalid',
        '.lan',
        '.local',
        '.localhost',
        '.private',
    ];

    // A strict LDH name of at least two labels whose last label is alphabetic.
    // The alphabetic TLD is what does the work: it is the one rule that rejects
    // every numeric notation at once, and the two-label floor is what rejects a
    // bare LAN name.
    private const string HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public static function names(string $host): bool
    {
        $host = mb_strtolower($host);

        // One trailing dot is a legal absolute-name suffix and normalises away;
        // anything else with an empty label is malformed and falls to the
        // pattern below.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        // An empty host names nothing, and neither does anything the hostname
        // pattern rejects: one answer for both.
        if ($host === '' || preg_match(self::HOSTNAME_PATTERN, $host) !== 1) {
            return false;
        }

        return array_all(self::RESERVED_SUFFIXES, static fn (string $suffix): bool => ! str_ends_with($host, $suffix));
    }
}
