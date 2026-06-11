<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

/**
 * Detects the platform from a User-Agent string and returns the appropriate
 * biometric label and platform key.
 *
 * Label contract (UI-SPEC §1 biometric button):
 *   - macOS desktop   → "Use Touch ID"
 *   - iOS             → "Use Face ID"
 *   - Windows         → "Use Windows Hello"
 *   - Android/generic → "Use fingerprint"
 *
 * Platform key contract:
 *   - 'nativephp_macos' — only when running inside the NativePHP macOS bundle
 *     on macOS (caller passes $isNativeMacos=true from System::canPromptTouchID()).
 *   - 'webauthn'        — all other platforms (browser, PWA, Windows, iOS,
 *     Android, non-native macOS browser).
 */
final class PlatformDetector
{
    /**
     * Return the platform-aware biometric button label.
     *
     * @param  string  $userAgent  The HTTP User-Agent header value.
     */
    public function detectLabel(string $userAgent): string
    {
        if ($this->isIos($userAgent)) {
            return 'Use Face ID';
        }

        if ($this->isMacOs($userAgent)) {
            return 'Use Touch ID';
        }

        if ($this->isWindows($userAgent)) {
            return 'Use Windows Hello';
        }

        // Android / generic fallback.
        return 'Use fingerprint';
    }

    /**
     * Return the platform key used to categorise the enrolled credential.
     *
     * Only returns 'nativephp_macos' when the caller explicitly confirms that
     * the NativePHP macOS bundle is active (via System::canPromptTouchID()).
     * All other cases — including macOS in a browser — return 'webauthn'.
     *
     * @param  string  $userAgent  The HTTP User-Agent header value.
     * @param  bool  $isNativeMacos  Pass true when System::canPromptTouchID() returned true.
     */
    public function platformKey(string $userAgent, bool $isNativeMacos = false): string
    {
        if ($isNativeMacos && $this->isMacOs($userAgent)) {
            return 'nativephp_macos';
        }

        return 'webauthn';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function isIos(string $userAgent): bool
    {
        // Match iPhone, iPad, or iPod — but not macOS (which does not contain
        // "iPhone" but shares "Mac" with macOS; the OS-specific string on iOS
        // devices is "iPhone OS" or "CPU OS" on iPad).
        return preg_match('/\b(iPhone|iPad|iPod)\b/i', $userAgent) === 1;
    }

    private function isMacOs(string $userAgent): bool
    {
        // "Macintosh" is present in macOS desktop user-agents.
        // iOS devices do NOT include "Macintosh" in their UA.
        return preg_match('/\bMacintosh\b/', $userAgent) === 1;
    }

    private function isWindows(string $userAgent): bool
    {
        return preg_match('/\bWindows\b/', $userAgent) === 1;
    }
}
