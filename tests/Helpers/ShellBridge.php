<?php

declare(strict_types=1);

namespace Tests\Helpers;

// The bridge answers a caller carrying the shell's secret and nothing else, so
// a test that posts a shell event has to carry it too. Kept here rather than
// spelled out per file: the header name is the package's, and a test writing
// its own copy would keep passing after the package renamed it.
final readonly class ShellBridge
{
    public const string HEADER = 'X-NativePHP-Secret';

    // Any value the config and the header agree on proves the same thing. A
    // fixed one keeps a failure readable; nothing here is a credential.
    private const string SECRET = 'shell-bridge-secret-for-tests';

    /**
     * @return array<string, string>
     */
    public static function arm(): array
    {
        config()->set('nativephp-internal.secret', self::SECRET);

        return [self::HEADER => self::SECRET];
    }
}
