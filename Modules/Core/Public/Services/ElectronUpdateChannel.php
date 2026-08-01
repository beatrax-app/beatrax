<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * @phpstan-import-type FetchedManifest from PublisherManifestFetcher
 *
 * @link ../../../../.docs/features/core/architecture.md
 */
final readonly class ElectronUpdateChannel
{
    private const STALE_THRESHOLD_DAYS = 30;

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private Clock $clock,
        private Repository $config,
    ) {}

    // `stable` resolves the `latest.yml` manifest electron-updater writes for
    // `v*.*.*` tags; `preview` resolves the `beta.yml` manifest it writes for
    // `v*-rc.*` tags.
    public function channel(): string
    {
        $value = $this->config->get('auto_update.update_channel', 'stable');

        return is_string($value) ? $value : 'stable';
    }

    // Null on every silent-failure path: nothing was fetched (offline / 404),
    // the manifest was not signed by us, or this version is already sitting
    // unacknowledged. They are one answer to the caller — nothing to show.
    public function poll(PublisherManifestFetcher $fetcher): ?UpdateManifestDto
    {
        $manifest = $fetcher->fetch($this->channel());
        if ($manifest === null || ! $this->isWorthSurfacing($manifest)) {
            return null;
        }

        return new UpdateManifestDto(
            latestVersion: $manifest['latest_version'],
            sha512Hex: $manifest['sha512_hex'],
            publishedAt: $manifest['published_at'],
            channel: $this->channel(),
        );
    }

    // Separated from poll() so each rejection stays distinguishable in the
    // log even though the caller cannot tell them apart: an unsigned manifest
    // is a tampering signal worth seeing, while an already-announced version
    // is routine and stays quiet.
    /**
     * @param  FetchedManifest  $manifest
     */
    private function isWorthSurfacing(array $manifest): bool
    {
        if (! $this->verifyManifest($manifest['body'], $manifest['signature'])) {
            $this->logger->warning('ElectronUpdateChannel: rejected manifest with invalid Ed25519 signature.', [
                'channel' => $this->channel(),
                'latest_version' => $manifest['latest_version'],
            ]);

            return false;
        }

        return ! $this->hasUnacknowledgedAvailabilityRow($manifest['latest_version']);
    }

    // Returns true iff sodium_crypto_sign_verify_detached succeeds; false on
    // every failure mode (tampered body/signature, malformed lengths). No
    // exception ever reaches callers — a failed verification is an in-band
    // signal the poll loop logs at WARNING and suppresses via null from poll().
    /**
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
        // Checked before the configuration is read, so a caller passing no
        // signature never provokes the missing-key warning about an install
        // that may be perfectly fine.
        if ($detachedSignature === '') {
            return false;
        }

        $publicKeyHex = $this->configuredPublisherKeyHex();

        return $publicKeyHex !== null
            && $this->verifiesUnder($publicKeyHex, $manifestBody, $detachedSignature);
    }

    // The two libsodium calls sit together because either can reject
    // malformed input and the caller cannot act differently on which did.
    /**
     * @param  non-empty-string  $detachedSignature
     */
    private function verifiesUnder(string $publicKeyHex, string $manifestBody, string $detachedSignature): bool
    {
        try {
            $publicKey = sodium_hex2bin($publicKeyHex);

            // An empty key verifies nothing, so it is refused rather than
            // handed to libsodium as an argument.
            return $publicKey !== '' && sodium_crypto_sign_verify_detached(
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

    // Null when no publisher key is configured. Warned about here rather than
    // at the call site because an unconfigured key is an install problem,
    // while a signature that will not verify is a manifest problem.
    private function configuredPublisherKeyHex(): ?string
    {
        $publicKeyHex = $this->config->get('auto_update.publisher_public_key_hex');
        if (! is_string($publicKeyHex) || $publicKeyHex === '') {
            $this->logger->warning('ElectronUpdateChannel: missing or invalid publisher public key configuration.');

            return null;
        }

        return $publicKeyHex;
    }

    // Comparison uses hash_equals for a constant-time check so a partial-byte
    // match cannot leak via timing.
    /**
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

    // Short-circuits to false whenever $currentVersion === $latestVersion so
    // a user who has already updated is never shown the stale banner.
    public function isStale(string $currentVersion, string $latestVersion, CarbonImmutable $latestPublishedAt): bool
    {
        if ($currentVersion === $latestVersion) {
            return false;
        }

        $daysSincePublished = $this->clock->now()->diffInDays($latestPublishedAt, absolute: true);

        return $daysSincePublished > self::STALE_THRESHOLD_DAYS;
    }

    // Idempotency guard for poll() — without it, every poll cycle would
    // insert a duplicate system_alerts row for the same release.
    private function hasUnacknowledgedAvailabilityRow(string $latestVersion): bool
    {
        return $this->db->connection()->table('system_alerts')
            ->where('kind', UpdateAlertKind::Available->value)
            ->whereNull('acknowledged_at')
            ->where('metadata', 'like', '%"latestVersion":"'.$latestVersion.'"%')
            ->exists();
    }
}
