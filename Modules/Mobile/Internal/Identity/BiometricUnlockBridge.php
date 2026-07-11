<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Native\Mobile\Facades\Biometrics;

/**
 * Wraps the `nativephp/mobile-biometrics` prompt behind a plain boolean
 * interface. Mirrors `Modules\Desktop\Internal\Native\NativeBiometricUnlock`'s
 * bool-only, single-caller, no-cross-module-import discipline verbatim
 * (15-PATTERNS.md §BiometricUnlockBridge).
 *
 * This class is the ONLY place in the codebase that calls
 * `Native\Mobile\Facades\Biometrics`. Its single responsibility is to answer
 * two questions:
 *
 *   - isAvailable(): can the mobile runtime present a biometric prompt right now?
 *   - prompt(): did the user successfully authenticate?
 *
 * The crypto/trust decision (whether a `true` result is allowed to release
 * the LOCK-04 at-rest key) lives entirely in the Auth module's session-lock
 * services. This class never imports anything from that module — it returns
 * only a bool. `MobileLockScreen` calls `prompt()` and, on `true`, delegates
 * to the existing unlock-read chain (T-15-14: a spoofed/aborted biometric
 * must never release the key — a false/aborted bool never reaches that
 * chain; T-15-16: this bridge never reaches into the Auth module's
 * internals).
 *
 * Guard for non-mobile / CI / web environments: `isAvailable()` returns
 * false unless BOTH:
 *   - `Native\Mobile\Facades\Biometrics` is resolvable. The
 *     `nativephp/mobile-biometrics` plugin is installed only under the
 *     `mobile-app/` sibling Composer root's vendor tree (Plan 03) — it is
 *     NOT present in the repo-root toolchain the host Pest/PHPStan run
 *     targets, so this guard is load-bearing, not defensive boilerplate
 *     (mirrors `Modules\Mobile\Internal\Sync\NetworkPolicyResolver`'s own
 *     `class_exists(Network::class)` guard for the sibling `mobile-network`
 *     plugin).
 *   - the on-device mobile runtime signal is present: `getenv('NATIVEPHP_PLATFORM')`
 *     (`ios`/`android`) per 15-SPIKE-FINDINGS.md Spike B — the one reliable
 *     mobile-runtime signal this phase established. This is a DIFFERENT
 *     signal than the desktop bundle's own runtime-detection config flag,
 *     which does not apply on mobile.
 *
 * phpstan.neon carve-out: `Native\Mobile\Facades\Biometrics` is already
 * allow-listed for `Modules/Mobile/Internal/Identity/*.php` (Plan 01).
 *
 * NOTE: intentionally NOT `final` — mirrors `AppLockKeyService`'s own
 * precedent (Phase 12 device-identity tests substitute a release()-returns-
 * null subclass because there is no other unlock seam to exercise). The
 * real `Native\Mobile\Facades\Biometrics` class is unreachable from the
 * repo-root test toolchain (only `mobile-app/vendor` carries it), so
 * `MobileBiometricUnlockTest` swaps in a subclass via
 * `$this->app->bind(BiometricUnlockBridge::class, fn () => new class
 * extends BiometricUnlockBridge { ... })` to simulate success/abort — the
 * real native sensor is exercised only by a manual on-device UAT pass
 * (15-RESEARCH.md V5 Input Validation note).
 */
class BiometricUnlockBridge
{
    /**
     * Returns true only when the mobile-biometrics facade is resolvable AND
     * the on-device mobile runtime signal is present.
     *
     * Safe to call unconditionally — returns false on web / CI / desktop
     * without ever referencing the native facade.
     */
    public function isAvailable(): bool
    {
        if (! class_exists(Biometrics::class)) {
            return false;
        }

        return getenv('NATIVEPHP_PLATFORM') !== false;
    }

    /**
     * Present the platform biometric prompt (Face ID / Touch ID / Android
     * biometric) and return the result.
     *
     * Returns true if the user authenticated successfully; false if they
     * cancelled, failed, or if biometric unlock is not available right now.
     *
     * The caller is responsible for treating a `true` result as nothing
     * more than an authorization signal — this class never derives, wraps,
     * or unwraps any key material itself (T-15-14: a false/aborted result
     * must never release the key).
     *
     * @param  string  $reason  Human-readable explanation for the prompt.
     *                          The underlying plugin API (15-RESEARCH.md
     *                          Code Examples) does not document a reason
     *                          parameter; accepted here only to mirror the
     *                          desktop bridge's signature shape.
     */
    public function prompt(string $reason = 'Unlock beatrax'): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        // Re-checked (not just isAvailable()'s own guard) so PHPStan's
        // class_exists() narrowing applies within THIS method's scope —
        // mirrors NetworkPolicyResolver::isCurrentConnectionExpensive()'s
        // identical shape (its own class_exists() guard sits in the same
        // method as the facade call it guards, for the same reason).
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
