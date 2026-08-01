<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Core\Public\Support\Lang;

final class PlatformDetector
{
    // Known limitation, accepted: iPadOS 13+ Safari in desktop mode sends a
    // "Macintosh" user-agent, so those iPads get the macOS "Use Touch ID"
    // label instead of "Use Face ID". Cosmetic only -- the WebAuthn flow
    // itself is unaffected.
    public function detectLabel(string $userAgent): string
    {
        return match (true) {
            $this->isIos($userAgent) => Lang::get('auth::biometric.face_id'),
            $this->isMacOs($userAgent) => Lang::get('auth::biometric.touch_id'),
            $this->isWindows($userAgent) => Lang::get('auth::biometric.windows_hello'),
            default => Lang::get('auth::biometric.fingerprint'),
        };
    }

    /**
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
