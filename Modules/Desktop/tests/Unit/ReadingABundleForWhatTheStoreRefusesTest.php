<?php

declare(strict_types=1);

use Modules\Desktop\Commands\ReviewMacBundleCommand;
use Modules\Desktop\Internal\Boot\EntitlementsPlist;
use Modules\Desktop\Internal\Boot\MacBundleInspection;
use Modules\Desktop\Internal\Boot\MacBundleReader;
use Modules\Desktop\Internal\Boot\ReadsAMacBundle;
use Modules\Desktop\Tests\Support\DescribedMacBundle;

// codesign and the file magic are the only two things the reader does that a
// Linux runner cannot, so everything above them is exercised against a bundle
// that is described rather than built.

function bundleDescribedAs(array $entitlements, array $executables, array $unsigned = []): MacBundleInspection
{
    app()->instance(ReadsAMacBundle::class, new DescribedMacBundle($entitlements, $executables, $unsigned));

    return app(MacBundleInspection::class);
}

it('reads a plist the way codesign writes one', function (): void {
    $parsed = EntitlementsPlist::parse(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0"><dict>
            <key>com.apple.security.app-sandbox</key><true/>
            <key>com.apple.security.get-task-allow</key><false/>
            <key>com.apple.application-identifier</key><string>NV5645J73B.io.nightworks.beatrax</string>
        </dict></plist>
        XML);

    expect($parsed)->toBe([
        'com.apple.security.app-sandbox' => true,
        'com.apple.security.get-task-allow' => false,
        'com.apple.application-identifier' => 'NV5645J73B.io.nightworks.beatrax',
    ]);
});

// codesign answers nothing at all for an unsigned binary, and the difference
// between "no entitlements" and "could not read" is what the signature check
// is separately for.
it('reads an empty set out of anything that is not a plist', function (string $notAPlist): void {
    expect(EntitlementsPlist::parse($notAPlist))->toBe([]);
})->with(['', 'not xml at all', '<plist version="1.0"><array/></plist>']);

it('judges only the nested executables, never the app binary twice', function (): void {
    $inspection = bundleDescribedAs(
        [
            '/x/Beatrax.app' => ['com.apple.security.app-sandbox' => true, 'com.apple.security.network.server' => true],
            '/x/Beatrax.app/Contents/MacOS/helper' => ['com.apple.security.app-sandbox' => true, 'com.apple.security.inherit' => true],
        ],
        ['/x/Beatrax.app' => ['Contents/MacOS/Beatrax', 'Contents/MacOS/helper']],
    );

    // network.server on the APP is right and on a child would be a refusal;
    // reading the app's own binary as a child reported every app right twice.
    expect($inspection->refusals('/x/Beatrax.app'))->toBe([]);
    expect($inspection->executableCount('/x/Beatrax.app'))->toBe(2);
});

it('finds the interpreter where this product actually puts it', function (): void {
    $inspection = bundleDescribedAs(
        ['/x/Beatrax.app' => ['com.apple.security.app-sandbox' => true]],
        ['/x/Beatrax.app' => ['Contents/MacOS/Beatrax', ReviewMacBundleCommand::INTERPRETER]],
    );

    // Named as launched, the way the command names it: the interpreter is
    // spawned, and a bundled data file that happens to be Mach-O is not, and
    // nothing on disk tells the two apart.
    $refusals = implode("\n", $inspection->refusals('/x/Beatrax.app', [ReviewMacBundleCommand::INTERPRETER]));

    expect($refusals)->toContain('outside a Contents/MacOS directory');
    expect($refusals)->toContain('launched by the app and is not sandboxed');

    // Unnamed, the same file is only a layout finding — which is why the
    // command has to name it and a foreign bundle is judged on less.
    expect(implode("\n", $inspection->refusals('/x/Beatrax.app')))
        ->not->toContain('launched by the app');
});

it('names an executable nothing signed', function (): void {
    $inspection = bundleDescribedAs(
        ['/x/Beatrax.app' => ['com.apple.security.app-sandbox' => true]],
        ['/x/Beatrax.app' => ['Contents/MacOS/Beatrax', 'Contents/MacOS/helper']],
        ['/x/Beatrax.app/Contents/MacOS/helper'],
    );

    expect(implode("\n", $inspection->refusals('/x/Beatrax.app')))->toContain('carries no signature');
});

// A path that never resolves is the shape a renamed build output takes, and
// an empty refusal list for one reads exactly like a clean bundle.
it('refuses a path that is not a bundle', function (): void {
    bundleDescribedAs([], []);

    test()->artisan('desktop:review-mac-bundle', ['path' => '/x/NotThere.app'])
        ->assertExitCode(1);
});

it('refuses a bundle it walked without finding one executable', function (): void {
    bundleDescribedAs([], []);

    test()->artisan('desktop:review-mac-bundle', ['path' => base_path()])
        ->assertExitCode(1);
});

it('answers zero for a bundle the store would take', function (): void {
    // base_path() stands in for the bundle because the command asks the
    // filesystem whether the path is a directory before it asks the reader
    // anything, and a described bundle has no directory of its own.
    bundleDescribedAs(
        [base_path() => ['com.apple.security.app-sandbox' => true]],
        [base_path() => ['Contents/MacOS/'.basename(base_path())]],
    );

    test()->artisan('desktop:review-mac-bundle', ['path' => base_path()])->assertExitCode(0);
});

it('is bound to the real reader outside a test', function (): void {
    expect(app(ReadsAMacBundle::class))->toBeInstanceOf(MacBundleReader::class);
});
