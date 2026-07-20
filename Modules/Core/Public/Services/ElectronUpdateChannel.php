<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * Thin Laravel-side adapter over electron-updater (vendored via
 * NativePHP) that exposes the channel-resolution + integrity-check
 * surface the SystemAlertsBanner subscribes to.
 *
 * Trust posture: with no OS-level code signing on any shipped
 * platform, Ed25519 manifest signing + SHA512 binary verification
 * are the SOLE binary-integrity signals between a tampered GitHub-
 * Releases asset and a malicious binary running on a user's machine.
 * Every fetched manifest is verified against the publisher public key
 * embedded in the config file BEFORE any system_alerts row is raised;
 * every downloaded binary is hashed against the SHA512 value the
 * (now-trusted) manifest declared BEFORE the install handoff fires.
 *
 * Public/private key generation runs once via
 * `sodium_crypto_sign_keypair()`; the SECRET half lives in the
 * repository secret ED25519_PRIVATE_KEY and never leaves the release-
 * pipeline runtime, while the PUBLIC half is committed as the default
 * for `auto_update.publisher_public_key_hex` (public-key cryptography
 * makes this safe).
 */
final readonly class ElectronUpdateChannel
{
    /**
     * 30-day staleness threshold for the `update.stale` banner kind.
     * Hardcoded — no per-user configuration knob.
     */
    private const STALE_THRESHOLD_DAYS = 30;

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private Clock $clock,
        private Repository $config,
    ) {}

    /**
     * Returns the currently-selected update channel name. `stable`
     * resolves the `latest.yml` manifest electron-updater writes for
     * `v*.*.*` tags; `preview` resolves the `beta.yml` manifest it
     * writes for `v*-rc.*` tags.
     */
    public function channel(): string
    {
        $value = $this->config->get('auto_update.update_channel', 'stable');

        return is_string($value) ? $value : 'stable';
    }

    /**
     * Fetches the latest publisher manifest, verifies its Ed25519
     * signature against the in-bundle public key, and returns a DTO
     * the caller uses to record an `update.available` system_alerts
     * row. Returns `null` on every silent-failure path:
     *  - the fetcher returned `null` (publisher offline / 404);
     *  - signature verification failed (tampered manifest);
     *  - a row for the same `latestVersion` is already unacknowledged
     *    in the system_alerts table (idempotency safeguard).
     *
     * Every silent-failure path is logged at WARNING level so the
     * dev-mode log-tail panel surfaces the rejection without
     * surfacing a misleading banner.
     */
    public function poll(PublisherManifestFetcher $fetcher): ?UpdateManifestDto
    {
        $manifest = $fetcher->fetch($this->channel());
        if ($manifest === null) {
            return null;
        }

        if (! $this->verifyManifest($manifest['body'], $manifest['signature'])) {
            $this->logger->warning('ElectronUpdateChannel: rejected manifest with invalid Ed25519 signature.', [
                'channel' => $this->channel(),
                'latest_version' => $manifest['latest_version'],
            ]);

            return null;
        }

        if ($this->hasUnacknowledgedAvailabilityRow($manifest['latest_version'])) {
            return null;
        }

        return new UpdateManifestDto(
            latestVersion: $manifest['latest_version'],
            sha512Hex: $manifest['sha512_hex'],
            publishedAt: $manifest['published_at'],
            channel: $this->channel(),
        );
    }

    /**
     * Verifies a detached Ed25519 signature over a manifest body
     * against the in-bundle publisher public key.
     *
     * Contract: returns `true` iff
     * `sodium_crypto_sign_verify_detached` succeeds; returns `false`
     * on every failure mode (tampered body, tampered signature,
     * malformed-length signature, malformed-length public key). No
     * exception is ever thrown to callers — a failed verification is
     * an in-band signal that the poll loop logs at WARNING level and
     * suppresses by returning null from `poll()`.
     *
     * @param  string  $manifestBody  Raw manifest bytes (the bytes
     *                                that were signed; not a JSON-
     *                                decoded value).
     * @param  string  $detachedSignature  Binary 64-byte Ed25519
     *                                     signature. Callers holding a
     *                                     hex signature must `hex2bin`
     *                                     before invocation.
     */
    public function verifyManifest(string $manifestBody, string $detachedSignature): bool
    {
        if ($detachedSignature === '') {
            return false;
        }

        $publicKeyHex = $this->config->get('auto_update.publisher_public_key_hex');
        if (! is_string($publicKeyHex) || $publicKeyHex === '') {
            $this->logger->warning('ElectronUpdateChannel: missing or invalid publisher public key configuration.');

            return false;
        }

        try {
            $publicKey = sodium_hex2bin($publicKeyHex);
            if ($publicKey === '') {
                return false;
            }

            return sodium_crypto_sign_verify_detached(
                $detachedSignature,
                $manifestBody,
                $publicKey,
            );
        } catch (SodiumException $e) {
            $this->logger->warning('ElectronUpdateChannel: Ed25519 verification rejected malformed input.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Verifies the SHA512 hash of a downloaded binary against the
     * value declared in the (already Ed25519-verified) manifest.
     *
     * Comparison uses `hash_equals` for a constant-time check so a
     * partial-byte match cannot leak via timing.
     *
     * @param  string  $binaryPath  Absolute path to the downloaded
     *                              binary on disk.
     * @param  string  $expectedSha512Hex  128-hex-char SHA512 digest
     *                                     from the verified manifest.
     */
    public function verifyBinary(string $binaryPath, string $expectedSha512Hex): bool
    {
        if (! is_file($binaryPath)) {
            $this->logger->warning('ElectronUpdateChannel: binary file missing on disk.', [
                'path' => $binaryPath,
            ]);

            return false;
        }

        $actualHex = hash_file('sha512', $binaryPath);
        if ($actualHex === false) {
            return false;
        }

        return hash_equals($expectedSha512Hex, $actualHex);
    }

    /**
     * Whether the running bundle has been on its current version for
     * more than 30 days while a newer release was already available.
     *
     * Short-circuits to `false` whenever `$currentVersion ===
     * $latestVersion` so a user who has already updated is never
     * shown the stale banner.
     */
    public function isStale(string $currentVersion, string $latestVersion, CarbonImmutable $latestPublishedAt): bool
    {
        if ($currentVersion === $latestVersion) {
            return false;
        }

        $daysSincePublished = $this->clock->now()->diffInDays($latestPublishedAt, absolute: true);

        return $daysSincePublished > self::STALE_THRESHOLD_DAYS;
    }

    /**
     * Idempotency guard for `poll()` — true if the system_alerts
     * table already carries an un-acknowledged `update.available` row
     * naming the given `latestVersion`. Without this guard, every
     * poll cycle would insert a duplicate row for the same release.
     */
    private function hasUnacknowledgedAvailabilityRow(string $latestVersion): bool
    {
        return $this->db->connection()->table('system_alerts')
            ->where('kind', 'update.available')
            ->whereNull('acknowledged_at')
            ->where('metadata', 'like', '%"latestVersion":"'.$latestVersion.'"%')
            ->exists();
    }
}
