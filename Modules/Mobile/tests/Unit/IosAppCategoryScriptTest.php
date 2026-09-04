<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

// The generated target carries GENERATE_INFOPLIST_FILE = YES beside an explicit
// INFOPLIST_FILE, so Xcode merges INFOPLIST_KEY_* build settings into the
// processed plist. NativePHP's template ships the category setting empty, and
// the last built app carries LSApplicationCategoryType as an empty string while
// the source Info.plist never mentions it — so this setting is what ships.

/** @return string the fake native root holding a generated Xcode project */
function appCategoryScaffold(string $categoryLine): string
{
    $root = sys_get_temp_dir().'/beatrax-category-'.bin2hex(random_bytes(6));

    mkdir($root.'/nativephp/ios/NativePHP.xcodeproj', 0o755, true);

    // Two build configurations, because the template declares the setting in
    // both and a rewrite that fixed only the first would ship an empty
    // category from whichever configuration the archive used.
    $pbxproj = "// !\$*UTF8*\$!\n{\n"
        ."\t\t\tINFOPLIST_KEY_CFBundleDisplayName = \"Beatrax\";\n"
        .$categoryLine
        ."\t\t\tname = Debug;\n"
        ."\t\t\tINFOPLIST_KEY_CFBundleDisplayName = \"Beatrax\";\n"
        .$categoryLine
        ."\t\t\tname = Release;\n}\n";

    file_put_contents($root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj', $pbxproj);

    return $root;
}

function runAppCategoryPatch(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process([PHP_BINARY, $scripts.'/nativephp_ios_app_category.php'], env: ['BEATRAX_NATIVE_ROOT' => $root]);
    $process->run();

    return $process;
}

const EMPTY_CATEGORY = "\t\t\tINFOPLIST_KEY_LSApplicationCategoryType = \"\";\n";

it('gives every build configuration the finance category', function (): void {
    $root = appCategoryScaffold(EMPTY_CATEGORY);

    $process = runAppCategoryPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $pbxproj = (string) file_get_contents($root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj');

    expect(substr_count($pbxproj, 'INFOPLIST_KEY_LSApplicationCategoryType = "public.app-category.finance";'))->toBe(2);
    expect($pbxproj)->not->toContain('INFOPLIST_KEY_LSApplicationCategoryType = "";');
});

it('re-runs without changing a byte', function (): void {
    $root = appCategoryScaffold(EMPTY_CATEGORY);
    $path = $root.'/nativephp/ios/NativePHP.xcodeproj/project.pbxproj';

    runAppCategoryPatch($root);

    $before = (string) file_get_contents($path);

    $repeat = runAppCategoryPatch($root);

    expect($repeat->isSuccessful())->toBeTrue($repeat->getErrorOutput());
    expect((string) file_get_contents($path))->toBe($before);
});

// A skip here would ship an empty category from a green log, which is the
// failure the script exists to stop: the build succeeds and App Store Connect
// refuses the upload.
it('refuses a project that no longer declares the setting', function (): void {
    $process = runAppCategoryPatch(appCategoryScaffold(''));

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('is not declared');
});

it('skips cleanly when there is no iOS scaffold at all', function (): void {
    $root = sys_get_temp_dir().'/beatrax-category-none-'.bin2hex(random_bytes(6));

    mkdir($root, 0o755, true);

    $process = runAppCategoryPatch($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    expect($process->getOutput())->toContain('no iOS scaffold yet');
});
