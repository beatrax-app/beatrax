<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Native\Mobile\Facades\Share;
use Throwable;

// The one place the OS share sheet is named. The seam callers reach it through
// is Public, and six modules depend on that seam — but a vendor facade may only
// be named from the module's own Internal side, so the facade stops here and
// everything above it deals in booleans.
final readonly class NativeShareSheet
{
    // False on web, CI and desktop: nativephp/mobile installs only into the
    // mobile-app root, so the facade is simply absent from a desktop vendor
    // tree and a caller must be able to ask without triggering an autoload.
    public function isInstalled(): bool
    {
        return class_exists(Share::class);
    }

    // Whether this shell registers the function behind Share::file() at all.
    // A build without it answers false rather than throwing, which is what
    // lets the caller tell the reader their file was not saved.
    public function canShareFiles(): bool
    {
        return function_exists('nativephp_can') && nativephp_can('Share.File');
    }

    // The in-method class_exists() re-check is load-bearing: PHPStan narrowing
    // is scope-local, so it has to sit in the same method as the Share:: call.
    public function file(string $shareTitle, string $shareMessage, string $path): bool
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
}
