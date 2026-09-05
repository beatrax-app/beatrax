<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Identity\SecureStorageKeyCustodian;

class CountingSecureStorageCustodian extends SecureStorageKeyCustodian
{
    /** @var array<string, string> */
    public array $slots = [];

    public int $nativeReads = 0;

    protected function runtimeAvailable(): bool
    {
        return true;
    }

    protected function nativeSet(string $key, string $value): bool
    {
        $this->slots[$key] = $value;

        return true;
    }

    protected function nativeGet(string $key): ?string
    {
        $this->nativeReads++;

        return $this->slots[$key] ?? null;
    }

    protected function nativeDelete(string $key): void
    {
        unset($this->slots[$key]);
    }
}
