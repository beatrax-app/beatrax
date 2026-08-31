<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Modules\Core\Public\Contracts\DeviceNameSource;

final readonly class DeviceNameDetector
{
    // Unbound on platforms with nothing better to offer, in which case the
    // container passes the default and the OS-family fallback stands. The
    // family is a defaulted parameter rather than a constant read inline, so
    // the fallback can be exercised for a platform the test is not running on.
    public function __construct(
        private ?DeviceNameSource $source = null,
        private string $platformFamily = PHP_OS_FAMILY,
    ) {}

    // Stored in device_registry.name and EXCHANGED with peers, so it must
    // never leak the OS hostname (often the user's real name) — hence a
    // neutral OS-family label, never php_uname('n').

    // Bare "Mac", not "This device (Mac)": the name travels to the peer, so
    // the desktop read as the handset you hold. The badge already marks self.
    public function detect(): string
    {
        $platformName = $this->source?->name();

        if ($platformName !== null && $platformName !== '') {
            return $platformName;
        }

        return match ($this->platformFamily) {
            'Darwin' => 'Mac',
            'Windows' => 'PC',
            'Linux' => 'Linux',
            default => 'This device',
        };
    }
}
