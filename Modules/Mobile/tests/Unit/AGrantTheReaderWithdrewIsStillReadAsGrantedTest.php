<?php

declare(strict_types=1);

// The plugin could raise the permission dialog and could not read the answer
// back, so the only record of a grant was the one the app wrote when the
// dialog was answered. Turning notifications off in system settings afterwards
// changed nothing the app could see.
//
// Measured on a Galaxy A51 (2026-09-09): POST_NOTIFICATIONS revoked from the
// app's own settings page, and mobile_notification_grant.granted still 1.

const READBACK_ANDROID_ANCHOR = "    /**\n     * Get scheduled notifications from the database with reconciliation.";

const READBACK_IOS_ANCHOR = '    class GetPending: BridgeFunction {';

function readBackPluginManifest(bool $withAnchor = true): string
{
    $pending = $withAnchor ? 'LocalNotification.GetPending' : 'LocalNotification.Renamed';

    return <<<JSON
    {
        "namespace": "LocalNotification",

        "bridge_functions": [
            {
                "name": "LocalNotification.RequestPermission",
                "android": "com.nativephp.localnotifications.LocalNotificationFunctions.RequestPermission",
                "ios": "LocalNotificationFunctions.RequestPermission",
                "description": "Request notification permission from the user"
            },
            {
                "name": "{$pending}",
                "android": "com.nativephp.localnotifications.LocalNotificationFunctions.GetPending",
                "android_params": ["context"],
                "ios": "LocalNotificationFunctions.GetPending",
                "description": "Get list of pending scheduled notifications"
            }
        ]
    }
    JSON;
}

function readBackAndroidSource(bool $withAnchor = true): string
{
    $anchor = $withAnchor
        ? READBACK_ANDROID_ANCHOR
        : "    /**\n     * Something upstream reworded.";

    return "package com.nativephp.localnotifications\n\n"
        ."import androidx.core.app.NotificationCompat\n\n"
        ."object LocalNotificationFunctions {\n"
        .$anchor."\n"
        ."     * Verifies each entry still has a registered alarm, prunes stale rows.\n"
        ."     */\n"
        ."    class GetPending(private val context: Context) : BridgeFunction {\n"
        ."        override fun execute(parameters: Map<String, Any>): Map<String, Any> {\n"
        ."            return mapOf(\"data\" to emptyList<Map<String, Any>>())\n"
        ."        }\n"
        ."    }\n}\n";
}

function readBackIosSource(bool $withAnchor = true): string
{
    $anchor = $withAnchor ? READBACK_IOS_ANCHOR : '    class Pending: BridgeFunction {';

    return "import UserNotifications\n\n"
        ."enum LocalNotificationFunctions {\n"
        .$anchor."\n"
        ."        func execute(parameters: [String: Any]) throws -> [String: Any] {\n"
        ."            return [\"data\": []]\n"
        ."        }\n"
        ."    }\n}\n";
}

/** @return array{0: string, 1: string} the fake native root and the plugin directory inside it */
function readBackScaffold(bool $androidAnchor = true, bool $iosAnchor = true, bool $manifestAnchor = true): array
{
    $root = sys_get_temp_dir().'/beatrax-readback-'.bin2hex(random_bytes(6));
    $plugin = $root.'/vendor/nativephp/mobile-local-notifications';

    mkdir($plugin.'/resources/android', 0700, true);
    mkdir($plugin.'/resources/ios', 0700, true);

    file_put_contents($plugin.'/nativephp.json', readBackPluginManifest($manifestAnchor)."\n");
    file_put_contents($plugin.'/resources/android/LocalNotificationFunctions.kt', readBackAndroidSource($androidAnchor));
    file_put_contents($plugin.'/resources/ios/LocalNotificationFunctions.swift', readBackIosSource($iosAnchor));

    return [$root, $plugin];
}

