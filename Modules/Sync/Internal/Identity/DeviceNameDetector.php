<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

/**
 * Auto-detect a sensible default device name (D-09).
 *
 * Reads the machine hostname, strips a trailing `.local`, humanises hyphens
 * into spaces, and appends a coarse OS-family tag ("Mac" / "PC" / "Linux") to
 * disambiguate two accounts on the same box. Falls back to "This device" when
 * the hostname is empty or unavailable.
 *
 * The detected name is only ever a default — the device list lets the user
 * rename it inline (the rename action ships in Plan 04).
 */
final class DeviceNameDetector
{
    public function detect(): string
    {
        $hostname = php_uname('n');

        // Strip a trailing ".local" (Bonjour/mDNS hostname suffix on macOS).
        $name = preg_replace('/\.local$/i', '', $hostname) ?? $hostname;

        // Humanise: hyphens → spaces ("Wessels-MacBook-Pro" → "Wessels MacBook Pro").
        $name = trim(str_replace('-', ' ', $name));

        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'Mac',
            'Windows' => 'PC',
            'Linux' => 'Linux',
            default => '',
        };

        if ($name === '') {
            return $os !== '' ? "This device ({$os})" : 'This device';
        }

        return $os !== '' ? "{$name} ({$os})" : $name;
    }
}
