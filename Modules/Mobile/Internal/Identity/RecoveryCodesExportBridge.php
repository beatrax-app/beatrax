<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Core\Public\Services\UserDataPathService;
use Native\Mobile\Facades\Share;
use Throwable;

// Hands the recovery codes to the OS as a file the user can keep.
//
// The screen used to do this with a blob URL and an <a download>. The Android
// WebView drops that click silently — no file, no error, no console entry —
// while the screen printed "Saved to your downloads." about codes that are
// never shown again. Going through the OS share sheet is the route that
// exists on a phone, and it reports whether it happened.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
class RecoveryCodesExportBridge
{
    private const string EXPORT_SUB = 'exports';

    // Safe to call unconditionally — false on web, CI and desktop without
    // ever referencing the native facade.
    public function isAvailable(): bool
    {
        if (! class_exists(Share::class)) {
            return false;
        }

        return UserDataPathService::isMobileRuntime();
    }

    // Returns whether the file was written and handed to the OS. A false
    // answer must reach the user: these codes are the only way back into an
    // account, so a caller that reports success on a failed export is worse
    // than one that offers no export at all.
    public function export(string $filename, string $contents, string $shareTitle, string $shareMessage): bool
    {
        if (! $this->isAvailable() || ! class_exists(Share::class)) {
            return false;
        }

        $path = $this->write($filename, $contents);

        if ($path === null) {
            return false;
        }

        try {
            Share::file($shareTitle, $shareMessage, $path);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    // Written inside the app's own data directory at 0600, never to shared
    // storage: the share sheet reads it on the user's behalf, so nothing else
    // needs to be able to.
    private function write(string $filename, string $contents): ?string
    {
        try {
            $path = UserDataPathService::appPath(self::EXPORT_SUB.DIRECTORY_SEPARATOR.$filename);
            $directory = dirname($path);

            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return null;
            }

            if (@file_put_contents($path, $contents) === false) {
                return null;
            }

            @chmod($path, 0600);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
