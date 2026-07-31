<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Native\Mobile\Facades\Biometrics;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
class BiometricUnlockBridge
{
    // Safe to call unconditionally - returns false on web/CI/desktop
    // without ever referencing the native facade.
    public function isAvailable(): bool
    {
        if (! class_exists(Biometrics::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    // Returns true if the user authenticated successfully; false if they
    // cancelled, failed, or biometric unlock is not available. The caller
    // must treat a true result as nothing more than an authorization
    // signal - this class never derives, wraps, or unwraps key material.
    public function prompt(): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        // Re-checked (not just isAvailable()'s own guard) so PHPStan's
        // class_exists() narrowing applies within THIS method's scope -
        // the guard must sit in the same method as the facade call it
        // guards.
        if (! class_exists(Biometrics::class)) {
            return false;
        }

        // The mobile-biometrics plugin is unreachable from this toolchain
        // (see the class docblock), so its return type cannot be resolved
        // statically here; narrow the resulting `mixed` explicitly rather
        // than trusting an assumed bool return.
        $result = Biometrics::prompt();

        return $result === true;
    }
}
