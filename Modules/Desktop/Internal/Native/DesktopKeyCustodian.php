<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Native\Desktop\System;

// Depends on the CONCRETE Native\Desktop\System rather than the facade, whose
// PHPDoc wrongly types encrypt/decrypt as non-nullable.
final class DesktopKeyCustodian implements KeyCustodian
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly System $system,
    ) {}

    public function store(string $rawKey): string
    {
        if (! $this->canEncrypt()) {
            // Unchanged, so the Auth module's encrypted-session custody applies.
            return $rawKey;
        }

        // base64 so a binary key survives the JSON/HTTP hop to the Electron
        // safeStorage process intact.
        $encoded = base64_encode($rawKey);
        $encrypted = $this->system->encrypt($encoded);
        sodium_memzero($encoded);

        if ($encrypted === null || $encrypted === '') {
            return $rawKey;
        }

        return $encrypted;
    }

    public function read(string $blob): ?string
    {
        if (! $this->canEncrypt()) {
            // The blob IS the raw key — store() returned it unchanged.
            return $blob;
        }

        // Null/'' means this ciphertext no longer decrypts on this machine
        // (rotated key, wrong host); callers fall back to a PIN unlock.
        $decrypted = $this->system->decrypt($blob);
        if ($decrypted === null || $decrypted === '') {
            return null;
        }

        $decoded = base64_decode($decrypted, strict: true);

        return $decoded === false ? null : $decoded;
    }

    public function forget(string $handle): void
    {
        // Intentionally empty: the ciphertext handle has no backing keychain
        // entry of its own, so forgetting the session copy is enough.
    }

    private function canEncrypt(): bool
    {
        if ($this->config->get('nativephp-internal.running') !== true) {
            return false;
        }

        return $this->system->canEncrypt();
    }
}
