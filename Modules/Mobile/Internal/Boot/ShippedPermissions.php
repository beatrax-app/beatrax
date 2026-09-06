<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Modules\Core\Public\Support\PatternScan;

// Every permission the shipped artifact may request, and the shipped code that
// makes the use it is granted for. Read against the MERGED manifest inside the
// built APK: the strip script edits the app manifest and runs before Gradle
// merges a dependency's own into it, and its own header says so.
final readonly class ShippedPermissions
{
    // Value is the class whose existence is the claim that the use is made. A
    // permission whose consumer has gone is a permission to drop, not a line
    // to keep because the store already accepted it once.
    public const array ALLOWED = [
        'android.permission.INTERNET' => 'Modules\Mobile\Internal\Sync\LanSyncClient',
        'android.permission.ACCESS_NETWORK_STATE' => 'Modules\Mobile\Internal\Sync\NetworkPolicyResolver',
        'android.permission.VIBRATE' => 'Modules\Mobile\Internal\Pairing\QrScanBridge',
        'android.permission.CAMERA' => 'Modules\Mobile\Internal\Pairing\QrScanBridge',
        'android.permission.USE_BIOMETRIC' => 'Modules\Mobile\Internal\Identity\BiometricKeyVault',
        'android.permission.POST_NOTIFICATIONS' => 'Modules\Mobile\Internal\Listeners\DispatchMobileNotification',
        'android.permission.WAKE_LOCK' => 'Modules\Core\Public\Scheduling\MobileBackgroundSchedule',
    ];

    // Named rather than merely absent from ALLOWED, because these are the ones
    // a store restricts and a dependency keeps contributing. An unknown
    // permission is a question; one of these is a known refusal returning.
    public const array REFUSED = [
        'android.permission.FLASHLIGHT',
        'android.permission.SCHEDULE_EXACT_ALARM',
        'android.permission.USE_EXACT_ALARM',
        'android.permission.RECEIVE_BOOT_COMPLETED',
        'android.permission.FOREGROUND_SERVICE',
        'android.permission.USE_FINGERPRINT',
    ];

    // Every refusal the requested set earns, empty when it earns none.
    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    public function refusals(array $requested): array
    {
        $refusals = [];

        foreach ($requested as $permission) {
            if (in_array($permission, self::REFUSED, true)) {
                $refusals[] = 'back in the merged manifest, and a store restricts it: '.$permission;

                continue;
            }

            if (! array_key_exists($permission, self::ALLOWED)) {
                $refusals[] = 'requested by the artifact and named nowhere in this product: '.$permission;
            }
        }

        foreach (self::ALLOWED as $permission => $consumer) {
            if (! class_exists($consumer)) {
                $refusals[] = 'allowed for a consumer that no longer exists: '.$permission.' -> '.$consumer;
            }
        }

        sort($refusals);

        return $refusals;
    }

    // aapt2 prints one `uses-permission: name='...'` line per entry, and other
    // lines beside them. Parsed here rather than in the shell so the shape the
    // command reads is the shape a case can hand it.
    /**
     * @return list<string>
     */
    public function requestedIn(string $aapt2Dump): array
    {
        $found = [];

        foreach (explode("\n", $aapt2Dump) as $line) {
            $matches = PatternScan::first("/name='([^']+)'/", trim($line));

            if (isset($matches[1])) {
                $found[] = $matches[1];
            }
        }

        return array_values(array_unique($found));
    }
}
