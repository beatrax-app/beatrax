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

    private bool $refuseDelete = false;

    public ?string $pollValue = null;

    protected function runtimeAvailable(): bool
    {
        return $this->available;
    }

    // Unpinned, this half of isAvailable() falls to the real bridge, which no
    // repo toolchain can reach, so every availability assertion would fail for
    // the absence of a phone rather than for anything the test is about.
    /** @var array{available?: bool, reason?: string} */
    public array $capability = ['available' => true, 'reason' => 'available'];

    /**
     * @return array{available?: bool, reason?: string}
     */
    protected function vaultCapability(): array
    {
        return $this->capability;
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

    protected function vaultDelete(string $key): bool
    {
        if ($this->refuseDelete) {
            return false;
        }

        unset($this->store[$key]);

        return true;
    }

    // Stands in for a platform that answers the removal with an error. Only
    // the native side can produce that, so a test cannot reach it any other
    // way — and a removal that failed silently is the whole finding.
    public function refuseDelete(): void
    {
        $this->refuseDelete = true;
    }
}
