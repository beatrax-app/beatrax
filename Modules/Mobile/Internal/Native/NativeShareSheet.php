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
    // the coupling this buys: the guard below pins that the facade stays
    // unused here, so a vendor that renames a key is a test failure and not a
    // silent no-op.
    public function file(string $shareTitle, string $shareMessage, string $path): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $encoded = json_encode([
            'title' => $shareTitle,
            'message' => $shareMessage,
            'filePath' => $path,
        ]);

        if ($encoded === false) {
            return false;
        }

        try {
            return self::answersSuccess(nativephp_call(self::SHARE_FUNCTION, $encoded));
        } catch (Throwable) {
            return false;
        }
    }

    // The shell spells success two ways across the functions it answers —
    // `{"status":"success"}` from the media picker, `{"success":true}` from
    // SecureStorage and File — so both are read here rather than guessing
    // which one a share replies with.

    // Everything else is a failure, including an answer this cannot parse.
    // The cost of reading a real share as failed is that the reader shares
    // again; the cost of the reverse is being told the recovery codes for an
    // account are saved in a file that was never written anywhere reachable.
    private static function answersSuccess(mixed $answer): bool
    {
        if (! is_string($answer) || $answer === '') {
            return false;
        }

        $decoded = json_decode($answer, true);

        if (! is_array($decoded)) {
            return false;
        }

        return ($decoded['status'] ?? null) === 'success'
            || ($decoded['success'] ?? null) === true;
    }
}
