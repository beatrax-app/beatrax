<?php

declare(strict_types=1);

use Modules\Core\Public\Support\HostTimezone;

afterEach(function (): void {
    HostTimezone::fake(null);
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL);
});

it('takes the zone the shell supplies over anything it could probe for itself', function (): void {
    HostTimezone::fake(null);
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL.'=Pacific/Auckland');

    expect(HostTimezone::detect())->toBe('Pacific/Auckland');
});

// An Android shell that hands over a device property rather than an identifier
// would otherwise be remembered for the life of the process, and every later
// call would answer with something DateTimeZone cannot construct.
it('declines a supplied value that is not a zone identifier', function (string $supplied): void {
    HostTimezone::fake(null);
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL.'='.$supplied);

    expect(HostTimezone::isZone(HostTimezone::detect()))->toBeTrue()
        ->and(HostTimezone::detect())->not->toBe($supplied);
})->with([
    'a device property' => 'GMT+02:00',
    'an offset' => '+02:00',
    'a windows name' => 'W. Europe Standard Time',
    'nonsense' => 'Europe/Nowhere',
]);

it('answers something DateTimeZone can be built from, whatever the host is', function (): void {
    HostTimezone::fake(null);
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL);

    $detected = HostTimezone::detect();

    expect(HostTimezone::isZone($detected))->toBeTrue()
        ->and(new DateTimeZone($detected))->toBeInstanceOf(DateTimeZone::class);
});

// The memo is what keeps three filesystem probes and a subprocess off every
// request; a second call that re-probed would be the bug this pins.
it('answers the same value twice without asking the host again', function (): void {
    HostTimezone::fake('Asia/Tokyo');

    $first = HostTimezone::detect();
    putenv(HostTimezone::SUPPLIED_BY_THE_SHELL.'=Pacific/Auckland');

    expect($first)->toBe('Asia/Tokyo')->and(HostTimezone::detect())->toBe('Asia/Tokyo');
});

it('refuses to remember a fake that is not a zone', function (): void {
    HostTimezone::fake('Europe/Nowhere');

    expect(HostTimezone::isZone(HostTimezone::detect()))->toBeTrue();
});
