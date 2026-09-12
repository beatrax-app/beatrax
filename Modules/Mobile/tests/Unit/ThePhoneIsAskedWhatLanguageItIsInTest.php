<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Native\NativeSystemLanguage;
use Native\Mobile\Facades\Device;
use Native\Mobile\Testing\FakeBridge;

// Neither mobile shell sends Accept-Language, so this bridge answer is the only
// thing that stands between a Dutch phone and an English app. Measured on a
// Galaxy A51: the WebView forwards Cookie, Accept and User-Agent into PHP and
// no language header at all.

it('reports no language where the mobile package is not installed', function (): void {
    if (class_exists(Device::class)) {
        expect(true)->toBeTrue('the mobile package is here, so the cases below belong to the bridge');

        return;
    }

    expect((new NativeSystemLanguage)->tag())->toBeNull();
});

it('answers with the tag the shell reported', function (): void {
    if (! class_exists(FakeBridge::class)) {
        expect(class_exists(Device::class))->toBeFalse(
            'the Device facade resolved without its own test bridge, so this case would prove nothing'
        );

        return;
    }

    $bridge = FakeBridge::enable();

    try {
        $bridge->respondTo('Device.GetInfo', '{"info":"{\"language\":\"nl-NL\",\"model\":\"SM-A515F\"}"}');
        expect((new NativeSystemLanguage)->tag())->toBe('nl-NL');

        $bridge->assertCalled('Device.GetInfo');
    } finally {
        FakeBridge::disable();
    }
});

// Every shape the bridge can answer with that is not a language: a refusal
// envelope, info that is not JSON, JSON without the key, and a key present but
// blank. None of them may become a locale, because a bad tag would outrank the
// English fallback rather than falling through to it.
it('treats an answer that names no language as no answer', function (): void {
    if (! class_exists(FakeBridge::class)) {
        expect(class_exists(Device::class))->toBeFalse(
            'the Device facade resolved without its own test bridge, so this case would prove nothing'
        );

        return;
    }

    $bridge = FakeBridge::enable();

    try {
        foreach ([
            'a refusal' => '{"status":"error","code":"UNKNOWN_FUNCTION","message":"not found"}',
            'info that is not JSON' => '{"info":"not json at all"}',
            'no language key' => '{"info":"{\"model\":\"SM-A515F\"}"}',
            'a blank language' => '{"info":"{\"language\":\"   \"}"}',
        ] as $shape => $answer) {
            $bridge->respondTo('Device.GetInfo', $answer);

            expect((new NativeSystemLanguage)->tag())->toBeNull(sprintf('%s became a language', $shape));
        }
    } finally {
        FakeBridge::disable();
    }
});
