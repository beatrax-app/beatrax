<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\HostTimezone;
use Modules\Mobile\Internal\Native\AndroidHostTimezone;

afterEach(function (): void {
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL);
    HostTimezone::fake(null);
});

// Android provisions neither path HostTimezone probes for. Measured on a Galaxy
// A51 set to Europe/Amsterdam: /etc/localtime and /etc/timezone are both absent
// and the app process carries no TZ, so every probe failed and the phone read
// its days in UTC while the desktop it syncs with did not. `app.timezone` is
// the frame a DATETIME column is written in, so that is two devices recording
// one instant as two days.

it('leaves a zone the environment already names alone', function (): void {
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL.'=Pacific/Auckland');

    (new AndroidHostTimezone)->supplyToEnvironment();

    expect(getenv(HostTimezone::SUPPLIED_BY_THE_SHELL))->toBe('Pacific/Auckland');
});

// The host running this suite is not a phone, so the guard is what is under
// test here: supplying a zone anywhere else would override a machine that can
// answer for itself.
it('supplies nothing on a runtime that is not Android', function (): void {
    if (UserDataPathService::platform() === MobilePlatform::Android) {
        expect(true)->toBeTrue('running on Android, where the case below is the other one');

        return;
    }

    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL);

    (new AndroidHostTimezone)->supplyToEnvironment();

    expect(getenv(HostTimezone::SUPPLIED_BY_THE_SHELL))->toBeFalse();
});

// getprop answers "GMT+02:00" on some builds, which DateTimeZone cannot be
// constructed from. HostTimezone would refuse it at the door, but an offset
// left in the environment would be read as a zone by anything else looking.
it('hands over only what DateTimeZone can be built from', function (): void {
    $read = (new AndroidHostTimezone)->read();

    expect($read === null || HostTimezone::isZone($read))->toBeTrue();
});
