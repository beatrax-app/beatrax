<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;

// Relay-side trust-on-first-use store binding each draining device_id to a
// hash of the per-device drain secret it first presented. Replaces the old
// shared-secret-derived token that every relay peer could recompute from the
// QR relay token, closing the cross-tenant drain/confirm metadata hole.
/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class RelayDrainRegistry
{
    private const REGISTRY_FILE = 'sync-relay-drain-registry.json';

    // TOFU: the first token seen for a did is recorded as did -> sha256(token)
    // and trusted; every later drain/confirm must present a token whose hash
    // hash_equals it. Residual: an attacker who registers a victim's did BEFORE
    // the victim ever drains wins the slot — far narrower than the old token.
    public function authorizes(string $did, string $presentedToken): bool
    {
        if ($did === '' || $presentedToken === '') {
            return false;
        }

        $store = $this->load();
        $presentedHash = hash('sha256', $presentedToken);
        $storedHash = isset($store[$did]) && is_string($store[$did]) ? $store[$did] : null;

        if ($storedHash === null) {
            $store[$did] = $presentedHash;
            $this->persist($store);

            return true;
        }

        // Timing-safe: the stored hash is a fixed-length hex digest, so the
        // compare never leaks how many leading characters matched.
        return hash_equals($storedHash, $presentedHash);
    }

    // A missing, unreadable, empty or non-object file all collapse to an empty
    // registry so the next presented token TOFU-registers cleanly.
    /**
     * @return array<array-key, mixed>
     */
    private function load(): array
    {
        $path = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::REGISTRY_FILE;

        if (! file_exists($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    // Same mkdir 0700 -> write -> chmod 0600 discipline RelayConfig uses for its
    // secrets: the file holds drain-token verifiers, so a chmod failure that
    // would leave it writable by others throws rather than being swallowed.
    /**
     * @param  array<array-key, mixed>  $store
     *
     * @throws SecretFileException on an I/O failure
     */
    private function persist(array $store): void
    {
        $dir = UserDataPathService::secretsPath();
        $path = $dir.DIRECTORY_SEPARATOR.self::REGISTRY_FILE;

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw SecretFileException::couldNotCreateSecretsDirectory($dir);
        }

        $json = json_encode($store, JSON_THROW_ON_ERROR);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw SecretFileException::couldNotWriteDrainRegistry($path);
        }

        if (! @chmod($path, 0600)) {
            throw SecretFileException::couldNotLockDown($path);
        }
    }
}