/** @return array{status: int, stdout: string, stderr: string} */
function runReadBackPatch(string $root): array
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_notification_grant_is_read_back.php';

    expect(is_file($script))->toBeTrue(sprintf('The patch script is not at %s.', $script));

    $process = proc_open(
        ['php', $script],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['BEATRAX_NATIVE_ROOT' => $root, 'PATH' => (string) getenv('PATH')],
    );

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['status' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

it('gives the application a function that reads the platform back', function (): void {
    [$root, $plugin] = readBackScaffold();

    expect(runReadBackPatch($root)['status'])->toBe(0);

    /** @var array{bridge_functions: list<array<string, mixed>>} $manifest */
    $manifest = json_decode((string) file_get_contents($plugin.'/nativephp.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = array_column($manifest['bridge_functions'], 'name');

    // A function the manifest does not carry is not registered on either
    // shell, whatever the platform source says — the generated bridge router
    // is built from this file.
    expect($names)->toContain('LocalNotification.CheckPermission');
});

it('reads the switch a reader can turn off, not only the permission they granted', function (): void {
    [$root, $plugin] = readBackScaffold();

    runReadBackPatch($root);

    expect((string) file_get_contents($plugin.'/resources/android/LocalNotificationFunctions.kt'))
        ->toContain('class CheckPermission(private val context: Context) : BridgeFunction')
        ->toContain('NotificationManagerCompat.from(context).areNotificationsEnabled()')
        ->toContain('import androidx.core.app.NotificationManagerCompat')
        ->toContain('mapOf("granted" to granted)');
});

// provisional and ephemeral both still deliver. Reading either as a refusal
// would tell a reader their device is dropping notifications it is showing.
it('counts every iOS status that still delivers as granted', function (): void {
    [$root, $plugin] = readBackScaffold();

    runReadBackPatch($root);

    $swift = (string) file_get_contents($plugin.'/resources/ios/LocalNotificationFunctions.swift');

    expect($swift)->toContain('class CheckPermission: BridgeFunction')
        ->toContain('getNotificationSettings')
        ->toContain('case .authorized, .provisional, .ephemeral:')
        ->toContain('["granted": granted]');
});

// The bridge runs on the PHP worker thread, so the plugin's own semaphore
// pattern is what makes an asynchronous settings read answerable synchronously.
it('bridges the asynchronous iOS read the way the plugin already bridges GetPending', function (): void {
    [$root, $plugin] = readBackScaffold();

    runReadBackPatch($root);

    $swift = (string) file_get_contents($plugin.'/resources/ios/LocalNotificationFunctions.swift');
    $body = substr($swift, (int) strpos($swift, 'class CheckPermission'));

    expect($body)->toContain('DispatchSemaphore(value: 0)')
        ->toContain('semaphore.signal()')
        ->toContain('semaphore.wait()');
});

it('produces the same bytes on a second run', function (): void {
    [$root, $plugin] = readBackScaffold();

    runReadBackPatch($root);

    $first = [
        (string) file_get_contents($plugin.'/nativephp.json'),
        (string) file_get_contents($plugin.'/resources/android/LocalNotificationFunctions.kt'),
        (string) file_get_contents($plugin.'/resources/ios/LocalNotificationFunctions.swift'),
    ];

    $second = runReadBackPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched')
        ->and([
            (string) file_get_contents($plugin.'/nativephp.json'),
            (string) file_get_contents($plugin.'/resources/android/LocalNotificationFunctions.kt'),
            (string) file_get_contents($plugin.'/resources/ios/LocalNotificationFunctions.swift'),
        ])->toBe($first);
});

it('leaves the import alone when the delivery guard already added it', function (): void {
    [$root, $plugin] = readBackScaffold();
    $kt = $plugin.'/resources/android/LocalNotificationFunctions.kt';

    file_put_contents($kt, str_replace(
        "import androidx.core.app.NotificationCompat\n",
        "import androidx.core.app.NotificationCompat\nimport androidx.core.app.NotificationManagerCompat\n",
        (string) file_get_contents($kt),
    ));

    expect(runReadBackPatch($root)['status'])->toBe(0)
        ->and(substr_count((string) file_get_contents($kt), 'import androidx.core.app.NotificationManagerCompat'))->toBe(1);
});

it('fails loudly rather than silently when a platform anchor has gone', function (bool $android, bool $ios, bool $manifest): void {
    [$root] = readBackScaffold($android, $ios, $manifest);

    $result = runReadBackPatch($root);

    expect($result['status'])->toBe(1)
        ->and($result['stderr'])->toContain('anchor matched 0 times');
})->with([
    'android' => [false, true, true],
    'ios' => [true, false, true],
    'manifest' => [true, true, false],
]);

it('skips a checkout that has not installed the plugin', function (): void {
    $root = sys_get_temp_dir().'/beatrax-readback-empty-'.bin2hex(random_bytes(6));
    mkdir($root, 0700, true);

    $result = runReadBackPatch($root);

    expect($result['status'])->toBe(0)
        ->and($result['stdout'])->toContain('no local-notifications plugin');
});
