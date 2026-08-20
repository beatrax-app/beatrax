<?php

declare(strict_types=1);

/*
 * The patch scripts resolved the generated project as ../mobile-app/nativephp/,
 * which exists in this repository and nowhere else. In a materialized
 * mobile-build tree the scripts and the scaffold share one root, so every one
 * of them printed "no scaffold yet — skipping" and exited 0: a green build
 * carrying NativePHP's own icon, no WebView camera permission and
 * allowBackup="true" on a financial app.
 */

/** @return list<string> the scripts that patch the generated native project */
function scaffoldPatchScripts(): array
{
    return [
        'nativephp_android_adaptive_icon.php',
        'nativephp_android_file_chooser.php',
        'nativephp_brand_boot_splash.php',
        'nativephp_exclude_data_from_backup.php',
        'nativephp_extend_bundle_copy_timeout.php',
        'nativephp_grant_webview_camera.php',
        'nativephp_ios_app_icon.php',
        'nativephp_ios_download_delegate.php',
        'nativephp_ios_request_body_stream.php',
        'nativephp_keep_webview_cookies.php',
        'nativephp_theme_native_shell.php',
    ];
}

function scaffoldScriptsDirectory(): string
{
    $here = base_path('scripts');

    return is_dir($here) ? $here : base_path('../scripts');
}

it('resolves no scaffold path by hand any more', function (): void {
    $hardcoded = [];
    $unprobed = [];

    foreach (scaffoldPatchScripts() as $script) {
        $path = scaffoldScriptsDirectory().DIRECTORY_SEPARATOR.$script;
        $source = (string) file_get_contents($path);

        // Either literal is what pinned them to this repository's layout.
        if (str_contains($source, "/../mobile-app/nativephp/")
            || str_contains($source, "/mobile-app/nativephp/")
            || str_contains($source, "/../mobile-app/vendor/")) {
            $hardcoded[] = $script;
        }

        if (! str_contains($source, 'beatraxScaffoldPath(')
            && ! str_contains($source, 'beatraxMobileVendorPath(')) {
            $unprobed[] = $script;
        }
    }

    expect($hardcoded)->toBe([], 'still pinned to ../mobile-app/: '.implode(', ', $hardcoded))
        ->and($unprobed)->toBe([], 'does not probe for the scaffold: '.implode(', ', $unprobed));
});

it('finds the scaffold in either tree shape', function (): void {
    require_once scaffoldScriptsDirectory().DIRECTORY_SEPARATOR.'nativephp_scaffold_root.php';

    $base = sys_get_temp_dir().'/beatrax-scaffold-'.bin2hex(random_bytes(6));

    // The materialized shape: scripts and scaffold under one root.
    mkdir($base.'/nativephp/android/app/src/main', 0o777, true);
    file_put_contents($base.'/nativephp/android/app/src/main/AndroidManifest.xml', '<manifest/>');

    putenv('BEATRAX_NATIVE_ROOT='.$base);

    try {
        expect(beatraxScaffoldPath('android/app/src/main'))
            ->toBe($base.'/nativephp/android/app/src/main');

        // A path that is not there is still null, so the skip stays a skip.
        expect(beatraxScaffoldPath('ios/NativePHP/NotHere.swift'))->toBeNull();
    } finally {
        putenv('BEATRAX_NATIVE_ROOT');
    }
});

it('still finds this repository own scaffold', function (): void {
    require_once scaffoldScriptsDirectory().DIRECTORY_SEPARATOR.'nativephp_scaffold_root.php';

    $resolved = beatraxScaffoldPath('android/app/src/main');

    expect($resolved)->toBeString()
        ->and(is_file($resolved.'/AndroidManifest.xml'))->toBeTrue();
});
