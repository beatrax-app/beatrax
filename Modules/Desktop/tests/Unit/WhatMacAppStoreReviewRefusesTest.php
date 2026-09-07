<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\MacBundleReview;

// The rules, without a build. The calibration against real bundles lives in
// its own file, because that one can only run on a machine that has them.

function reviewOfBundle(array $app, array $children = []): array
{
    return (new MacBundleReview)->refusals($app, $children);
}

function sandboxedApp(array $extra = []): array
{
    return ['com.apple.security.app-sandbox' => true, ...$extra];
}

function inheritingChild(array $extra = []): array
{
    return [
        'entitlements' => [
            'com.apple.security.app-sandbox' => true,
            'com.apple.security.inherit' => true,
            ...$extra,
        ],
        'signed' => true,
        'inMacOsDirectory' => true,
        'launched' => true,
    ];
}

it('takes a sandboxed app with an inheriting child and refuses nothing', function (): void {
    expect(reviewOfBundle(sandboxedApp(), ['Contents/MacOS/helper' => inheritingChild()]))->toBe([]);
});

it('refuses an app that is not sandboxed at all', function (): void {
    expect(reviewOfBundle([]))->toHaveCount(1);
    expect(reviewOfBundle([])[0])->toContain('not sandboxed');
});

it('refuses each entitlement the store does not take', function (string $entitlement): void {
    $refusals = reviewOfBundle(sandboxedApp([$entitlement => true]));

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain($entitlement);
})->with(array_keys(MacBundleReview::REFUSED_ENTITLEMENTS));

it('refuses a child that combines inherit with another sandbox right', function (): void {
    $child = inheritingChild(['com.apple.security.files.user-selected.read-write' => true]);

    $refusals = reviewOfBundle(sandboxedApp(), ['Contents/MacOS/helper' => $child]);

    expect($refusals)->toHaveCount(1);
    expect($refusals[0])->toContain('combines inherit');
});

// The shape a real store app ships and an earlier version of these rules
// refused: a nested .app is its own sandboxed program, holding its own rights
// rather than inheriting the parent's.
it('takes a nested bundle that carries its own rights instead of inheriting', function (): void {
    $helper = [
        'entitlements' => [
            'com.apple.security.app-sandbox' => true,
            'com.apple.security.files.user-selected.read-write' => true,
        ],
        'signed' => true,
        'inMacOsDirectory' => true,
        'launched' => true,
    ];

    expect(reviewOfBundle(sandboxedApp(), ['Contents/Library/LoginItems/H.app/Contents/MacOS/H' => $helper]))->toBe([]);
});

it('refuses a child nothing signed', function (): void {
    $child = inheritingChild();
    $child['signed'] = false;

    expect(reviewOfBundle(sandboxedApp(), ['Contents/MacOS/helper' => $child])[0])->toContain('no signature');
});

// Where the bundled interpreter lands today: Contents/Resources/build/php.
it('refuses an executable outside a Contents/MacOS directory', function (): void {
    $child = inheritingChild();
    $child['inMacOsDirectory'] = false;

    expect(reviewOfBundle(sandboxedApp(), ['Contents/Resources/build/php/php' => $child])[0])
        ->toContain('outside a Contents/MacOS directory');
});

it('refuses an unsandboxed executable the app launches', function (): void {
    $child = ['entitlements' => [], 'signed' => true, 'inMacOsDirectory' => true, 'launched' => true];

    expect(reviewOfBundle(sandboxedApp(), ['Contents/MacOS/helper' => $child])[0])
        ->toContain('launched by the app and is not sandboxed');
});

// Apple Configurator ships cfgutilscript unsandboxed in its own MacOS
// directory and is on the store. Requiring it of everything refused a real
// submission, which is what the calibration file caught.
it('takes an unsandboxed executable the app never launches', function (): void {
    $tool = ['entitlements' => [], 'signed' => true, 'inMacOsDirectory' => true, 'launched' => false];

    expect(reviewOfBundle(sandboxedApp(), ['Contents/MacOS/cli-tool' => $tool]))->toBe([]);
});
