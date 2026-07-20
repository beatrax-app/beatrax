<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use RuntimeException;

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
        $path = UserDataPathService::appPath(self::CONFIG_SUB);

        if (! file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }

        $data = json_decode($json, true, 512, 0);
        if (! is_array($data)) {
            return null;
        }

        $endpoint = $data['endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        return $endpoint;
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
     * @throws RuntimeException on an I/O failure.
     */
    public function setEndpointUrl(?string $url): void
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            if (! mkdir($dir, 0700, true)) {
                throw new RuntimeException("Cannot create relay config directory: {$dir}");
            }
        }

        $data = ['endpoint' => $url ?? ''];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write relay config to: {$path}");
        }
    }

    public function authToken(): ?string
    {
        $path = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::TOKEN_FILE;

        if (! file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return null;
        }

        $data = json_decode($json, true, 512, 0);
        if (! is_array($data)) {
            return null;
        }

        $token = $data['token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
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
     * @throws RuntimeException on an I/O failure.
     */
    public function setAuthToken(?string $token): void
    {
        $dir = UserDataPathService::secretsPath();
        $path = $dir.DIRECTORY_SEPARATOR.self::TOKEN_FILE;

        if (! is_dir($dir)) {
            if (! mkdir($dir, 0700, true)) {
                throw new RuntimeException("Cannot create secrets directory: {$dir}");
            }
        }

        $data = ['token' => $token ?? ''];
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write relay token to: {$path}");
        }

        // A silently-swallowed chmod failure would leave the secret token
        // file world-readable with no signal — verify and throw instead.
        if (! @chmod($path, 0600)) {
            throw new RuntimeException(
                "Cannot chmod relay token file to 0600 (secret would be left readable): {$path}"
            );
        }
    }
}
