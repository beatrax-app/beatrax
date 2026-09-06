<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use DateTimeZone;

// The zone the machine is in, which is not the zone PHP reports. PHP answers
// `date_default_timezone_get()` out of `app.timezone`, so asking it returns
// whatever the bundle happened to pin — the desktop template pinned
// Europe/Amsterdam and every reader outside it read somebody else's day.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class HostTimezone
{
    // Set by a shell that knows better than the filesystem does. The mobile
    // runtimes are the case: an Android application does not get to read
    // /etc/localtime, and the shell has the answer from the platform.
    public const string SUPPLIED_BY_THE_SHELL = 'BEATRAX_HOST_TIMEZONE';

    private const string ZONEINFO_LINK = '/etc/localtime';

    private const string ZONEINFO_FILE = '/etc/timezone';

    private static ?string $detected = null;

    // Memoized for the life of the process. Two of the four probes touch the
    // filesystem and one spawns a subprocess, and the answer cannot change
    // under a running application without it being restarted anyway.
    public static function detect(): string
    {
        return self::$detected ??= self::probe();
    }

    // Test seam, and the only writer of the memo. A value that is not a zone
    // identifier is refused rather than remembered, so a shell handing over
    // nonsense falls back rather than poisoning every later call.
    public static function fake(?string $zone): void
    {
        self::$detected = $zone !== null && self::isZone($zone) ? $zone : null;
    }

    public static function isZone(string $candidate): bool
    {
        return in_array($candidate, DateTimeZone::listIdentifiers(), true);
    }

    private static function probe(): string
    {
        foreach ([self::fromShell(), self::fromLink(), self::fromFile(), self::fromWindows()] as $candidate) {
            if ($candidate !== null && self::isZone($candidate)) {
                return $candidate;
            }
        }

        return 'UTC';
    }

    private static function fromShell(): ?string
    {
        return self::nonEmptyString(getenv(self::SUPPLIED_BY_THE_SHELL));
    }

    private static function nonEmptyString(mixed $candidate): ?string
    {
        return is_string($candidate) && trim($candidate) !== '' ? trim($candidate) : null;
    }

    // macOS, Linux and iOS all symlink /etc/localtime into the zoneinfo tree,
    // and the identifier is the tail of that path after the directory holding
    // the database — two segments, because "Europe/Amsterdam" is two and
    // "America/Argentina/La_Rioja" is three.
    private static function fromLink(): ?string
    {
        if (! is_link(self::ZONEINFO_LINK)) {
            return null;
        }

        $target = @readlink(self::ZONEINFO_LINK);

        if (! is_string($target)) {
            return null;
        }

        $segments = explode('/', str_replace('\\', '/', $target));

        for ($take = 3; $take >= 2; $take--) {
            $candidate = implode('/', array_slice($segments, -$take));

            if (self::isZone($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // Debian and its derivatives write the identifier itself here, and it is
    // the authority on a host where /etc/localtime was copied rather than
    // linked — which is what a container image usually ships.
    private static function fromFile(): ?string
    {
        if (! is_readable(self::ZONEINFO_FILE)) {
            return null;
        }

        return self::nonEmptyString(@file_get_contents(self::ZONEINFO_FILE));
    }

    // Windows names its zones its own way, and the mapping between the two
    // vocabularies lives in ICU. Without intl there is no mapping to make, so
    // the probe declines rather than guessing at one.
    private static function fromWindows(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! function_exists('shell_exec') || ! class_exists(\IntlTimeZone::class)) {
            return null;
        }

        $windowsId = @shell_exec('tzutil /g');

        if (! is_string($windowsId) || trim($windowsId) === '') {
            return null;
        }

        // Through the mixed door: ext-intl's own signature says string|false,
        // and the stub PHPStan reads says string. Narrowing what the stub calls
        // certain is how a false gets returned from a ?string method.
        return self::nonEmptyString(\IntlTimeZone::getIDForWindowsID(trim($windowsId)));
    }
}
