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
    private const string SHARE_FUNCTION = 'Share.File';

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
        return function_exists('nativephp_can') && nativephp_can(self::SHARE_FUNCTION);
    }

    // Deliberately not Share::file(): the facade is typed void, so the answer
    // the shell gives is dropped inside it and a sheet that never opened — a
    // cancel, a provider that refused, a file it could not read — came back
    // indistinguishable from a share that happened.

    // The payload mirrors Native\Mobile\Share::file() key for key, which is
    // the coupling this buys, and a guard pins the facade as unused across the
    // tree so a vendor that renames a key fails a test rather than going
    // quietly nowhere.
    public function file(string $shareTitle, string $shareMessage, string $path): bool
    {
        $payload = self::payloadFor($shareTitle, $shareMessage, $path);

        return $payload !== null && $this->answerTo($payload);
    }

    // Null where the payload will not encode, which is the same answer as a
    // refusal to the caller: nothing was handed anywhere.
    private static function payloadFor(string $shareTitle, string $shareMessage, string $path): ?string
    {
        $encoded = json_encode([
            'title' => $shareTitle,
            'message' => $shareMessage,
            'filePath' => $path,
        ]);

        return $encoded === false ? null : $encoded;
    }

    private function answerTo(string $payload): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        try {
            return BridgeAnswer::saysItSucceeded(nativephp_call(self::SHARE_FUNCTION, $payload));
        } catch (Throwable) {
            return false;
        }
    }
}
