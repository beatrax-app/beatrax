<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Give the application a way to ask what the platform allows NOW.
 *
 * The plugin exposes `RequestPermission` and nothing that reads the answer
 * back, so the only record of a grant is the one the app wrote when the dialog
 * was answered. A reader who turns notifications off in system settings
 * afterwards changes nothing the app can see: the settings screen keeps saying
 * the device will show them, and the delivery record keeps counting arrivals.
 *
 * Measured on a Galaxy A51 (2026-09-09): POST_NOTIFICATIONS revoked from the
 * app's settings page, and `mobile_notification_grant.granted` still 1.
 *
 * `areNotificationsEnabled()` on Android rather than the runtime permission,
 * for the same reason the delivery guard uses it: a reader who holds the
 * permission and switches the app's notifications off is equally unreachable,
 * and only one of those two is a permission. iOS answers the same question as
 * `authorizationStatus`, where provisional and ephemeral both still deliver.
 *
 * Both halves are synchronous. Android's read is a property; iOS's settings
 * call is asynchronous and is bridged with the DispatchSemaphore the plugin
 * already uses for `GetPending` and `Cancel` — the bridge runs on the PHP
 * worker thread, never the main thread, so the wait cannot deadlock the
 * completion handler.
 *
 * Patched in the plugin's own resources rather than the generated project,
 * because `app/src/nativephp/` is regenerated from these on every build and an
 * edit there is discarded — measured, by making exactly that mistake first.
 *
 * @link ../.docs/features/mobile/architecture.md
 */

$root = 'nativephp/mobile-local-notifications/';
$manifestPath = beatraxMobileVendorPath($root.'nativephp.json') ?? '';
$androidPath = beatraxMobileVendorPath($root.'resources/android/LocalNotificationFunctions.kt') ?? '';
$iosPath = beatraxMobileVendorPath($root.'resources/ios/LocalNotificationFunctions.swift') ?? '';

if (! is_file($manifestPath) || ! is_file($androidPath) || ! is_file($iosPath)) {
    // The plugin is installed only under the mobile Composer root, and is
    // absent from a checkout that has not run composer install there.
    fwrite(STDOUT, "nativephp_notification_grant_is_read_back: no local-notifications plugin — skipping.\n");
    exit(0);
}

/**
 * Replaces one occurrence of an anchor, or fails loudly.
 */
function beatraxGrantReadBackReplace(string $subject, string $anchor, string $replacement, string $what): string
{
    $found = substr_count($subject, $anchor);

    if ($found !== 1) {
        fwrite(STDERR, sprintf(
            "nativephp_notification_grant_is_read_back: %s anchor matched %d times, expected 1.\n",
            $what,
            $found,
        ));
        fwrite(STDERR, "The plugin changed shape; confirm the grant is still read back before shipping.\n");
        exit(1);
    }

    return str_replace($anchor, $replacement, $subject);
}

$applied = [];

// ─── The manifest, which is what makes the function reachable at all ────────
$manifest = (string) file_get_contents($manifestPath);

if (! str_contains($manifest, 'LocalNotification.CheckPermission')) {
    $entry = <<<'JSON'
            {
                "name": "LocalNotification.CheckPermission",
                "android": "com.nativephp.localnotifications.LocalNotificationFunctions.CheckPermission",
                "android_params": ["context"],
                "ios": "LocalNotificationFunctions.CheckPermission",
                "description": "Read back whether the platform will show notifications now"
            },
            {
                "name": "LocalNotification.GetPending",
    JSON;

    $manifest = beatraxGrantReadBackReplace(
        $manifest,
        "        {\n            \"name\": \"LocalNotification.GetPending\",",
        rtrim($entry),
        'manifest',
    );

    $decoded = json_decode($manifest, true);

    if (! is_array($decoded)) {
        fwrite(STDERR, sprintf("nativephp_notification_grant_is_read_back: %s is no longer valid JSON after patching.\n", $manifestPath));
        exit(1);
    }

    file_put_contents($manifestPath, $manifest);
    $applied[] = 'nativephp.json';
}

// ─── Android ────────────────────────────────────────────────────────────────
$android = (string) file_get_contents($androidPath);

if (! str_contains($android, 'class CheckPermission')) {
    // The delivery guard adds the same import, and either script may run first.
    if (! str_contains($android, 'import androidx.core.app.NotificationManagerCompat')) {
        $android = beatraxGrantReadBackReplace(
            $android,
            "import androidx.core.app.NotificationCompat\n",
            "import androidx.core.app.NotificationCompat\nimport androidx.core.app.NotificationManagerCompat\n",
            'Android import',
        );
    }

    $kotlin = <<<'KOTLIN'
    /**
     * What the platform will allow right now, rather than what the reader
     * answered once. A grant the reader withdrew in settings is invisible to
     * every record the app keeps of the dialog.
     */
    class CheckPermission(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val granted = NotificationManagerCompat.from(context).areNotificationsEnabled()

            Log.d(TAG, "Notification permission read back: granted=$granted")

            return mapOf("granted" to granted)
        }
    }
KOTLIN;

    $anchor = "    /**\n     * Get scheduled notifications from the database with reconciliation.";

    $android = beatraxGrantReadBackReplace(
        $android,
        $anchor,
        rtrim($kotlin)."\n\n".$anchor,
        'Android GetPending',
    );

    file_put_contents($androidPath, $android);
    $applied[] = 'LocalNotificationFunctions.kt';
}

// ─── iOS ────────────────────────────────────────────────────────────────────
$ios = (string) file_get_contents($iosPath);

if (! str_contains($ios, 'class CheckPermission')) {
    $swift = <<<'SWIFT'
    /// What the platform will allow right now. provisional and ephemeral both
    /// still deliver, so neither is a refusal; only denied and notDetermined
    /// mean the reader will see nothing.
    class CheckPermission: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            var granted = false

            let semaphore = DispatchSemaphore(value: 0)
            UNUserNotificationCenter.current().getNotificationSettings { settings in
                switch settings.authorizationStatus {
                case .authorized, .provisional, .ephemeral:
                    granted = true
                default:
                    granted = false
                }
                semaphore.signal()
            }
            semaphore.wait()

            return ["granted": granted]
        }
    }

    class GetPending: BridgeFunction {
SWIFT;

    $ios = beatraxGrantReadBackReplace(
        $ios,
        '    class GetPending: BridgeFunction {',
        rtrim($swift),
        'iOS GetPending',
    );

    file_put_contents($iosPath, $ios);
    $applied[] = 'LocalNotificationFunctions.swift';
}

fwrite(STDOUT, $applied === []
    ? "nativephp_notification_grant_is_read_back: already patched.\n"
    : 'nativephp_notification_grant_is_read_back: patched '.implode(', ', $applied).".\n");
