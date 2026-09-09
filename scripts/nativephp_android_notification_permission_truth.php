<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Carry the answer to the notification dialog back to the application.
 *
 * The local-notifications plugin raises the POST_NOTIFICATIONS dialog and
 * documents the result as arriving through `onRequestPermissionsResult`. The
 * generated activity implements that callback, posts a lifecycle event nothing
 * listens for — its own log says `No listeners for event: onPermissionResult` —
 * and then switches on request codes 1001 and 1002. The plugin's code is
 * 10001. Nothing dispatches the event the plugin says it dispatches, so the
 * answer is dropped.
 *
 * Measured on a Galaxy A51 (2026-09-09): the prompt was accepted, the system
 * recorded `POST_NOTIFICATIONS: granted=true`, and `mobile_notification_grant`
 * still read `granted` NULL.
 *
 * A grant recovers by accident. `RequestPermission` short-circuits when the
 * permission is already held and dispatches the event from that branch, so the
 * next ask — and the reader is asked again while the answer is outstanding —
 * settles it. A refusal has no such branch: it is never recorded, the row stays
 * NULL, and the reader is asked on every page load until Android stops showing
 * the dialog at all. The recovery existed only on the path that did not need
 * it, and the settings screen could never say "you refused this" because
 * nothing had ever written it down.
 *
 * iOS does not have this: its `RequestPermission` dispatches
 * `PermissionGranted` from the authorisation callback itself.
 *
 * The generated tree is rebuilt by `native:install`, so this is applied from
 * composer's hooks rather than hand-edited. It is idempotent, and a missing
 * anchor is a hard failure rather than a silent skip.
 *
 * @link ../.docs/features/mobile/architecture.md
 */

$activity = beatraxScaffoldPath('android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt') ?? '';

if (! is_file($activity)) {
    // The native scaffold is generated on demand and is absent from a fresh
    // checkout; there is nothing to patch until `native:install` has run.
    fwrite(STDOUT, "nativephp_android_notification_permission_truth: no Android scaffold yet — skipping.\n");
    exit(0);
}

$source = (string) file_get_contents($activity);

if (str_contains($source, 'PERMISSION_REQUEST_CODE')) {
    fwrite(STDOUT, "nativephp_android_notification_permission_truth: already patched.\n");
    exit(0);
}

$anchor = '        // Post lifecycle event for each permission result';

if (substr_count($source, $anchor) !== 1) {
    fwrite(STDERR, sprintf(
        "nativephp_android_notification_permission_truth: callback anchor matched %d times, expected 1.\n",
        substr_count($source, $anchor),
    ));
    exit(1);
}

$bridge = <<<'KOTLIN'
        // The plugin raises the notification dialog and documents its result as
        // arriving here. Nothing carried it: the lifecycle event below has no
        // listeners, and the branches under it are for other request codes. A
        // refusal was therefore never recorded at all, and the reader was asked
        // again on every page until Android stopped showing the dialog.
        if (requestCode == com.nativephp.localnotifications.LocalNotificationFunctions.RequestPermission.PERMISSION_REQUEST_CODE) {
            val index = permissions.indexOf(android.Manifest.permission.POST_NOTIFICATIONS)
            val granted = index >= 0 && grantResults.getOrNull(index) == PackageManager.PERMISSION_GRANTED

            NativeActionCoordinator.dispatchEvent(
                this,
                "NativePHP\\LocalNotifications\\Events\\PermissionGranted",
                org.json.JSONObject().put("granted", granted).toString()
            )
        }

KOTLIN;

file_put_contents($activity, str_replace($anchor, $bridge.$anchor, $source));

fwrite(STDOUT, "nativephp_android_notification_permission_truth: patched MainActivity.kt.\n");
