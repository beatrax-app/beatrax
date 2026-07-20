<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class DeviceNameDetector
{
    // This default is stored in device_registry.name and EXCHANGED with /
    // displayed to paired peers, so it must not leak the OS hostname (often
    // the user's real name) to any device paired before it's renamed —
    // hence a neutral OS-family label and never php_uname('n').
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
