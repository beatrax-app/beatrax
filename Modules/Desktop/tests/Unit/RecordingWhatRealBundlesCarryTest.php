<?php

declare(strict_types=1);

use Modules\Desktop\Internal\Boot\ReadsAMacBundle;
use Modules\Desktop\Tests\Support\DescribedMacBundle;

// The recorder decides which bundles are worth recording, and that decision is
// the whole value of the fixture it writes.

function recordingOf(array $installed, array $executables = [], array $entitlements = []): string
{
    app()->instance(ReadsAMacBundle::class, new DescribedMacBundle($entitlements, $executables, [], $installed));

    return sys_get_temp_dir().'/beatrax-record-'.bin2hex(random_bytes(6)).'.json';
}

function recordedBundles(string $out): array
{
    /** @var array{recorded_on: string, bundles: list<array<string, mixed>>} $decoded */
    $decoded = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

    return $decoded['bundles'];
}

it('takes up to three from the store and one Electron app from outside', function (): void {
    $installed = [];

    foreach (['A', 'B', 'C', 'D'] as $name) {
        $installed['/Applications/'.$name.'.app'] = ['from_the_store' => true, 'electron' => false];
    }

    $installed['/Applications/Plain.app'] = ['from_the_store' => false, 'electron' => false];
    $installed['/Applications/Shell.app'] = ['from_the_store' => false, 'electron' => true];

    $out = recordingOf($installed);

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(0);

    $names = array_column(recordedBundles($out), 'name');

    expect($names)->toHaveCount(4);
    expect($names)->toContain('Shell.app');
    // A bundle shaped nothing like this product could be refused for reasons
    // this product will never meet, so it is no control at all.
    expect($names)->not->toContain('Plain.app');
});

it('refuses to write a recording with only store bundles', function (): void {
    $out = recordingOf(['/Applications/A.app' => ['from_the_store' => true, 'electron' => false]]);

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(1);

    expect(is_file($out))->toBeFalse();
});

it('refuses to write a recording with nothing from the store', function (): void {
    $out = recordingOf(['/Applications/Shell.app' => ['from_the_store' => false, 'electron' => true]]);

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(1);
});

it('records what each bundle carries, not just its name', function (): void {
    $installed = [
        '/Applications/Store.app' => ['from_the_store' => true, 'electron' => false],
        '/Applications/Shell.app' => ['from_the_store' => false, 'electron' => true],
    ];

    $out = recordingOf(
        $installed,
        ['/Applications/Store.app' => ['Contents/MacOS/Store', 'Contents/MacOS/helper']],
        [
            '/Applications/Store.app' => ['com.apple.security.app-sandbox' => true],
            '/Applications/Store.app/Contents/MacOS/helper' => ['com.apple.security.inherit' => true],
        ],
    );

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(0);

    $store = recordedBundles($out)[0];

    expect($store['app_entitlements'])->toBe(['com.apple.security.app-sandbox' => true]);
    // The app's own binary is judged as the app, so it is not among the
    // children — recording it there reported every app right as a violation.
    expect(array_keys($store['children']))->toBe(['Contents/MacOS/helper']);
    expect($store['children']['Contents/MacOS/helper']['inMacOsDirectory'])->toBeTrue();
    expect($store['children']['Contents/MacOS/helper']['launched'])->toBeFalse();
});

it('marks an Electron helper under Frameworks as one the app launches', function (): void {
    $helper = 'Contents/Frameworks/Shell Helper.app/Contents/MacOS/Shell Helper';

    $out = recordingOf(
        [
            '/Applications/Store.app' => ['from_the_store' => true, 'electron' => false],
            '/Applications/Shell.app' => ['from_the_store' => false, 'electron' => true],
        ],
        ['/Applications/Shell.app' => ['Contents/MacOS/Shell', $helper]],
    );

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(0);

    $recorded = array_values(array_filter(recordedBundles($out), fn (array $b): bool => $b['name'] === 'Shell.app'))[0];

    expect($recorded['children'][$helper]['launched'])->toBeTrue();
});

it('stamps the recording with the day it was taken', function (): void {
    $out = recordingOf([
        '/Applications/Store.app' => ['from_the_store' => true, 'electron' => false],
        '/Applications/Shell.app' => ['from_the_store' => false, 'electron' => true],
    ]);

    test()->artisan('desktop:record-mac-bundles', ['--out' => $out])->assertExitCode(0);

    /** @var array{recorded_on: string} $decoded */
    $decoded = json_decode((string) file_get_contents($out), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['recorded_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});
