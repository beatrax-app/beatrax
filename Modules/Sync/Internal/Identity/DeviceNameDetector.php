<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

/**
 * Produce a neutral default device name (D-09).
 *
 * WR-06: this default is stored in device_registry.name and EXCHANGED with /
 * displayed to paired peers. For a privacy-first, local-only product the auto
 * name must not leak the OS hostname (often the user's real name, e.g.
 * "Wessels-MacBook-Pro") to any device the user pairs with before they have a
 * chance to rename it. We therefore default to a neutral OS-family label
 * ("This device (Mac)") and never read php_uname('n').
 *
 * The default is only ever a default — the device list lets the user rename it
 * inline (D-09) to anything they choose, including a hostname-derived name, as
 * an explicit opt-in.
 */
final class DeviceNameDetector
{
    public function detect(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Darwin' => 'Mac',
            'Windows' => 'PC',
            'Linux' => 'Linux',
            default => '',
        };

        return $os !== '' ? "This device ({$os})" : 'This device';
    }
}
