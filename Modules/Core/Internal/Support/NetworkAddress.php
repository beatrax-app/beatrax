<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

final readonly class NetworkAddress
{
    // A dual-stack listener publishes one interface as either `10.0.0.4` or
    // `::ffff:10.0.0.4`, so both collapse onto a single key before anything
    // compares them. Without that, a declared interface would match only the
    // spelling the operator happened to write.
    public static function comparable(string $address): ?string
    {
        $binary = @inet_pton($address);
        if ($binary === false) {
            return null;
        }

        $v4MappedPrefix = str_repeat("\x00", 10)."\xff\xff";

        return strlen($binary) === 16 && str_starts_with($binary, $v4MappedPrefix)
            ? substr($binary, 12)
            : $binary;
    }

    public static function isLoopback(string $address): bool
    {
        $key = self::comparable($address);

        return $key !== null && self::keyIsLoopback($key);
    }

    // All-zero is `0.0.0.0` or `::`, the wildcard bind. It names no interface,
    // so honouring it in a list of served interfaces would spell "everything" —
    // the one thing this configuration must not be able to say.
    public static function isWildcard(string $address): bool
    {
        $key = self::comparable($address);

        return $key !== null && trim($key, "\x00") === '';
    }

    // 127.0.0.0/8 is the whole IPv4 loopback range, so the first byte decides
    // it. `::1` is fifteen NUL bytes and a 0x01, compared in binary rather than
    // against one of the many text spellings of it.
    private static function keyIsLoopback(string $key): bool
    {
        return strlen($key) === 4
            ? $key[0] === "\x7f"
            : $key === str_repeat("\x00", 15)."\x01";
    }
}
