<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;

// Relay-side trust-on-first-use store binding each draining device_id to a
// hash of the per-device drain secret it first presented. Replaces the old
// shared-secret-derived token that every relay peer could recompute from the
// QR relay token, closing the cross-tenant drain/confirm metadata hole.
final class RelayDrainRegistry
{
    private const string REGISTRY_FILE = 'sync-relay-drain-registry.json';

    // TOFU: the first token seen for a did is recorded and trusted; every later
    // drain must present one whose hash hash_equals it. Only the DRAIN path may
    // reach this. Confirm derives the did from an autoincrement row id, so
    // registering there let a caller sweep DELETE /relay/drain/{1..N}.
    public function registerOrAuthorize(string $did, string $presentedToken): bool
    {
        if ($did === '' || $presentedToken === '') {
            return false;
        }

        $store = $this->load();

        if ($this->storedHash($store, $did) === null) {
            $store[$did] = hash('sha256', $presentedToken);
            $this->persist($store);

            return true;
        }

        return $this->authorizes($did, $presentedToken);
    }

    // Verify only, never register: an unregistered did is refused here rather
    // than claimed, which is what keeps the TOFU decision in one place.
    public function authorizes(string $did, string $presentedToken): bool
    {
        if ($did === '' || $presentedToken === '') {
            return false;
        }

        $storedHash = $this->storedHash($this->load(), $did);

        if ($storedHash === null) {
            return false;
        }

        // Timing-safe: the stored hash is a fixed-length hex digest, so the
        // compare never leaks how many leading characters matched.
        return hash_equals($storedHash, hash('sha256', $presentedToken));
    }

    /**
     * @param  array<array-key, mixed>  $store
     */
    private function storedHash(array $store, string $did): ?string
    {
        return isset($store[$did]) && is_string($store[$did]) ? $store[$did] : null;
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
