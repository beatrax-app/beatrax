<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\HostTimezone;
use Throwable;

// Android provisions neither path HostTimezone probes for. Measured on a Galaxy
// A51 set to Europe/Amsterdam: /etc/localtime and /etc/timezone are both absent
// and the app process carries no TZ, so all four probes failed and every day
// boundary on the phone was UTC while the desktop beside it was not.

// The platform keeps the answer in a system property instead, which an app may
// read. This fills the env seam HostTimezone already documents rather than
// adding a fifth probe to it, so the settings control that names "this machine"
// is right for the same reason the stored frame is.
final readonly class AndroidHostTimezone
{
    private const string PROPERTY = 'persist.sys.timezone';

    private const string GETPROP = '/system/bin/getprop';

    // Left alone when the environment already names a zone: a packager or a
    // test that pinned one outranks the device, the same way it does for every
    // other tier.
    public function supplyToEnvironment(): void
    {
        if (self::alreadySupplied() || ! self::isAndroid()) {
            return;
        }

        $zone = $this->read();

        if ($zone !== null) {
            putenv(HostTimezone::SUPPLIED_BY_THE_SHELL.'='.$zone);
        }
    }

    // Only an identifier is handed over. A property that answers "GMT+02:00" on
    // some builds is not a zone, and HostTimezone would refuse it anyway — but
    // refusing it here keeps the environment honest for anything else reading.
    public function read(): ?string
    {
        if (! function_exists('shell_exec') || ! is_executable(self::GETPROP)) {
            return null;
        }

        try {
            $answer = @shell_exec(self::GETPROP.' '.escapeshellarg(self::PROPERTY));
        } catch (Throwable) {
            return null;
        }

        $zone = is_string($answer) ? trim($answer) : '';

        return $zone !== '' && HostTimezone::isZone($zone) ? $zone : null;
    }

    private static function alreadySupplied(): bool
    {
        return trim((string) getenv(HostTimezone::SUPPLIED_BY_THE_SHELL)) !== '';
    }

    private static function isAndroid(): bool
    {
        return UserDataPathService::platform() === MobilePlatform::Android;
    }
}
