<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;

/**
 * @link ../../../../../.docs/features/sync/relay-endpoint-authorization.md#a-drain-token-names-the-one-device-it-drains
 */
final class RelayDrainRegistry
{
    private const string REGISTRY_FILE = 'sync-relay-drain-registry.json';

    // Trust-on-first-use, but only for a token that already names this device
    // id. Registering whatever bearer arrived let any past peer's copy of the
    // relay-wide QR token claim a mailbox nobody had drained yet — which is
    // every new device's FIRST drain, the one carrying its GDK epoch wraps.
    public function registerOrAuthorize(string $did, string $presentedToken): bool
    {
        if (! RelayDrainToken::namesDevice($presentedToken, $did)) {
            return false;
        }

        $store = $this->load();

        if ($this->storedHash($store, $did) === null) {
            $store[$did] = ['v' => RelayDrainToken::VERSION, 'hash' => hash('sha256', $presentedToken)];
            $this->persist($store);

            return true;
        }

        return $this->authorizes($did, $presentedToken);
    }

    // Verify only, never register: an unregistered did is refused here rather
    // than claimed, which is what keeps the TOFU decision in one place.
    // Confirm derives its did from an autoincrement row id, so registering
    // there let a caller sweep DELETE /relay/drain/{1..N}.
    public function authorizes(string $did, string $presentedToken): bool
    {
        if (! RelayDrainToken::namesDevice($presentedToken, $did)) {
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
        return $this->entryHash($store[$did] ?? null);
    }

    // The verifier a binding written by THIS scheme carries, or null for
    // anything else. The superseded install-scoped scheme wrote a bare hash
    // string and the two are indistinguishable as hex, so the version marker
    // is the only thing that can tell them apart on an upgraded install.
    private function entryHash(mixed $entry): ?string
    {
        if (! is_array($entry) || ($entry['v'] ?? null) !== RelayDrainToken::VERSION) {
            return null;
        }

        $hash = $entry['hash'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    // A missing, unreadable, empty or non-object file all collapse to an empty
    // registry so the next presented token TOFU-registers cleanly. A binding
    // from the superseded scheme is dropped for the same reason: honoured, it
    // would 401 the upgraded owner out of its own mailbox for good.
    /**
     * @return array<array-key, mixed>
     */
    private function load(): array
    {
        $path = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::REGISTRY_FILE;

        // is_file, not file_exists: a directory standing here would be read as
        // a file, and the warning that raises becomes an exception out of a
        // drain rather than the write failure persist() reports.
        if (! is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        $data = $json === false || $json === '' ? null : json_decode($json, true);

        return is_array($data)
            ? array_filter($data, fn (mixed $entry): bool => $this->entryHash($entry) !== null)
            : [];
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

        // FORCE_OBJECT because a device id that is all digits decodes to an
        // integer key: json_encode would then emit a JSON array, which reads
        // back renumbered from zero and silently unbinds that device.
        $json = json_encode($store, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

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
