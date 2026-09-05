<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Mobile\Internal\Identity\BiometricKeyVault;

// The native BiometricVault facade is unreachable in the repo toolchain, so this
// subclass supplies an in-memory enclave. The blob crypto is real, so the
// enroll/recover round-trip exercises the true wrap and unwrap path; only the OS
// biometric gate is faked.
class FakeBiometricKeyVault extends BiometricKeyVault
{
    /** @var array<string, string> */
    public array $store = [];

    public bool $available = true;

    /** @var array<string, mixed>|null forces a specific native get() outcome */
    public ?array $forcedGet = null;

    public ?string $pollValue = null;

    protected function runtimeAvailable(): bool
    {
        return $this->available;
    }

    // The enclave path is iOS, which reports Darwin. Unpinned, the fake inherits
    // the HOST's PHP_OS_FAMILY, so platformCanStore() answers false on a Linux
    // runner and every availability assertion passes on a Mac and fails in CI.
    protected function platformFamily(): string
    {
        return 'Darwin';
    }

    protected function pollRecovered(): ?string
    {
        return $this->pollValue;
    }

    protected function vaultSet(string $key, string $value): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    protected function vaultGet(string $key, string $reason): array
    {
        if ($this->forcedGet !== null) {
            return $this->forcedGet;
        }

        return isset($this->store[$key])
            ? ['value' => $this->store[$key], 'authenticated' => true]
            : ['value' => '', 'missing' => true];
    }

    protected function vaultDelete(string $key): void
    {
        unset($this->store[$key]);
    }
}
