<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\RelayConfigWriteException;
use Modules\Sync\Internal\Exceptions\SecretFileException;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final class RelayConfig
{
    private const CONFIG_SUB = 'sync/relay.json';

    private const TOKEN_FILE = 'sync-relay-token.json';

    // Never throws on a missing file — absent file/empty `endpoint` key
    // both mean "not configured".
    public function endpointUrl(): ?string
    {
        $data = $this->readJsonObject(UserDataPathService::appPath(self::CONFIG_SUB));
        if ($data === null) {
            return null;
        }

        $endpoint = $data['endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }

    // False when relay.json is absent or the endpoint is empty — LAN-direct
    // is the out-of-box path.
    public function isConfigured(): bool
    {
        return $this->endpointUrl() !== null;
    }

    // The relay is ZK regardless of transport (it never decrypts), but an
    // http:// endpoint exposes ciphertext and metadata sizes to network
    // observers — the UI should surface a warning when this returns true.
    public function isInsecure(): bool
    {
        $url = $this->endpointUrl();
        if ($url === null) {
            return false;
        }

        return ! str_starts_with($url, 'https://');
    }

    // Passing null or empty string clears the endpoint (reverts to "not
    // configured"). Non-HTTPS URLs are stored but flagged insecure.
    /**
     * @throws RelayConfigWriteException on an I/O failure
     */
    public function setEndpointUrl(?string $url): void
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw RelayConfigWriteException::couldNotCreateDirectory($dir);
        }

        $data = ['endpoint' => $url ?? ''];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw RelayConfigWriteException::couldNotWrite($path);
        }
    }

    public function authToken(): ?string
    {
        $data = $this->readJsonObject(UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::TOKEN_FILE);
        if ($data === null) {
            return null;
        }

        $token = $data['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    // Shared read path for both config files: a missing file, an unreadable
    // or empty body, and non-object JSON all collapse to null so callers
    // treat "absent" and "malformed" identically as "not configured".
    /**
     * @return array<array-key, mixed>|null
     */
    private function readJsonObject(string $path): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    // Derives a per-device drain token = HMAC-SHA256(authToken, device_id) —
    // a single shared bearer token would let any holder drain an arbitrary
    // recipient's mailbox. ZK preserved: only HMACs the device_id (routing
    // metadata already visible).
    /**
     * @param  string  $deviceId  The device_id whose mailbox the token authorizes.
     */
    public function deriveDeviceToken(string $deviceId): ?string
    {
        $secret = $this->authToken();
        if ($secret === null || $secret === '' || $deviceId === '') {
            return null;
        }

        return hash_hmac('sha256', $deviceId, $secret);
    }

    // Mirrors the DeviceIdentityService key-file write pattern: mkdir 0700
    // -> write -> chmod 0600. Passing null clears the stored token.
    /**
     * @throws SecretFileException on an I/O failure, including one that would
     *                             leave the token readable
     */
    public function setAuthToken(?string $token): void
    {
        $dir = UserDataPathService::secretsPath();
        $path = $dir.DIRECTORY_SEPARATOR.self::TOKEN_FILE;

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw SecretFileException::couldNotCreateSecretsDirectory($dir);
        }

        $data = ['token' => $token ?? ''];
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw SecretFileException::couldNotWriteRelayToken($path);
        }

        // A silently-swallowed chmod failure would leave the secret token
        // file world-readable with no signal — verify and throw instead.
        if (! @chmod($path, 0600)) {
            throw SecretFileException::couldNotLockDown($path);
        }
    }
}
