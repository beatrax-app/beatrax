<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\MobilePlatform;
use Modules\Mobile\Internal\Boot\NativeBuildPatches;

// MobilePlatform::savesWebViewDownloads() decides where the recovery-codes
// screen sends its "Download as .txt" — the one screen whose data is shown
// exactly once. The claim is only true because a patch script puts the
// delegate in the generated shell, so these tie the two together: an enum that
// says yes while nothing installs the delegate would send the codes into a
// navigation that saves nothing, which is how they were lost before.

function webViewDownloadScriptsDirectory(): string
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    return (string) $scripts;
}

/** @return list<string> every nativephp_*.php patch script on disk */
function webViewDownloadPatchScripts(): array
{
    $found = glob(webViewDownloadScriptsDirectory().'/nativephp_*.php');

    expect($found)->not->toBeFalse();

    /** @var list<string> $scripts */
    $scripts = $found === false ? [] : array_values($found);

    expect($scripts)->not->toBeEmpty();

    return $scripts;
}

it('backs the iOS claim with a patch that installs the download delegate and the share sheet', function (): void {
    $script = webViewDownloadScriptsDirectory().'/nativephp_ios_download_delegate.php';

    expect(MobilePlatform::Ios->savesWebViewDownloads())->toBeTrue()
        ->and($script)->toBeFile();

    $source = (string) file_get_contents($script);

    // decisionHandler(.download) is what stops the WebView navigating onto the
    // blob; UIActivityViewController is what puts "Save to Files" in front of
    // the reader. Without the second the file lands in a temp directory nobody
    // can reach, which is the same defect one layer down.
    expect($source)
        ->toContain('WKDownloadDelegate')
        ->toContain('decisionHandler(.download)')
        ->toContain('UIActivityViewController');
});

it('re-applies the download delegate on every build rather than only after a composer update', function (): void {
    $perBuild = (new ReflectionClass(NativeBuildPatches::class))
        ->getReflectionConstant('SCRIPTS')
        ->getValue();

    expect($perBuild)->toBeArray();

    /** @var list<string> $perBuild */
    // native:run does not regenerate the tree but native:install does, so a
    // script that only composer knows about is missing from a freshly
    // scaffolded build — and writing the script is not applying it.
    expect($perBuild)->toContain('nativephp_ios_download_delegate.php');
});

it('claims nothing for Android while no patch gives its WebView a download listener', function (): void {
    expect(MobilePlatform::Android->savesWebViewDownloads())->toBeFalse();

    $installers = [];

    foreach (webViewDownloadPatchScripts() as $script) {
        if (str_contains((string) file_get_contents($script), 'setDownloadListener')) {
            $installers[] = basename($script);
        }
    }

    // The inverse guard: the day a patch does add one, this fails and the enum
    // has to be told, rather than Android quietly keeping the copy-in-the-
    // container path after it has a real download route.
    expect($installers)->toBe([], 'a patch now installs an Android DownloadListener: '.implode(', ', $installers));
});

// The route Android takes BECAUSE savesWebViewDownloads() is false: the file is
// written into the app's container and handed to the share sheet. That needed
// Share.File, which the generated shell registers on iOS and not on Android --
// its only share is ACTION_SEND with text/plain and EXTRA_TEXT, which shares a
// message and not a file. So the screen was telling the truth and there was
// still no file.
it('backs the Android container route with a patch that registers Share.File', function (): void {
    $script = webViewDownloadScriptsDirectory().'/nativephp_android_share_file.php';

    expect($script)->toBeFile();

    $source = (string) file_get_contents($script);

    // A file:// URI has not been allowed to leave an app since N, so the
    // provider is what makes the handover possible at all -- and the generated
    // <paths> listed only the cache, while the export is written under files.
    expect($source)
        ->toContain('registry.register("Share.File"')
        ->toContain('FileProvider.getUriForFile')
        ->toContain('Intent.EXTRA_STREAM')
        ->toContain('FLAG_GRANT_READ_URI_PERMISSION')
        // Staged into the cache rather than shared in place: Laravel writes to
        // getDir("storage"), which is a sibling of getFilesDir() and outside
        // every root the provider declares. Sharing in place threw
        // IllegalArgumentException on the device and produced no share sheet.
        ->toContain('context.cacheDir')
        ->toContain('beatrax-share')
        // The sheet's own preview runs in the resolver's process, which
        // Context.startActivity never grants -- only an Activity migrates the
        // extra to ClipData on the caller's behalf. Without this the chooser
        // logs a permission denial and draws a generic tile.
        ->toContain('ClipData.newUri');

    // nativephp/ is generated and this script only ever appends to it, so a
    // root an earlier version added outlives the version that added it. The
    // literal may appear only as the thing being taken back out, and it is
    // taken out through beatraxRewrite rather than preg_replace: a give-up
    // here returns null, the cast writes '', and the verification pass reads
    // an empty permission set as one with nothing left to object to.
    expect(substr_count($source, 'files-path name='))->toBe(1)
        ->and($source)->toContain("\$staleRoot = '<files-path name=\"beatrax-internal\" path=\".\" />';")
        ->and($source)->toMatch('/beatraxRewrite\(.*\$staleRoot.*\$paths\)/');

    $generatedPaths = base_path('mobile-app/nativephp/android/app/src/main/res/xml/file_paths.xml');

    if (is_file($generatedPaths)) {
        expect((string) file_get_contents($generatedPaths))->not->toContain('files-path');
    }

    $perBuild = (new ReflectionClass(NativeBuildPatches::class))
        ->getReflectionConstant('SCRIPTS')
        ->getValue();

    /** @var list<string> $perBuild */
    expect($perBuild)->toContain('nativephp_android_share_file.php');
});
