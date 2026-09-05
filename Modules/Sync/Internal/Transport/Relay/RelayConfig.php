<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\RelayConfigWriteException;
use Modules\Sync\Internal\Exceptions\SecretFileException;
use Throwable;

final class RelayConfig
{
    private const string CONFIG_SUB = 'sync/relay.json';

    private const string DRAIN_TOKENS_FILE = 'sync-relay-drain-tokens.json';

    // The two files the per-device scheme replaced: an install-scoped drain
    // secret every local user of this install shared, and a relay-wide token
    // the pairing QR handed to every peer that ever paired. Both are retired
    // rather than left behind — see retireSupersededSecretFiles().
    private const string SUPERSEDED_DRAIN_SECRET_FILE = 'sync-relay-drain-secret.json';

    private const string SUPERSEDED_RELAY_TOKEN_FILE = 'sync-relay-token.json';

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
     * @throws RelayConfigWriteException on an I/O failure, or when the pin this
     *                                   write would carry forward cannot be read
     */
    public function setEndpointUrl(?string $url): void
    {
        $this->rewrite(['endpoint' => $url ?? '', 'pin' => $this->storedValue('pin')]);
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

    /**
     * @throws RelayConfigWriteException on an I/O failure, or when the endpoint
     *                                   this write would carry forward cannot be read
     */
    public function setPin(?string $pin): void
    {
        $this->rewrite(['endpoint' => $this->storedValue('endpoint'), 'pin' => $pin ?? '']);
    }

    // Both setters rewrite the WHOLE file, so each carries the other's field
    // forward. Absent and unparseable are one answer to a reader and two to a
    // writer: a torn relay.json blanked the pin, which is the only thing
    // verifying the certificate the relay presents.
    /**
     * @throws RelayConfigWriteException when the stored file exists and will not read
     */
    private function storedValue(string $key): string
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);

        // is_file, not file_exists: a DIRECTORY standing where the config
        // belongs carries no field forward and is the write failure rewrite()
        // reports a moment later, under the name a reader can act on.
        if (! is_file($path)) {
            return '';
        }

        // The read is unsuppressed, so a file present but unreadable raises
        // rather than answering null. Both roads lead to the same refusal, and
        // callers of these setters are declared to expect only this type.
        try {
            $data = $this->readJsonObject($path);
        } catch (Throwable) {
            throw RelayConfigWriteException::couldNotReadBeforeWriting($path);
        }

        if ($data === null) {
            throw RelayConfigWriteException::couldNotReadBeforeWriting($path);
        }

        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string, string>  $data
     *
     * @throws RelayConfigWriteException on an I/O failure
     */
    private function rewrite(array $data): void
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw RelayConfigWriteException::couldNotCreateDirectory($dir);
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw RelayConfigWriteException::couldNotWrite($path);
        }
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

    // The drain credential for ONE device id on this install, minted on first
    // use and never transmitted. Keyed on the device id because device ids are
    // per-user: one secret for the whole install bound the relay to whichever
    // local user drained first and answered 401 to the second one forever.
    /**
     * @throws SecretFileException on an I/O failure, including one that would
     *                             leave the token readable
     */
    public function deviceDrainToken(string $deviceId): string
    {
        $tokens = $this->readDrainTokens();

        if (isset($tokens[$deviceId])) {
            return $tokens[$deviceId];
        }

        $tokens[$deviceId] = RelayDrainToken::mint($deviceId);
        $this->writeDrainTokens($tokens);

        return $tokens[$deviceId];
    }

    /**
     * @return array<array-key, string>
     */
    private function readDrainTokens(): array
    {
        $path = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.self::DRAIN_TOKENS_FILE;

        // is_file, not the file_exists the shared reader uses: a directory
        // standing where this file belongs must reach the write below and be
        // reported as the I/O failure it is, rather than dying in a read that
        // never had anything to return.
        $data = is_file($path) ? $this->readJsonObject($path) : null;
        $stored = $data === null ? null : ($data['tokens'] ?? null);

        if (! is_array($stored)) {
            return [];
        }

        $tokens = [];

        foreach ($stored as $deviceId => $token) {
            if (is_string($token) && $token !== '') {
                $tokens[$deviceId] = $token;
            }
        }

        return $tokens;
    }

    // Same mkdir 0700 -> write -> chmod 0600 discipline as the identity
    // key-file: these are bearer credentials, so a chmod failure that would
    // leave them world-readable throws rather than being swallowed.
    /**
     * @param  array<array-key, string>  $tokens
     *
     * @throws SecretFileException on an I/O failure
     */
    private function writeDrainTokens(array $tokens): void
    {
        $dir = UserDataPathService::secretsPath();
        $path = $dir.DIRECTORY_SEPARATOR.self::DRAIN_TOKENS_FILE;

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true)) {
            throw SecretFileException::couldNotCreateSecretsDirectory($dir);
        }

        // FORCE_OBJECT because a device id that is all digits decodes to an
        // integer key: json_encode would then emit a JSON array, which reads
        // back renumbered from zero and hands that device a fresh token on
        // every drain — a new TOFU claim the relay has already refused.
        $json = json_encode(['tokens' => $tokens], JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);

        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw SecretFileException::couldNotWriteDrainTokens($path);
        }

        if (! @chmod($path, 0600)) {
            throw SecretFileException::couldNotLockDown($path);
        }

        $this->retireSupersededSecretFiles();
    }

    // Best-effort, and only once the replacement is safely on disk. A secret
    // nothing reads any more still reads like a live one to whoever finds it
    // next, and the relay-wide one in particular is the credential every peer
    // that ever scanned a pairing QR is still holding a copy of.
    private function retireSupersededSecretFiles(): void
    {
        foreach ([self::SUPERSEDED_DRAIN_SECRET_FILE, self::SUPERSEDED_RELAY_TOKEN_FILE] as $file) {
            $path = UserDataPathService::secretsPath().DIRECTORY_SEPARATOR.$file;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
