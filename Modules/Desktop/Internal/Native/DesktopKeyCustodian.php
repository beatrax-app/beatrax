<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Auth\Public\Contracts\KeyCustodian;
use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Auth\Public\Exceptions\KeyCustodyRefused;
use Native\Desktop\System;

// Depends on the CONCRETE Native\Desktop\System rather than the facade, whose
// PHPDoc wrongly types encrypt/decrypt as non-nullable.
final class DesktopKeyCustodian implements KeyCustodian
{
    // Marks a handle this custodian actually encrypted, so a later read can
    // tell ciphertext it must open from a raw key an unavailable safeStorage
    // made store() hand straight back. Nothing else can tell them apart, and
    // guessing released the ciphertext as though it were the data key.
    private const string ENCRYPTED_PREFIX = 'nativephp:safestorage:v1:';

    private const string STORE = 'Electron safeStorage';

    // What a store() call actually got back, and the only evidence custody() has
    // that a key ever reached safeStorage. Null until one has run. canEncrypt()
    // answers whether a write COULD succeed, and it answered yes on every
    // refusal this class shipped with.
    private ?bool $writeAccepted = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly System $system,
        private readonly SafeStorageBackendProbe $backend,
    ) {}

    public function store(string $rawKey): string
    {
        if (! $this->canEncrypt()) {
            return $rawKey;
        }

        // base64 so a binary key survives the JSON/HTTP hop to the Electron
        // safeStorage process intact.
        $encoded = base64_encode($rawKey);
        $encrypted = $this->system->encrypt($encoded);
        sodium_memzero($encoded);

        if ($encrypted === null || $encrypted === '') {
            // Present and refusing: not the absent store the branch above
            // degrades for. Returning the key here put it in the session
            // payload, beside the ledger it is the root wrap of.
            $this->writeAccepted = false;

            throw KeyCustodyRefused::writeRefused(self::class, self::STORE);
        }

        $this->writeAccepted = true;

        return self::ENCRYPTED_PREFIX.$encrypted;
    }

    public function read(string $blob): ?string
    {
        if (str_starts_with($blob, self::ENCRYPTED_PREFIX)) {
            // safeStorage comes up after the first requests do, and handing the
            // ciphertext back during that window released a 56-byte non-key
            // into the identity loader, the GDK keyring and the column codec.
            return $this->canEncrypt()
                ? $this->decrypt(substr($blob, strlen(self::ENCRYPTED_PREFIX)))
                : null;
        }

        if (! $this->canEncrypt()) {
            // store() was pass-through on this platform, so the handle IS the
            // raw key and there is nothing to unwrap.
            return $blob;
        }

        // Unmarked while safeStorage works: a handle written before the marker
        // existed. A raw key from a pass-through store fails this and becomes
        // a PIN unlock, which is the safe half of the ambiguity.
        return $this->decrypt($blob);
    }

    // Null/'' means this ciphertext no longer decrypts on this machine
    // (rotated key, wrong host); callers fall back to a PIN unlock.
    private function decrypt(string $ciphertext): ?string
    {
        $decrypted = $this->system->decrypt($ciphertext);

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

    public function custody(): KeyCustody
    {
        if (! $this->canEncrypt()) {
            return KeyCustody::Session;
        }

        // Answered from the write, not from the probe: that safeStorage CAN
        // encrypt is not evidence that it did, and a custody report sourced
        // from the probe alone claimed the OS held a key it had refused.
        if ($this->writeAccepted === false) {
            return KeyCustody::PlatformStoreRefusedTheWrite;
        }

        return $this->backend->protects()
            ? KeyCustody::OperatingSystem
            : KeyCustody::PlatformStoreDoesNotProtect;
    }

    // Deliberately blind to the backend the probe names: a keyring-less Linux
    // still round-trips, and refusing to encrypt there would strand every blob
    // an earlier build wrote on that machine -- the OAuth secrets and the
    // biometric wrap among them -- for a layer that was never the secret.
    private function canEncrypt(): bool
    {
        if ($this->config->get('nativephp-internal.running') !== true) {
            return false;
        }

        return $this->system->canEncrypt();
    }
}
