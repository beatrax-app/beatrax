<?php

declare(strict_types=1);

// NotificationManager.notify() does not throw when an app may not post. It
// returns quietly, and the plugin answered success either way.
//
// Measured on a Galaxy A51 with POST_NOTIFICATIONS denied and the channel at
// importance NONE: a manual cash-book entry raised its notification, the bridge
// answered success, the application recorded a delivery, and the shade held
// nothing. Granting the permission and repeating the entry put "Kasboek
// bijgewerkt" on screen — which is how both halves were measured against each
// other rather than assumed.

const DELIVERY_SHOW_ANCHOR = '            Log.d(TAG, "Showing notification: id=$id, title=$title")';

const DELIVERY_IMPORT_ANCHOR = 'import androidx.core.app.NotificationCompat';

function deliveryPluginSource(bool $withAnchors = true): string
{
    $show = $withAnchors ? DELIVERY_SHOW_ANCHOR : '            // this site was rewritten upstream';

    return "package com.nativephp.localnotifications\n\n"
        .DELIVERY_IMPORT_ANCHOR."\n\n"
        ."object LocalNotificationFunctions {\n"
        ."    class Show(private val context: Context) : BridgeFunction {\n"
        ."        override fun execute(parameters: Map<String, Any>): Map<String, Any> {\n"
        .$show."\n\n"
        ."            return try {\n"
        ."                manager.notify(requestCodeFor(id), notification)\n"
        ."                mapOf(\"success\" to true, \"id\" to id)\n"
        ."            } catch (e: Exception) {\n"
        ."                throw BridgeError.ExecutionFailed(\"Failed to show notification: \${e.message}\")\n"
        ."            }\n"
        ."        }\n"
        ."    }\n\n"
        ."    class Schedule(private val context: Context) : BridgeFunction {\n"
        ."        override fun execute(parameters: Map<String, Any>): Map<String, Any> {\n"
        ."            Log.d(TAG, \"Scheduling notification\")\n"
        ."            return mapOf(\"success\" to true)\n"
        ."        }\n"
        ."    }\n}\n";
}

function deliveryScaffold(bool $withAnchors = true): string
{
    $root = sys_get_temp_dir().'/beatrax-delivery-'.bin2hex(random_bytes(6));
    $full = $root.'/vendor/nativephp/mobile-local-notifications/resources/android/LocalNotificationFunctions.kt';

    mkdir(dirname($full), 0700, true);
    file_put_contents($full, deliveryPluginSource($withAnchors));

    return $root;
}

function patchedDeliveryPlugin(string $root): string
{
    return (string) file_get_contents($root.'/vendor/nativephp/mobile-local-notifications/resources/android/LocalNotificationFunctions.kt');
}

/** @return array{status: int, stdout: string, stderr: string} */
function runDeliveryPatch(string $root): array
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_android_notification_delivery_is_reported.php';

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

it('refuses to answer success for a notification the platform will discard', function (): void {
    $root = deliveryScaffold();

    expect(runDeliveryPatch($root)['status'])->toBe(0);

    expect(patchedDeliveryPlugin($root))
        ->toContain('NotificationManagerCompat.from(context).areNotificationsEnabled()')
        ->toContain('throw BridgeError.ExecutionFailed("notifications are disabled for this app")')
        ->toContain('import androidx.core.app.NotificationManagerCompat');
});

// The refusal has to reach the application as a refusal. It reads the bridge's
// error shape and records no delivery for it; a false `success` field would be
// read as one, which is the defect this fixes wearing different clothes.
it('refuses through the shape the application already reads as a refusal', function (): void {
    $root = deliveryScaffold();
    runDeliveryPatch($root);

    $patched = patchedDeliveryPlugin($root);
    $guard = substr($patched, (int) strpos($patched, 'areNotificationsEnabled'));

    expect($guard)->toContain('throw BridgeError.ExecutionFailed')
        ->and($guard)->not->toContain('"success" to false');
});

// The site that must NOT be guarded. Scheduling hands the platform a
// notification for a later time, and a permission the reader has not granted
// yet is one they may grant before it fires; refusing would throw away a
// notification that would have been allowed.
it('leaves scheduling alone, because a future permission is not this one', function (): void {
    $root = deliveryScaffold();
    runDeliveryPatch($root);

    $patched = patchedDeliveryPlugin($root);
    $schedule = substr($patched, (int) strpos($patched, 'class Schedule(private val context: Context)'));

    expect($schedule)->not->toContain('areNotificationsEnabled');
});

it('patches once however often the build regenerates the project', function (): void {
    $root = deliveryScaffold();
    runDeliveryPatch($root);
    $second = runDeliveryPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched');
});

it('fails loudly when the anchor it rewrites has moved', function (): void {
    $result = runDeliveryPatch(deliveryScaffold(withAnchors: false));

    expect($result['status'])->not->toBe(0)
        ->and($result['stderr'])->toContain('expected 1');
});

// The fixture is written by hand. This ties both anchors to the plugin the
// build actually compiles.
it('anchors on text the shipped plugin really carries', function (): void {
    $relative = 'vendor/nativephp/mobile-local-notifications/resources/android/LocalNotificationFunctions.kt';
    $upstream = null;

    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            $upstream = (string) file_get_contents($candidate);
        }
    }

    if ($upstream === null) {
        test()->markTestSkipped('nativephp/mobile is installed only under the mobile Composer root, so the shipped WebView client is not here to read.');
    }

    expect(substr_count($upstream, DELIVERY_IMPORT_ANCHOR))->toBe(1)
        ->and(substr_count($upstream, 'class Schedule(private val context: Context)'))->toBe(1);
});
