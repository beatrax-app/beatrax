<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

// What nativephp_call() hands back, in the two shapes the shell's own router
// builds. A refusal is always {"status":"error","code":...,"message":...} —
// BridgeRouter.swift and BridgeRouter.kt agree on it, and it is what an
// unregistered function name answers with.

// A success has no envelope at all: BridgeResponse.success returns the
// function's own data unwrapped, so there is no single key that means yes.
// Reading one of these as the other is how a call the phone refused reaches a
// reader as work that was done.
final readonly class BridgeAnswer
{
    // Why the shell refused, or null when this answer is not a refusal. An
    // answer nothing can read is not counted here: it is unknown rather than
    // refused, and the two deserve different sentences.
    public static function refusal(mixed $answer): ?string
    {
        $decoded = self::decode($answer);

        if ($decoded === null) {
            return null;
        }

        if (($decoded['success'] ?? null) === false) {
            return self::describe($decoded, 'the shell answered success: false');
        }

        return ($decoded['status'] ?? null) === 'error'
            ? self::describe($decoded, 'the shell answered status: error')
            : null;
    }

    // True only where the answer says so, in either spelling the shell uses:
    // {"success":true} from Share.File, File and SecureStorage, and
    // {"status":"success"} from the media picker. Everything else, an answer
    // that will not parse included, is not a yes.
    public static function saysItSucceeded(mixed $answer): bool
    {
        $decoded = self::decode($answer);

        if ($decoded === null) {
            return false;
        }

        return ($decoded['status'] ?? null) === 'success'
            || ($decoded['success'] ?? null) === true;
    }

    /** @return array<mixed>|null */
    private static function decode(mixed $answer): ?array
    {
        if (! is_string($answer) || $answer === '') {
            return null;
        }

        $decoded = json_decode($answer, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<mixed> $decoded */
    private static function describe(array $decoded, string $fallback): string
    {
        $code = $decoded['code'] ?? null;
        $message = $decoded['message'] ?? null;

        $parts = array_values(array_filter(
            [
                is_string($code) && $code !== '' ? $code : null,
                is_string($message) && $message !== '' ? $message : null,
            ],
            static fn (?string $part): bool => $part !== null,
        ));

        return $parts === [] ? $fallback : implode(': ', $parts);
    }
}
