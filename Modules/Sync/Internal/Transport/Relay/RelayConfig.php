<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\RelayConfigWriteException;
use Modules\Sync\Internal\Exceptions\SecretFileException;

final class RelayConfig
{
    private const string CONFIG_SUB = 'sync/relay.json';

    private const string TOKEN_FILE = 'sync-relay-token.json';

    private const string DRAIN_SECRET_FILE = 'sync-relay-drain-secret.json';

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

    // Insecure AND routable off this network — the transport refuses these,
    // since plaintext to a public host exposes ciphertext and metadata sizes
    // in transit. A private or loopback host is the desktop's own relay,
    // reachable only from this LAN, and is the out-of-box pairing path.
    public function isPubliclyInsecure(): bool
    {
        if (! $this->isInsecure()) {
            return false;
        }

        $host = parse_url((string) $this->endpointUrl(), PHP_URL_HOST);

        // An endpoint with no host at all is treated as public: nothing about
        // it says it stays on this network.
        return ! is_string($host) || $host === '' || ! $this->isLanHost($host);
    }

    // Whether an endpoint names a host reachable only from this network —
    // the desktop's own relay, whether this device runs it or learned it from
    // a QR. Anything else is an operator's self-hosted relay: configuration
    // this device does not own and must never re-point or re-credential.
    public function isLanEndpoint(string $endpoint): bool
    {
        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' && $this->isLanHost($host);
    }

    // The one question all three endpoint checks were each answering for
    // themselves: is this host reachable only from this network? `localhost`,
    // loopback, and private IPv4 count; link-local and other reserved ranges do
    // not. A domain name never does — DNS resolves to wherever, not ours to trust.
    private function isLanHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        // Loopback (127/8) is the intended same-machine relay; everything else
        // must be an RFC 1918 PRIVATE address. Link-local (169.254 — APIPA and
        // the 169.254.169.254 metadata endpoint) and other reserved ranges are
        // refused, so a scanned QR cannot drive a plaintext POST at one of them.
        return str_starts_with($host, '127.') || $this->isPrivateIpv4($host);
    }

    // NO_PRIV_RANGE fails only for the private ranges, so a failure here is
    // precisely the RFC 1918 LAN case (reserved/link-local addresses pass it
    // and are therefore rejected).
    private function isPrivateIpv4(string $host): bool
    {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE,
        ) === false;
    }

    // Whether an endpoint is one the transport would actually use. Callers
    // that PERSIST an endpoint must ask this first: storing one the client
    // later refuses leaves a device holding a relay it can never send to.
    public function wouldAcceptEndpoint(string $endpoint): bool
    {
        if (str_starts_with($endpoint, 'https://')) {
            return true;
        }

        if (! str_starts_with($endpoint, 'http://')) {
            return false;
        }

        // Plaintext is only ever accepted to a host that cannot leave this
        // network — which is the same question isLanEndpoint() answers.
        return $this->isLanEndpoint($endpoint);
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

        // Keep any pin already stored: setEndpointUrl() is also how a peer
        // records an endpoint it just learned, and dropping the pin here
        // would silently downgrade a pinned relay to unverified. An
        // unreadable config must not pre-empt the write error below.
        try {
            $existingPin = $this->pin() ?? '';
        } catch (\Throwable) {
            $existingPin = '';
        }

        $data = ['endpoint' => $url ?? '', 'pin' => $existingPin];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw RelayConfigWriteException::couldNotWrite($path);
        }
    }

    // The peer's pinned SPKI key, learned out-of-band from the QR. Present
    // means: verify the relay presents exactly this key and nothing else.
    public function pin(): ?string
    {
        $data = $this->readJsonObject(UserDataPathService::appPath(self::CONFIG_SUB));

        if ($data === null) {
            return null;
        }

        $pin = $data['pin'] ?? null;

        return is_string($pin) && $pin !== '' ? $pin : null;
    }

    public function setPin(?string $pin): void
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw RelayConfigWriteException::couldNotCreateDirectory($dir);
        }

        $data = ['endpoint' => $this->endpointUrl() ?? '', 'pin' => $pin ?? ''];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

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

    // This device's OWN per-instance drain secret, minted once and persisted
    // like the token/pin. The draining client presents it and the relay
    // verifies it via trust-on-first-use, so it is per-device — NOT the
    // relay-wide authToken every paired peer receives through the QR.
    /**
     * @throws SecretFileException on an I/O failure, including one that would
     *                             leave the secret readable
     */
    public function deviceDrainSecret(): string
    {
        $existing = $this->readDeviceDrainSecret();
        if ($existing !== null) {
            return $existing;
        }

        $secret = bin2hex(random_bytes(32));
        $this->writeDeviceDrainSecret($secret);

        return $secret;
    }

    private function readDeviceDrainSecret(): ?string
    {
        $data = $this->readJsonObject(UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::DRAIN_SECRET_FILE);
        if ($data === null) {
            return null;
        }

        $secret = $data['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    // Same mkdir 0700 -> write -> chmod 0600 discipline as setAuthToken: the
    // secret is a per-device bearer credential, so a chmod failure that would
    // leave it world-readable throws rather than being swallowed.
    /**
     * @throws SecretFileException on an I/O failure
     */
    private function writeDeviceDrainSecret(string $secret): void
    {
        $dir = UserDataPathService::secretsPath();
        $path = $dir.DIRECTORY_SEPARATOR.self::DRAIN_SECRET_FILE;

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw SecretFileException::couldNotCreateSecretsDirectory($dir);
        }

        $json = json_encode(['secret' => $secret], JSON_THROW_ON_ERROR);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw SecretFileException::couldNotWriteDrainSecret($path);
        }

        if (! @chmod($path, 0600)) {
            throw SecretFileException::couldNotLockDown($path);
        }
    }
}
