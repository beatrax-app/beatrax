<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Core\Public\Support\Lang;

final class PlatformDetector
{
    // Accepted: iPadOS 13+ Safari in desktop mode sends a "Macintosh" agent,
    // so those iPads read Touch ID. Cosmetic; WebAuthn is unaffected.
    public function detectLabel(string $userAgent): string
    {
        return match (true) {
            $this->isIos($userAgent) => Lang::get('auth::biometric.face_id'),
            $this->isMacOs($userAgent) => Lang::get('auth::biometric.touch_id'),
            $this->isWindows($userAgent) => Lang::get('auth::biometric.windows_hello'),
            default => Lang::get('auth::biometric.fingerprint'),
        };
    }

    private function isIos(string $userAgent): bool
    {
        return preg_match('/\b(iPhone|iPad|iPod)\b/i', $userAgent) === 1;
    }

    private function isMacOs(string $userAgent): bool
    {
        return preg_match('/\bMacintosh\b/', $userAgent) === 1;
    }

    private function isWindows(string $userAgent): bool
    {
        return preg_match('/\bWindows\b/', $userAgent) === 1;
    }
}
