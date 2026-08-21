<?php

declare(strict_types=1);

use Modules\Mobile\Internal\Boot\NativeBuildPatches;
use Symfony\Component\Process\Process;

function stripPermissionsFixtureManifest(): string
{
    return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android"
            xmlns:tools="http://schemas.android.com/tools">

            <uses-permission android:name="android.permission.INTERNET" />
            <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />

            <!-- Device.* core built-in (migrated from nativephp/mobile-device) -->
            <uses-permission android:name="android.permission.VIBRATE" />
            <uses-permission android:name="android.permission.FLASHLIGHT" />    <uses-permission android:name="android.permission.USE_BIOMETRIC" />
            <uses-permission android:name="android.permission.SCHEDULE_EXACT_ALARM" />
            <uses-permission android:name="android.permission.USE_EXACT_ALARM" />
            <uses-permission android:name="android.permission.RECEIVE_BOOT_COMPLETED" />
            <uses-permission android:name="android.permission.CAMERA" />

            <!-- NativePHP Plugin Permissions -->
            <uses-permission android:name="android.permission.POST_NOTIFICATIONS" />

            <application android:allowBackup="false" android:label="Beatrax" tools:targetApi="31">
            </application>
        </manifest>
        XML;
}

/** @return array{0: string, 1: string} the fake native root and the manifest path inside it */
function stripPermissionsScaffold(string $manifest): array
{
    $root = sys_get_temp_dir().'/beatrax-perms-'.bin2hex(random_bytes(6));
    $main = $root.'/nativephp/android/app/src/main';

    mkdir($main, 0o755, true);
    file_put_contents($main.'/AndroidManifest.xml', $manifest."\n");

    return [$root, $main.'/AndroidManifest.xml'];
}

function runStripPermissions(string $root): Process
{
    $scripts = NativeBuildPatches::locate(base_path());

    expect($scripts)->not->toBeNull();

    $process = new Process(
        [PHP_BINARY, $scripts.'/nativephp_strip_unused_permissions.php'],
        env: ['BEATRAX_NATIVE_ROOT' => $root],
    );
    $process->run();

    return $process;
}

// native:install writes USE_EXACT_ALARM into the manifest, Play restricts that
// permission to alarm clocks and calendars, and shipping it is grounds for removal
// from the store. The fixture keeps the generated manifest's quirk of putting two
// permissions on one line, which is what makes a line-based edit dangerous.

