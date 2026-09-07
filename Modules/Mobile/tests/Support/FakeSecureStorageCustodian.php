<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

class FakeSecureStorageCustodian extends SecureStorageKeyCustodian
{
    /** @var array<string, string> */
    public array $slots = [];

    public bool $setSucceeds = true;

    public bool $deleteSucceeds = true;

    protected function runtimeAvailable(): bool
    {
        return true;
    }

    protected function nativeSet(string $key, string $value): bool
    {
        if (! $this->setSucceeds) {
            return false;
        }
        $this->slots[$key] = $value;

        return true;
    }

    protected function nativeGet(string $key): ?string
    {
        return $this->slots[$key] ?? null;
    }

    protected function nativeDelete(string $key): bool
    {
        if (! $this->deleteSucceeds) {
            return false;
        }

        unset($this->slots[$key]);

        return true;
    }
}
