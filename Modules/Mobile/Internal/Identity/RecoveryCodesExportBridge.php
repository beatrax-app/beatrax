<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Core\Public\Services\UserDataPathService;
use Native\Mobile\Facades\Share;
use Throwable;

// Hands the recovery codes to the OS as a file the user can keep. A blob URL
// and an <a download> is dropped silently by the Android webview — no file, no
// error, no console entry — while the screen claimed it had saved codes that
// are never shown again. The share sheet is the route a phone actually has.
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
        if (! $this->isAvailable()) {
            return false;
        }

        $path = $this->write($filename, $contents);

        return $path !== null && $this->handToShareSheet($shareTitle, $shareMessage, $path);
    }

    // The in-method class_exists() re-check is load-bearing: PHPStan narrowing
    // is scope-local, so it has to sit in the same method as the Share:: call.
    private function handToShareSheet(string $shareTitle, string $shareMessage, string $path): bool
    {
        if (! class_exists(Share::class)) {
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

            if (! $this->ensureDirectory(dirname($path)) || @file_put_contents($path, $contents) === false) {
                return null;
            }

            @chmod($path, 0600);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    // The second is_dir() is not the first one repeated: mkdir() fails with
    // EEXIST when a racing writer got there in between, and the directory it
    // lost the race for is exactly the one this needed.
    private function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0700, true) || is_dir($directory);
    }
}