it('pins out every permission the app never exercises', function (): void {
    [$root, $manifest] = stripPermissionsScaffold(stripPermissionsFixtureManifest());

    $process = runStripPermissions($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $patched = (string) file_get_contents($manifest);

    // What the merger will see: comments are invisible to it, and one of them
    // deliberately carries a plain declaration so the plugin compiler's
    // substring check finds the permission and stops re-adding it.
    $merged = (string) preg_replace('/<!--.*?-->/s', '', $patched);

    foreach ([
        'android.permission.USE_EXACT_ALARM',
        'android.permission.SCHEDULE_EXACT_ALARM',
        'android.permission.RECEIVE_BOOT_COMPLETED',
        'android.permission.FLASHLIGHT',
        'android.permission.FOREGROUND_SERVICE',
        'android.permission.USE_FINGERPRINT',
    ] as $gone) {
        expect($merged)
            ->toContain('<uses-permission android:name="'.$gone.'" tools:node="remove" />')
            ->not->toContain('<uses-permission android:name="'.$gone.'" />');

        // And the decoy the compiler reads is present in the raw file.
        expect($patched)->toContain('<!-- <uses-permission android:name="'.$gone.'" />');
    }
});

it('keeps the permissions the app really uses', function (): void {
    // VIBRATE is the trap: nothing in the PHP reaches it, but ScannerActivity
    // buzzes on a successful QR scan and that scanner is how a phone pairs.
    [$root, $manifest] = stripPermissionsScaffold(stripPermissionsFixtureManifest());

    runStripPermissions($root);

    $patched = (string) file_get_contents($manifest);

    foreach ([
        'android.permission.INTERNET',
        'android.permission.ACCESS_NETWORK_STATE',
        'android.permission.VIBRATE',
        'android.permission.USE_BIOMETRIC',
        'android.permission.CAMERA',
        'android.permission.POST_NOTIFICATIONS',
    ] as $kept) {
        expect($patched)->toContain('<uses-permission android:name="'.$kept.'" />');
    }
});

it('leaves the manifest well-formed even where two permissions share a line', function (): void {
    [$root, $manifest] = stripPermissionsScaffold(stripPermissionsFixtureManifest());

    runStripPermissions($root);

    expect(@simplexml_load_file($manifest))->not->toBeFalse();
});

it('produces the same bytes on a second run', function (): void {
    [$root, $manifest] = stripPermissionsScaffold(stripPermissionsFixtureManifest());

    runStripPermissions($root);
    $first = (string) file_get_contents($manifest);

    $second = runStripPermissions($root);

    expect($second->isSuccessful())->toBeTrue($second->getErrorOutput());
    expect((string) file_get_contents($manifest))->toBe($first);
});

it('fails loudly rather than writing an inert tools:node when the namespace is gone', function (): void {
    $withoutTools = str_replace(
        "\n    xmlns:tools=\"http://schemas.android.com/tools\"",
        '',
        stripPermissionsFixtureManifest(),
    );

    [$root] = stripPermissionsScaffold($withoutTools);

    $process = runStripPermissions($root);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('tools namespace');
});

it('fails loudly when the anchor it writes against has gone', function (): void {
    $withoutAnchor = str_replace(
        '    <uses-permission android:name="android.permission.INTERNET" />',
        '    <uses-permission android:name="android.permission.INTERNET"/>',
        stripPermissionsFixtureManifest(),
    );

    [$root] = stripPermissionsScaffold($withoutAnchor);

    $process = runStripPermissions($root);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('INTERNET anchor not found');
});

// What native:install actually writes, which is where composer's post-update
// hook runs this script. CAMERA, USE_BIOMETRIC and POST_NOTIFICATIONS are
// absent because they come from the plugin manifests at Gradle's merge.
function stripPermissionsFreshInstallManifest(): string
{
    return <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <manifest xmlns:android="http://schemas.android.com/apk/res/android"
            xmlns:tools="http://schemas.android.com/tools">

            <uses-permission android:name="android.permission.INTERNET" />
            <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />

            <!-- Device.* core built-in (migrated from nativephp/mobile-device) -->
            <uses-permission android:name="android.permission.VIBRATE" />
            <uses-permission android:name="android.permission.FLASHLIGHT" />

            <application android:allowBackup="true" android:label="Beatrax" tools:targetApi="31">
            </application>
        </manifest>
        XML;
}

it('succeeds on the manifest native:install writes, before the plugin compiler has run', function (): void {
    // It exited 1 here on every build off a fresh scaffold, looking for three
    // permissions the merge had not contributed yet. A step that always fails
    // is a step whose red exit code nobody reads the day it means something.
    [$root, $manifest] = stripPermissionsScaffold(stripPermissionsFreshInstallManifest());

    $process = runStripPermissions($root);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $merged = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($manifest));

    foreach ([
        'android.permission.INTERNET',
        'android.permission.ACCESS_NETWORK_STATE',
        'android.permission.VIBRATE',
    ] as $kept) {
        expect($merged)->toContain('<uses-permission android:name="'.$kept.'" />');
    }
});

it('fails loudly when a permission the merge contributes is pinned out here', function (): void {
    // The one way this script could keep the camera out of the built APK, and
    // nothing downstream reports it — the QR scanner just throws on a phone.
    $sabotaged = str_replace(
        '    <uses-permission android:name="android.permission.CAMERA" />',
        '    <uses-permission tools:node="remove" android:name="android.permission.CAMERA" />',
        stripPermissionsFixtureManifest(),
    );

    [$root] = stripPermissionsScaffold($sabotaged);

    $process = runStripPermissions($root);

    expect($process->isSuccessful())->toBeFalse();
    expect($process->getErrorOutput())->toContain('android.permission.CAMERA is pinned out');
});
