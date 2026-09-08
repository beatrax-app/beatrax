<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Native\MobileUrlOpener;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Testing\FakeBridge;

const MOBILE_OPENER_URL = 'https://github.com/beatrax-app/beatrax/releases/latest';

// A phone was the one platform that could not open a URL at all: the seam was
// typed by the desktop package the mobile Composer root does not install, so
// asking for an opener was a fatal rather than a no-op.
it('reports nothing opened where the mobile package is not installed', function (): void {
    if (class_exists(Browser::class)) {
        expect(true)->toBeTrue('the mobile package is here, so the case below belongs to the bridge');

        return;
    }

    expect((new MobileUrlOpener)->open(MOBILE_OPENER_URL))->toBeFalse();
});

it('answers with what the shell said about the URL', function (): void {
    if (! class_exists(FakeBridge::class)) {
        expect(class_exists(Browser::class))->toBeFalse(
            'the Browser facade resolved without its own test bridge, so this case would prove nothing'
        );

        return;
    }

    $bridge = FakeBridge::enable();

    try {
        $bridge->respondTo('Browser.Open', '{"success":false}');
        expect((new MobileUrlOpener)->open(MOBILE_OPENER_URL))->toBeFalse(
            'the shell said it did not open the URL and the opener reported that it did'
        );

        $bridge->respondTo('Browser.Open', '{"status":"error","code":"UNKNOWN_FUNCTION","message":"Function \'Browser.Open\' not found in bridge registry"}');
        expect((new MobileUrlOpener)->open(MOBILE_OPENER_URL))->toBeFalse();

        $bridge->respondTo('Browser.Open', '{"success":true}');
        expect((new MobileUrlOpener)->open(MOBILE_OPENER_URL))->toBeTrue();

        $bridge->assertCalled('Browser.Open');
    } finally {
        FakeBridge::disable();
    }
});
