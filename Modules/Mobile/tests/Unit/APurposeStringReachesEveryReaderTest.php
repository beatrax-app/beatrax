<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

// A purpose string is the only sentence iOS shows before it hands the app a
// camera, a face scanner or the local network, and the reader decides on it.
// Twenty-six interface languages and one English sentence means twenty-five
// readers deciding on a sentence they may not be able to read — including the
// Face ID prompt, which releases the key the ledger is encrypted with.

const PURPOSE_STRING_KEYS = [
    'NSCameraUsageDescription',
    'NSFaceIDUsageDescription',
    'NSLocalNetworkUsageDescription',
];

// The plugins' own strings, verbatim. mobile-scanner and mobile-biometrics each
// declare one and IOSPluginCompiler merges them; they describe NativePHP's demo
// app, and one of them claims the app scans barcodes.
const PURPOSE_STRINGS_FROM_A_PLUGIN = [
    'This app requires camera access to scan QR codes and barcodes',
    'This app uses Face ID for secure authentication',
    'Unlock Beatrax with Face ID',
];

/** The suite runs from both Composer roots, so Modules/ is not at one offset. */
function purposeStringRepoRoot(): string
{
    return dirname((string) realpath(base_path('Modules')));
}

/** @return array<string, array<string, string>> locale => Info.plist key => sentence */
function localisedPurposeStrings(): array
{
    $localised = [];

    foreach (glob(purposeStringRepoRoot().'/Modules/Mobile/Resources/ios/lang/*/purpose-strings.php') ?: [] as $file) {
        /** @var array<string, string> $strings */
        $strings = require $file;

        $localised[basename(dirname($file))] = $strings;
    }

    ksort($localised);

    return $localised;
}

/** @return list<string> every locale the interface ships in, read off the lang tree */
function purposeStringInterfaceLocales(): array
{
    $locales = array_map('basename', glob(purposeStringRepoRoot().'/Modules/Mobile/Resources/lang/*', GLOB_ONLYDIR) ?: []);

    sort($locales);

    return $locales;
}

it('carries a purpose string for every language the interface ships in', function (): void {
    $shipped = purposeStringInterfaceLocales();

    expect($shipped)->toHaveCount(26);

    $localised = array_keys(localisedPurposeStrings());
    sort($localised);

    expect($localised)->toBe($shipped, sprintf(
        "The interface ships in %d languages and the purpose strings in %d.\n".
        'A locale added to the interface needs a '.
        'Modules/Mobile/Resources/ios/lang/<locale>/purpose-strings.php as well.',
        count($shipped),
        count($localised),
    ));
});

it('declares every purpose-string key in every locale', function (): void {
    $missing = [];

    foreach (localisedPurposeStrings() as $locale => $strings) {
        foreach (PURPOSE_STRING_KEYS as $key) {
            $value = $strings[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                $missing[] = $locale.' · '.$key;
            }
        }
    }

    expect($missing)->toBe([], "purpose strings with nothing behind them:\n  ".implode("\n  ", $missing));
});

it('names the product in every language rather than only in English', function (): void {
    $anonymous = [];

    foreach (localisedPurposeStrings() as $locale => $strings) {
        foreach (PURPOSE_STRING_KEYS as $key) {
            if (! str_contains($strings[$key] ?? '', 'Beatrax')) {
                $anonymous[] = $locale.' · '.$key;
            }
        }
    }

    expect($anonymous)->toBe([], "purpose strings that name no product:\n  ".implode("\n  ", $anonymous));
});

// The inherited scanner string is the case this rule was written for: a reader
// granting the camera for "barcodes" was told something untrue about the use.
it('never repeats a sentence a dependency wrote about its own product', function (): void {
    $inherited = [];

    foreach (localisedPurposeStrings() as $locale => $strings) {
        foreach ($strings as $key => $value) {
            if (in_array($value, PURPOSE_STRINGS_FROM_A_PLUGIN, true)) {
                $inherited[] = $locale.' · '.$key;
            }
        }
    }

    expect($inherited)->toBe([], "purpose strings inherited from a plugin:\n  ".implode("\n  ", $inherited));
});

it('claims no capability the app has in no language', function (): void {
    $claimed = [];

    foreach (localisedPurposeStrings() as $locale => $strings) {
        if (str_contains(strtolower($strings['NSCameraUsageDescription'] ?? ''), 'barcode')) {
            $claimed[] = $locale;
        }
    }

    expect($claimed)->toBe([]);
});

// One home for the base language. The Info.plist value and the twenty-five
// translations are written against each other, and a second copy of the English
// would show its drift only as a prompt whose two languages disagree.
it('takes the Info.plist value from the same file the translations live in', function (): void {
    $config = require base_path(
        is_file(base_path('mobile-app/config/nativephp.php')) ? 'mobile-app/config/nativephp.php' : 'config/nativephp.php',
    );

    $english = localisedPurposeStrings()['en'];

    foreach (PURPOSE_STRING_KEYS as $key) {
        expect($config['permissions'][$key] ?? null)->toBe($english[$key]);
    }
});

function purposeStringScaffold(bool $synchronized = true): string
{
    $root = sys_get_temp_dir().'/beatrax-purpose-'.bin2hex(random_bytes(6));

    mkdir($root.'/nativephp/ios/NativePHP', 0o755, true);
    mkdir($root.'/nativephp/ios/NativePHP.xcodeproj', 0o755, true);

    $group = $synchronized ? 'PBXFileSystemSynchronizedRootGroup' : 'PBXGroup';

    file_put_contents(
        $root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj',
        "// !\$*UTF8*\$!\n{\n\t\tISA = {\n\t\t\tisa = {$group};\n\t\t\tpath = NativePHP;\n\t\t};\n}\n",
    );

    return $root;
}

function runPurposeStringPatch(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process(
        [PHP_BINARY, $scripts.'/nativephp_ios_purpose_string_localisations.php'],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );
    $process->run();

    return $process;
}

it('writes one .lproj folder per language into the synchronized group', function (): void {
    $root = purposeStringScaffold();

    $process = runPurposeStringPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect(glob($root.'/nativephp/ios/NativePHP/*.lproj/InfoPlist.strings') ?: [])
        ->toHaveCount(count(localisedPurposeStrings()));
});

it('writes each sentence as a quoted, terminated .strings entry', function (): void {
    $root = purposeStringScaffold();

    runPurposeStringPatch($root);

    $dutch = (string) file_get_contents($root.'/nativephp/ios/NativePHP/nl.lproj/InfoPlist.strings');

    expect($dutch)
        ->toContain('"NSFaceIDUsageDescription" = "')
        ->toContain(localisedPurposeStrings()['nl']['NSFaceIDUsageDescription'])
        ->toContain('";');
});

// A file in no build phase reads exactly like a file that was never written, and
// the failure it produces is every prompt silently back in English.
it('refuses to leave localisations that no build phase would copy', function (): void {
    $process = runPurposeStringPatch(purposeStringScaffold(synchronized: false));

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('synchronized root group');
});

it('produces the same bytes on a second run', function (): void {
    $root = purposeStringScaffold();

    runPurposeStringPatch($root);

    $before = (string) file_get_contents($root.'/nativephp/ios/NativePHP/de.lproj/InfoPlist.strings');

    $repeat = runPurposeStringPatch($root);

    expect($repeat->isSuccessful())->toBeTrue($repeat->getErrorOutput());
    expect((string) file_get_contents($root.'/nativephp/ios/NativePHP/de.lproj/InfoPlist.strings'))->toBe($before);
    expect($repeat->getOutput())->toContain('already applied');
});
