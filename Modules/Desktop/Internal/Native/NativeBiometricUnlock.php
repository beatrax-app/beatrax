<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Native\Desktop\Facades\System;

/**
 * Wraps the NativePHP Touch ID (macOS) prompt behind a plain boolean interface.
 *
 * This class is the ONLY place in the codebase that calls
 * `System::canPromptTouchID()` and `System::promptTouchID()`. Its single
 * responsibility is to answer two questions:
 *
 *   - isAvailable(): can the OS present a Touch ID prompt right now?
 *   - prompt(): did the user successfully authenticate with Touch ID?
 *
 * The crypto release logic (unwrapping the per-device biometric wrap and
 * unlocking the session) lives entirely in the Auth module. This class
 * only returns a bool — it never imports `AppLockKeyWrap`, `AppLockKdf`,
 * or any `Modules\Auth\*` symbol. The macOS lock-screen biometric handler
 * calls `prompt()` and, on `true`, delegates the key-release to an Auth
 * module service. This keeps the T-05-25 (Touch ID bypass) mitigation
 * fully in Auth: the Desktop native layer cannot release any key.
 *
 * Guard for non-desktop / CI environments: `isAvailable()` returns false
 * unless the NativePHP bundle is running (`nativephp-internal.running`
 * config flag). On web / CI, `System::canPromptTouchID()` would throw or
 * return false anyway, but gating on the config flag avoids the cold HTTP
 * client path entirely in test runs.
 *
 * phpstan.neon carve-out: both `System` facade calls are added to the
 * `Native\Desktop\Facades\(Menu|Window|System|Notification|App)` allow-list
 * paths block in phpstan.neon (same entry as OsThemeProbe and
 * DispatchOsNotification).
 */
final class NativeBiometricUnlock
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Returns true only when the NativePHP bundle is running AND the OS
     * supports Touch ID (macOS only).
     *
     * Safe to call unconditionally — returns false on web / CI / Windows
     * without reaching the NativePHP HTTP client.
     */
    public function isAvailable(): bool
    {
        if ($this->config->get('nativephp-internal.running') !== true) {
            return false;
        }

        return System::canPromptTouchID();
    }

    /**
     * Present the macOS Touch ID prompt and return the result.
     *
     * Returns true if the user authenticated successfully; false if they
     * cancelled, failed, or if Touch ID is not available.
     *
     * The caller is responsible for releasing the data key only on a `true`
     * result (T-05-25: a false result must not release the key).
     *
     * @param  string  $reason  Human-readable explanation shown in the OS prompt.
     */
    public function prompt(string $reason = 'Unlock beatrax'): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        return System::promptTouchID($reason);
    }
}
