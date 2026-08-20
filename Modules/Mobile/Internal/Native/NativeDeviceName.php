<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Native;

use Modules\Core\Public\Contracts\DeviceNameSource;
use Native\Mobile\Facades\Device;
use Throwable;

// This phone's own name, as the OS reports it. The generic detector keys off
// PHP_OS_FAMILY, which on Android is "Linux" — so a paired desktop labelled
// the phone "This device (Linux)" and no rename could reach it, because the
// name travels in the pairing frame.
final readonly class NativeDeviceName implements DeviceNameSource
{
    public function name(): ?string
    {
        try {
            $info = Device::getInfo();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($info) || $info === '') {
            return null;
        }

        $decoded = json_decode($info, true);

        return is_array($decoded) ? $this->pick($decoded) : null;
    }

    // The user-set device name first ("Wessel's S24"), then the hardware
    // identity ("samsung SM-S928B"). Both are names the phone already
    // broadcasts to nearby devices, so neither leaks anything new.
    /**
     * @param  array<mixed>  $info
     */
    private function pick(array $info): ?string
    {
        $name = $info['name'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $manufacturer = is_string($info['manufacturer'] ?? null) ? $info['manufacturer'] : '';
        $model = is_string($info['model'] ?? null) ? $info['model'] : '';
        $combined = trim($manufacturer.' '.$model);

        return $combined === '' ? null : $combined;
    }
}
