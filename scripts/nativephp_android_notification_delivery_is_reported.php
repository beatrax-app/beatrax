<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Stop reporting a notification the platform discarded as one it delivered.
 *
 * `NotificationManager.notify()` does not throw when an app may not post. It
 * returns quietly, and the caller cannot tell a delivery from a discard. The
 * plugin's `Show` logs "Notification shown successfully" and answers
 * `success: true` either way.
 *
 * Measured on a Galaxy A51 (2026-09-09) with POST_NOTIFICATIONS denied and the
 * channel at importance NONE: a manual cash-book entry raised its notification,
 * the bridge answered success, `DispatchMobileNotification::recordDeliveryOutcome`
 * read that as a delivery and recorded one, and the notification shade held
 * nothing. Granting the permission and repeating the same entry put
 * "Kasboek bijgewerkt / 1 boeking handmatig toegevoegd" on screen, which is
 * how both halves were measured against each other.
 *
 * The application cannot make this call itself. The plugin exposes a way to
 * request the permission and no way to read it, so the only thing that knows
 * whether the next notification will be seen is the code posting it.
 *
 * `areNotificationsEnabled()` rather than the runtime permission: a reader who
 * grants POST_NOTIFICATIONS and then turns the app's notifications off in
 * settings is equally unreachable, and only one of those is a permission.
 *
 * Only `Show` is guarded. `Schedule` deliberately is not: it hands the platform
 * a notification for a later time, and a permission the reader has not granted
 * yet is one they may grant before it fires. Refusing to schedule would throw
 * away a notification that would have been allowed.
 *
 * Patched in the plugin's own resources rather than the generated project,
 * because `app/src/nativephp/` is regenerated from these on every build and an
 * edit there is discarded — measured, by making exactly that mistake first.
 *
 * @link ../.docs/features/mobile/architecture.md
 */

$target = beatraxMobileVendorPath('nativephp/mobile-local-notifications/resources/android/LocalNotificationFunctions.kt') ?? '';

if (! is_file($target)) {
    // The plugin is installed only under the mobile Composer root, and is
    // absent from a checkout that has not run composer install there.
    fwrite(STDOUT, "nativephp_android_notification_delivery_is_reported: no local-notifications plugin — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($target);

if (str_contains($source, 'areNotificationsEnabled')) {
    fwrite(STDOUT, "nativephp_android_notification_delivery_is_reported: already patched.\n");
    exit(0);
}

/**
 * Replaces one occurrence of an anchor, or fails loudly.
 */
function beatraxDeliveryReplace(string $subject, string $anchor, string $replacement, string $what): string
{
    $found = substr_count($subject, $anchor);

    if ($found !== 1) {
        fwrite(STDERR, sprintf(
            "nativephp_android_notification_delivery_is_reported: %s anchor matched %d times, expected 1.\n",
            $what,
            $found,
        ));
        exit(1);
    }

    return str_replace($anchor, $replacement, $subject);
}

$source = beatraxDeliveryReplace(
    $source,
    "import androidx.core.app.NotificationCompat\n",
    "import androidx.core.app.NotificationCompat\nimport androidx.core.app.NotificationManagerCompat\n",
    'import',
);

$guard = <<<'KOTLIN'
            Log.d(TAG, "Showing notification: id=$id, title=$title")

            // notify() does not throw when the app may not post — it returns
            // quietly, and answering success here recorded a delivery for a
            // notification the reader never saw. Measured on an A51 with the
            // permission denied: "shown successfully", and an empty shade.
            if (!NotificationManagerCompat.from(context).areNotificationsEnabled()) {
                Log.w(TAG, "Notification not delivered: this app may not post notifications")
                throw BridgeError.ExecutionFailed("notifications are disabled for this app")
            }

KOTLIN;

$source = beatraxDeliveryReplace(
    $source,
    "            Log.d(TAG, \"Showing notification: id=\$id, title=\$title\")\n",
    $guard,
    'Show body',
);

file_put_contents($target, $source);

fwrite(STDOUT, "nativephp_android_notification_delivery_is_reported: patched LocalNotificationFunctions.kt.\n");
