<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

/**
 * @link ../../../../../.docs/features/sync/relay-endpoint-authorization.md#a-drain-token-names-the-one-device-it-drains
 */
final class RelayDrainToken
{
    public const string VERSION = 'bdt1';

    private const string SEPARATOR = '.';

    // A fresh 32-byte secret per device id, never derived from anything the
    // install shares between its users: two local users must bind separately
    // at the relay, or the second one's leg dies with the first one's binding.
    public static function mint(string $deviceId): string
    {
        return self::prefixFor($deviceId).bin2hex(random_bytes(32));
    }

    // The relay's whole answer to "is this credential even about this device".
    // A relay-wide bearer carries no prefix, so it fails here rather than
    // trust-on-first-use-registering an id nobody has claimed yet; a token
    // minted for another device carries that one's prefix, and fails too.
    public static function namesDevice(string $token, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        $prefix = self::prefixFor($deviceId);

        // The prefix is fixed-length and its digest differs per device id, so
        // no token minted for one device can carry another's.
        return str_starts_with($token, $prefix) && strlen($token) > strlen($prefix);
    }

    // The device tag is a digest rather than the id itself, so the token is not
    // a second place the id is written in the clear. It tells the relay nothing
    // new either way: the relay is handed the device id in the very request
    // this token authorizes.
    private static function prefixFor(string $deviceId): string
    {
        return self::VERSION.self::SEPARATOR.hash('sha256', $deviceId).self::SEPARATOR;
    }
}
