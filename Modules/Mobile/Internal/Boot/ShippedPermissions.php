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

    // The platform's plain `signature` level, and only it. The field is a
    // bitmask and every modifier on top of it -- privileged, preinstalled --
    // widens who may hold the permission, so a value that is not exactly this
    // is a different grant and has to be looked at rather than assumed benign.
    private const int SIGNATURE_ONLY = 0x2;

    // What the platform assumes when the attribute is absent: any app that
    // asks for it holds it, without the user being told.
    private const int PROTECTION_NORMAL = 0x0;

    // Every refusal the manifest earns, empty when it earns none.
    /**
     * @param  array{package: string, requested: list<string>, declared: array<string, int>}  $manifest
     * @return list<string>
     */
    public function refusals(array $manifest): array
    {
        $refusals = [];

        foreach ($manifest['requested'] as $permission) {
            $refusal = $this->refusalFor($permission, $manifest);

            if ($refusal !== null) {
                $refusals[] = $refusal;
            }
        }

        foreach ($manifest['declared'] as $permission => $level) {
            if ($level !== self::SIGNATURE_ONLY) {
                $refusals[] = sprintf(
                    'declared by the artifact at a level other apps can hold: %s (protectionLevel 0x%x)',
                    $permission,
                    $level,
                );
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

    // A permission the artifact declares for itself, in its own package, at
    // signature level is a lock rather than a key: no app signed by another
    // key can hold it, so it grants nothing outward and has no consumer in
    // this product to name. androidx.core mints exactly one of these.
    /**
     * @param  array{package: string, requested: list<string>, declared: array<string, int>}  $manifest
     */
    private function refusalFor(string $permission, array $manifest): ?string
    {
        if (in_array($permission, self::REFUSED, true)) {
            return 'back in the merged manifest, and a store restricts it: '.$permission;
        }

        if ($this->isAccountedFor($permission, $manifest)) {
            return null;
        }

        return 'requested by the artifact and named nowhere in this product: '.$permission;
    }

    // The two ways a request is answered for: a consumer this product names,
    // or a lock the artefact puts on its own components, which names none
    // because it grants nothing outward.
    /**
     * @param  array{package: string, requested: list<string>, declared: array<string, int>}  $manifest
     */
    private function isAccountedFor(string $permission, array $manifest): bool
    {
        return array_key_exists($permission, self::ALLOWED)
            || $this->isSelfProtection($permission, $manifest);
    }

    /**
     * @param  array{package: string, requested: list<string>, declared: array<string, int>}  $manifest
     */
    private function isSelfProtection(string $permission, array $manifest): bool
    {
        return $manifest['package'] !== ''
            && str_starts_with($permission, $manifest['package'].'.')
            && ($manifest['declared'][$permission] ?? null) === self::SIGNATURE_ONLY;
    }

    // `aapt2 dump xmltree` rather than `dump permissions`, because only this
    // one carries the protectionLevel -- and the level is the whole difference
    // between a permission the app asks of the world and one it locks itself
    // with. Attributes belong to the most recent element line.
    /**
     * @return array{package: string, requested: list<string>, declared: array<string, int>}
     */
    public function readManifest(string $xmlTree): array
    {
        $package = '';
        $requested = [];
        $declared = [];
        $element = '';
        $name = '';

        foreach (explode("\n", $xmlTree) as $line) {
            $opened = PatternScan::first('/^\s*E: ([A-Za-z0-9_.-]+) \(line=/', $line);

            if (isset($opened[1])) {
                $element = $opened[1];
                $name = '';

                continue;
            }

            if ($element === 'manifest') {
                $found = PatternScan::first('/^\s*A: package="([^"]*)"/', $line);
                $package = $found[1] ?? $package;

                continue;
            }

            if ($element !== 'uses-permission' && $element !== 'permission') {
                continue;
            }

            $name = $this->readPermissionAttribute($line, $element, $name, $requested, $declared);
        }

        return ['package' => $package, 'requested' => array_values(array_unique($requested)), 'declared' => $declared];
    }

    // Carries the two collections by reference because the name and the level
    // arrive on separate lines: the element's name has to survive until the
    // level that belongs with it is read. Returns the name still in hand.
    /**
     * @param  list<string>  $requested
     * @param  array<string, int>  $declared
     */
    private function readPermissionAttribute(
        string $line,
        string $element,
        string $name,
        array &$requested,
        array &$declared,
    ): string {
        $found = PatternScan::first('/^\s*A: (?:\S*:)?name\(0x[0-9a-f]+\)="([^"]*)"/', $line);

        if (isset($found[1])) {
            if ($element === 'uses-permission') {
                $requested[] = $found[1];
            }

            // Seeded at the name, not at the level: a declaration that omits
            // protectionLevel is `normal` by default, which is the widest of
            // them, and a parser that only records levels it was shown reads
            // that silence as though the permission were never declared.
            if ($element === 'permission') {
                $declared[$found[1]] = self::PROTECTION_NORMAL;
            }

            return $found[1];
        }

        $level = PatternScan::first('/^\s*A: (?:\S*:)?protectionLevel\(0x[0-9a-f]+\)=(0x[0-9a-f]+|\d+)/', $line);

        if (isset($level[1]) && $element === 'permission' && $name !== '') {
            $declared[$name] = (int) (str_starts_with($level[1], '0x')
                ? hexdec(substr($level[1], 2))
                : $level[1]);
        }

        return $name;
    }
}
