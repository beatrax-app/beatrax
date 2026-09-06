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
use Modules\Core\Public\Enums\UpdateChannel;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md
 *
 * @phpstan-import-type FetchedManifest from PublisherManifestFetcher
 */
final readonly class ElectronUpdateChannel
{
    private const int STALE_THRESHOLD_DAYS = 30;

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
        private Clock $clock,
        private Repository $config,
        private UpdateChannelPreference $channels,
    ) {}

    public function channel(): UpdateChannel
    {
        return $this->channels->channel();
    }

    // Null on every silent-failure path — offline, unsigned, or already
    // announced — because they are one answer to the caller: nothing to show.
    public function poll(PublisherManifestFetcher $fetcher): ?UpdateManifestDto
    {
        // Read once: the answer is a row, and the fetch, the log line and the
        // DTO must all name the channel that was asked for even if the reader
        // changes it mid-poll.
        $channel = $this->channel();

        $manifest = $fetcher->fetch($channel);
        if ($manifest === null || ! $this->isWorthSurfacing($manifest, $channel)) {
            return null;
        }

        return new UpdateManifestDto(
            latestVersion: $manifest['latest_version'],
            sha512Hex: $manifest['sha512_hex'],
            publishedAt: $manifest['published_at'],
            channel: $channel,
        );
    }

    // Split from poll() so the log can distinguish rejections the caller
    // cannot: an unsigned manifest is a tampering signal, a repeat is routine.
    /**
     * @param  FetchedManifest  $manifest
     */
    private function isWorthSurfacing(array $manifest, UpdateChannel $channel): bool
    {
        if (! $this->verifyManifest($manifest['body'], $manifest['signature'])) {
            $this->logger->warning('ElectronUpdateChannel: rejected manifest with invalid Ed25519 signature.', [
                'channel' => $channel->value,
                'latest_version' => $manifest['latest_version'],
            ]);

            return false;
        }

        return ! $this->hasUnacknowledgedAvailabilityRow($manifest['latest_version']);
    }

    // No exception ever reaches callers: every failure mode — tampered body,
    // malformed lengths — comes back as false.
    /**
     * @param  string  $manifestBody  Raw signed manifest bytes, not a decoded value.
     * @param  string  $detachedSignature  Binary 64-byte Ed25519 signature.
     */
    public function verifyManifest(string $manifestBody, string $detachedSignature): bool
    {
        // Before the config read, so an empty signature never provokes the
        // missing-key warning about an install that may be perfectly fine.
        if ($detachedSignature === '') {
            return false;
        }

        $publicKeyHex = $this->configuredPublisherKeyHex();

        return $publicKeyHex !== null
            && $this->verifiesUnder($publicKeyHex, $manifestBody, $detachedSignature);
    }

    /**
     * @param  non-empty-string  $detachedSignature
     */
    private function verifiesUnder(string $publicKeyHex, string $manifestBody, string $detachedSignature): bool
    {
        try {
            $publicKey = sodium_hex2bin($publicKeyHex);

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

    // Warned about here, not at the call site: an unconfigured key is an
    // install problem, an unverifiable signature is a manifest problem.
    private function configuredPublisherKeyHex(): ?string
    {
        $publicKeyHex = $this->config->get('auto_update.publisher_public_key_hex');
        if (! is_string($publicKeyHex) || $publicKeyHex === '') {
            $this->logger->warning('ElectronUpdateChannel: missing or invalid publisher public key configuration.');

            return null;
        }

        return $publicKeyHex;
    }

    /**
     * @param  string  $binaryPath  Absolute path to the downloaded binary.
     * @param  string  $expectedSha512Hex  128-hex-char SHA512 from the verified manifest.
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

    public function isStale(string $currentVersion, string $latestVersion, CarbonImmutable $latestPublishedAt): bool
    {
        if ($currentVersion === $latestVersion) {
            return false;
        }

        $daysSincePublished = $this->clock->now()->diffInDays($latestPublishedAt, absolute: true);

        return $daysSincePublished > self::STALE_THRESHOLD_DAYS;
    }

    // The running bundle's own version, which isStale() measures the offered
    // release against. Absent outside the desktop bundle, where the whole
    // update path is already switched off by an unset feed URL.
    public function installedVersion(): string
    {
        $value = $this->config->get('nativephp.version');

        return is_string($value) ? $value : '';
    }

    // The kind a verified manifest earns. Without this the announcement path
    // could only ever write `update.available`, leaving the `update.stale`
    // banner and its actions unreachable.
    public function alertKindFor(UpdateManifestDto $manifest): UpdateAlertKind
    {
        return $this->isStale($this->installedVersion(), $manifest->latestVersion, $manifest->publishedAt)
            ? UpdateAlertKind::Stale
            : UpdateAlertKind::Available;
    }

    // Idempotency guard for poll() — without it, every poll cycle would
    // insert a duplicate system_alerts row for the same release. Every
    // update kind counts: a stale row already names this version.
    private function hasUnacknowledgedAvailabilityRow(string $latestVersion): bool
    {
        return $this->db->connection()->table('system_alerts')
            ->whereIn('kind', UpdateAlertKind::values())
            ->whereNull('acknowledged_at')
            ->where('metadata', 'like', '%"latestVersion":"'.$latestVersion.'"%')
            ->exists();
    }
}
