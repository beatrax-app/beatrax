<?php

declare(strict_types=1);

// The plugin raises the POST_NOTIFICATIONS dialog and documents its result as
// arriving through onRequestPermissionsResult. The generated activity posts a
// lifecycle event nothing listens for — its own log says "No listeners for
// event: onPermissionResult" — and then switches on request codes 1001 and
// 1002. The plugin's code is 10001, so the answer was dropped.
//
// Measured on a Galaxy A51: the prompt was accepted, the system recorded
// granted=true, and mobile_notification_grant still read granted NULL.
//
// A grant recovered by accident, because RequestPermission short-circuits when
// the permission is already held and dispatches the event from that branch. A
// refusal had no such branch: it was never recorded, and the reader was asked
// again on every page load until Android stopped showing the dialog at all.
// The recovery existed only on the path that did not need it.

const NOTIFICATION_CALLBACK_ANCHOR = '        // Post lifecycle event for each permission result';

function notificationAnswerActivity(bool $withAnchor = true): string
{
    $callback = $withAnchor ? NOTIFICATION_CALLBACK_ANCHOR : '        // this site was rewritten upstream';

    return "package com.nativephp.mobile.ui\n\n"
        ."class MainActivity : FragmentActivity() {\n"
        ."    override fun onRequestPermissionsResult(\n"
        ."        requestCode: Int,\n"
        ."        permissions: Array<out String>,\n"
        ."        grantResults: IntArray\n"
        ."    ) {\n"
        ."        super.onRequestPermissionsResult(requestCode, permissions, grantResults)\n\n"
        .$callback."\n"
        ."        permissions.forEachIndexed { index, permission -> }\n\n"
        ."        when (requestCode) {\n"
        ."            1001 -> {}\n"
        ."            1002 -> {}\n"
        ."        }\n"
        ."    }\n}\n";
}

function notificationAnswerScaffold(bool $withAnchor = true): string
{
    $root = sys_get_temp_dir().'/beatrax-notif-answer-'.bin2hex(random_bytes(6));
    $full = $root.'/nativephp/'.NOTIFICATION_ACTIVITY_RELATIVE;

    mkdir(dirname($full), 0700, true);
    file_put_contents($full, notificationAnswerActivity($withAnchor));

    return $root;
}

const NOTIFICATION_ACTIVITY_RELATIVE = 'android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

function patchedNotificationActivity(string $root): string
{
    return (string) file_get_contents($root.'/nativephp/'.NOTIFICATION_ACTIVITY_RELATIVE);
}

/** @return array{status: int, stdout: string, stderr: string} */
function runNotificationAnswerPatch(string $root): array
{
    $script = dirname(__DIR__, 4).'/scripts/nativephp_android_notification_permission_truth.php';

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

// The plugin's own event, keyed on the plugin's own constant rather than a
// literal, so a code the plugin moves cannot leave this matching nothing.
it('carries the dialog answer back as the event the plugin documents', function (): void {
    $root = notificationAnswerScaffold();

    expect(runNotificationAnswerPatch($root)['status'])->toBe(0);

    expect(patchedNotificationActivity($root))
        ->toContain('LocalNotificationFunctions.RequestPermission.PERMISSION_REQUEST_CODE')
        ->toContain('NativePHP\\\\LocalNotifications\\\\Events\\\\PermissionGranted')
        ->toContain('NativeActionCoordinator.dispatchEvent');
});

// A refusal is the whole point: a grant already recovered on the next ask, and
// only a refusal was lost for the life of the install.
it('reports a refusal as an answer rather than as no answer', function (): void {
    $root = notificationAnswerScaffold();
    runNotificationAnswerPatch($root);

    expect(patchedNotificationActivity($root))
        ->toContain('grantResults.getOrNull(index) == PackageManager.PERMISSION_GRANTED')
        ->toContain('.put("granted", granted)');
});

// It reads the result for POST_NOTIFICATIONS by name. The callback is shared
// with every other permission the shell asks for, and answering one of those
// as though it were this one is worse than dropping it.
it('reads the answer for the permission it asked about', function (): void {
    $root = notificationAnswerScaffold();
    runNotificationAnswerPatch($root);

    expect(patchedNotificationActivity($root))
        ->toContain('permissions.indexOf(android.Manifest.permission.POST_NOTIFICATIONS)');
});

it('patches once however often the build regenerates the project', function (): void {
    $root = notificationAnswerScaffold();
    runNotificationAnswerPatch($root);
    $second = runNotificationAnswerPatch($root);

    expect($second['status'])->toBe(0)
        ->and($second['stdout'])->toContain('already patched');
});

// An unpatched shell degrades to exactly the defect this exists to fix, so a
// moved anchor has to stop the build rather than skip quietly.
it('fails loudly when the callback it rewrites has moved', function (): void {
    $result = runNotificationAnswerPatch(notificationAnswerScaffold(withAnchor: false));

    expect($result['status'])->not->toBe(0)
        ->and($result['stderr'])->toContain('expected 1');
});

// The fixture is written by hand, so on its own it only proves the patch
// rewrites text this file invented. This ties the anchor to the activity the
// build actually generates.
it('anchors on text the shipped activity really carries', function (): void {
    $relative = 'vendor/nativephp/mobile/resources/androidstudio/'.NOTIFICATION_ACTIVITY_RELATIVE;
    $upstream = null;

    foreach ([base_path($relative), base_path('mobile-app/'.$relative)] as $candidate) {
        if (is_file($candidate)) {
            $upstream = (string) file_get_contents($candidate);
        }
    }

    if ($upstream === null) {
        test()->markTestSkipped(
            'vendor/nativephp/mobile/resources/androidstudio/'.NOTIFICATION_ACTIVITY_RELATIVE.' is not present '
            .'under either Composer root, so this anchor is answered in no job at all. It reported a pass '
            .'instead of saying so; .github/test-skip-budget.json records where it stands.',
        );
    }

    expect(substr_count($upstream, NOTIFICATION_CALLBACK_ANCHOR))->toBe(1);
});
