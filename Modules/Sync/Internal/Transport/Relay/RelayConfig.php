<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use RuntimeException;

/**
 * Reads and writes the relay endpoint URL and auth token for the zero-knowledge
 * store-and-forward relay (XPORT-03, D-01/D-02).
 *
 * Storage convention:
 *   - Endpoint URL (non-secret): plain JSON at
 *     `UserDataPathService::appPath('sync/relay.json')`.
 *     Default: absent / empty — the relay is opt-in (D-01 "nothing leaves the
 *     machine until the user sets an endpoint").
 *   - Auth token (secret): plain JSON at
 *     `UserDataPathService::secretsPath() . '/sync-relay-token.json'`
 *     written with chmod 600 — same pattern as the device identity key-file in
 *     DeviceIdentityService. Never stored in .env.
 *
 * HTTPS enforcement (T-13-08 / Pitfall 6):
 *   The relay endpoint URL is stored as-is, but isInsecure() flags non-https
 *   URLs so the UI can warn the user. The relay itself is ZK regardless of TLS
 *   (it never decrypts payloads), but an http:// endpoint leaks ciphertext sizes
 *   and metadata to a network eavesdropper.
 *
 * D-01 "default none": when relay.json is absent or its `endpoint` key is empty,
 * isConfigured() returns false and LAN-direct is the only active sync path.
 * Nothing leaves the machine until the user explicitly configures an endpoint.
 */
final class RelayConfig
{
    /** Sub-path within appPath() for the endpoint config file. */
    private const CONFIG_SUB = 'sync/relay.json';

    /** File name within secretsPath() for the token. */
    private const TOKEN_FILE = 'sync-relay-token.json';

    /**
     * Read the configured relay endpoint URL, or null when unconfigured.
     *
     * Returns null when:
     *   - relay.json does not exist (never configured), or
     *   - the `endpoint` key is absent or empty string.
     *
     * Never throws on a missing file — absent file = "not configured".
     */
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

    /**
     * Whether a relay endpoint is configured.
     *
     * Returns false when relay.json is absent or the endpoint is empty (D-01
     * default-none posture — LAN-direct is the out-of-box path).
     */
    public function isConfigured(): bool
    {
        return $this->endpointUrl() !== null;
    }

    /**
     * Whether the configured endpoint uses plain HTTP (not HTTPS).
     *
     * The relay is ZK regardless of transport (it never decrypts), but an http://
     * endpoint exposes ciphertext and metadata sizes to network observers. The UI
     * should surface a warning when this returns true (T-13-08 / Pitfall 6).
     *
     * Returns false when no endpoint is configured.
     */
    public function isInsecure(): bool
    {
        $url = $this->endpointUrl();
        if ($url === null) {
            return false;
        }

        return ! str_starts_with($url, 'https://');
    }

    /**
     * Persist the relay endpoint URL to `sync/relay.json`.
     *
     * Passing null or empty string clears the endpoint (reverts to "not configured").
     * Non-HTTPS URLs are stored but flagged insecure — the caller may log a warning.
     *
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

    /**
     * Read the relay auth token, or null when not set.
     *
     * The token is stored at `secretsPath()/sync-relay-token.json` with chmod 600.
     * Never reads from .env.
     */
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

    /**
     * Derive a per-device drain token bound to a specific device_id (CR-04).
     *
     * The relay's stored auth token is a shared secret. A single shared bearer
     * token is incompatible with the multi-device ZK posture: any token-holder
     * could otherwise drain (and delete) an arbitrary recipient's mailbox by
     * passing any `did`. To bind authorization to the requested mailbox we derive
     * a token = HMAC-SHA256(authToken, device_id). The relay verifies the
     * presented token against the value derived for the `$did` it is about to
     * drain, so a token scoped to device A cannot drain device B's mailbox.
     *
     * ZK is preserved: the relay only ever HMACs the device_id (already routing
     * metadata it can see) with a secret it already holds. It learns no plaintext,
     * no user_id, and never touches blob content.
     *
     * Returns null when no relay auth token is configured (token-less relay).
     *
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

    /**
     * Persist the relay auth token to `secretsPath()/sync-relay-token.json` with chmod 600.
     *
     * Mirrors the DeviceIdentityService key-file write pattern:
     *   mkdir 0700 → write → chmod 0600.
     *
     * Passing null clears the stored token.
     *
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

        // chmod 600: owner read/write only — mirrors DeviceIdentityService posture.
        @chmod($path, 0600);
    }
}
