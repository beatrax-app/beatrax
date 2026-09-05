<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Public\Enums\ExternalUrlRefusal;

// The one judgement a URL this codebase did not write must pass before it
// becomes an href, a window address or an argument to the shell. It was written
// four times before: two corpus readers refused anything but https, and the two
// Blade templates rendering what they admitted accepted `http://` as well.
/**
 * @link ../../../../.docs/conventions/an-external-url-is-judged-once.md
 */
final class ExternalUrl
{
    public const int MAX_LENGTH = 512;

    private const string SCHEME = 'https://';

    private const int HTTPS_PORT = 443;

    // Names that resolve to this machine or this LAN rather than out on the
    // web. The desktop shell serves the application to itself over loopback and
    // the sync listener answers to a `.local` name, so a URL naming one of these
    // aims the reader's click back at their own install.
    private const array LOCAL_SUFFIXES = ['.localhost', '.local', '.internal', '.home.arpa'];

    /**
     * @param  list<string>|null  $allowedHosts  lower-case hosts, or null where the caller has no finite list
     */
    public static function refusalFor(string $url, ?array $allowedHosts = null): ?ExternalUrlRefusal
    {
        $host = self::host($url);

        return match (true) {
            ! str_starts_with($url, self::SCHEME) => ExternalUrlRefusal::NotHttps,
            ! self::wellFormed($url) => ExternalUrlRefusal::Malformed,
            self::carriesCredentials($url) => ExternalUrlRefusal::CarriesCredentials,
            $host === null || ! self::isPublicHost($host) => ExternalUrlRefusal::HostIsNotPublic,
            ! self::servesOnTheHttpsPort($url) => ExternalUrlRefusal::NonDefaultPort,
            $allowedHosts !== null && ! in_array($host, $allowedHosts, true) => ExternalUrlRefusal::HostNotAllowListed,
            default => null,
        };
    }

    /**
     * @param  list<string>|null  $allowedHosts
     */
    public static function accepts(string $url, ?array $allowedHosts = null): bool
    {
        return self::refusalFor($url, $allowedHosts) === null;
    }

    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? rtrim(mb_strtolower($host), '.') : null;
    }

    private static function wellFormed(string $url): bool
    {
        // The control-character sweep is not left to the validator: a URL is
        // also written into a log line and a `title` attribute, and a bare CR
        // or LF there ends the line the reader is looking at, not the URL.
        return mb_strlen($url) <= self::MAX_LENGTH
            && ! PatternScan::matches('/[\x00-\x1F\x7F]/', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    // `https://github.com@example.test/` reads as GitHub to a person and
    // resolves to example.test. It is the one shape where checking the link
    // before clicking actively misleads the reader, and a general URL validator
    // accepts it.
    private static function carriesCredentials(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && (isset($parts['user']) || isset($parts['pass']));
    }

    private static function isPublicHost(string $host): bool
    {
        // A name with no dot in it is a LAN name, and an address literal names a
        // machine rather than a service. Neither can be a merchant's contact
        // page, and both can be the reader's own device.
        $local = $host === 'localhost'
            || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            $local = $local || str_ends_with($host, $suffix);
        }

        return ! $local;
    }

    private static function servesOnTheHttpsPort(string $url): bool
    {
        $port = parse_url($url, PHP_URL_PORT);

        return $port === null || $port === self::HTTPS_PORT;
    }
}
