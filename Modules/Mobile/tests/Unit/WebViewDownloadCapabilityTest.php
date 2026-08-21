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
