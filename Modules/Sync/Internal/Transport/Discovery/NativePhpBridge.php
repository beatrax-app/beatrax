<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

use Modules\Core\Public\Services\UserDataPathService;

// `nativephp_call` is a C function compiled into the shipped libphp.a, which
// hands the call to a Swift `@_cdecl` symbol in the app target. Off a device
// it does not exist — except under the Jump dev relay, whose stand-in answers
// "yes" to every capability and then dials a TCP bridge that is not there.
/**
 * @link ../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
final class NativePhpBridge implements NativeBridge
{
    public function supports(string $function): bool
    {
        if (! UserDataPathService::isMobileRuntime()) {
            return false;
        }

        return function_exists('nativephp_can')
            && function_exists('nativephp_call')
            && nativephp_can($function);
    }

    /**
     * @param  array<string, scalar>  $parameters
     * @return array<mixed>|null
     */
    public function call(string $function, array $parameters): ?array
    {
        if (! $this->supports($function)) {
            return null;
        }

        $encoded = json_encode($parameters);

        return $encoded === false ? null : self::decode(nativephp_call($function, $encoded));
    }

    // Typed mixed, not ?string: off the stub the answer comes from a C
    // extension, and a value that is not a string is exactly the case the
    // caller reads as "nothing readable" rather than a TypeError.
    /**
     * @return array<mixed>|null
     */
    private static function decode(mixed $answer): ?array
    {
        if (! is_string($answer) || $answer === '') {
            return null;
        }

        $decoded = json_decode($answer, true);

        return is_array($decoded) ? $decoded : null;
    }
}
