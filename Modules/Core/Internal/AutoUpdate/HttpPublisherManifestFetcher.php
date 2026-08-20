<?php

declare(strict_types=1);

namespace Modules\Core\Internal\AutoUpdate;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory as HttpClient;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md
 */
final readonly class HttpPublisherManifestFetcher implements PublisherManifestFetcher
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpClient $http,
        private Repository $config,
        private LoggerInterface $logger,
        // Defaults to the live platform; injected in tests so each platform's
        // manifest name can be asserted without mocking a constant.
        private string $platformFamily = PHP_OS_FAMILY,
    ) {}

    public function fetch(string $channel): ?array
    {
        $base = $this->config->get('auto_update.manifest_feed_url');
        if (! is_string($base) || $base === '') {
            return null;
        }

        $manifestUrl = rtrim($base, '/').'/'.$this->manifestName($channel);

        try {
            return $this->fetchAndParse($manifestUrl);
        } catch (Throwable $e) {
            // Offline, DNS failure, malformed manifest, unparseable date: one
            // answer to the caller, and never a throw into the update flow.
            $this->logger->warning('HttpPublisherManifestFetcher: manifest fetch failed.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // Fetching another platform's manifest would check the binary against a
    // different OS's digest, so a real update would never install.
    private function manifestName(string $channel): string
    {
        $base = $channel === 'stable' ? 'latest' : 'beta';

        $suffix = match ($this->platformFamily) {
            'Darwin' => '-mac',
            'Linux' => '-linux',
            default => '',
        };

        return $base.$suffix.'.yml';
    }

    /**
     * @return array{body: string, signature: string, latest_version: string, sha512_hex: string, published_at: CarbonImmutable}|null
     */
    private function fetchAndParse(string $manifestUrl): ?array
    {
        $manifest = $this->http->createPendingRequest()->timeout(self::TIMEOUT_SECONDS)->get($manifestUrl);
        $signature = $this->http->createPendingRequest()->timeout(self::TIMEOUT_SECONDS)->get($manifestUrl.'.sig');

        if (! $manifest->successful() || ! $signature->successful()) {
            return null;
        }

        $signatureBytes = $this->decodeHexSignature(trim($signature->body()));
        if ($signatureBytes === null) {
            return null;
        }

        $body = $manifest->body();
        $parsed = $this->parseManifestBody($body);
        if ($parsed === null) {
            return null;
        }

        return [
            'body' => $body,
            'signature' => $signatureBytes,
            'latest_version' => $parsed['version'],
            'sha512_hex' => $parsed['sha512_hex'],
            'published_at' => $parsed['published_at'],
        ];
    }

    // The `.sig` sibling is what sodium_bin2hex produced at signing time, so a
    // non-hex or odd-length body is a corrupt signature, not hex2bin input.
    private function decodeHexSignature(string $hex): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }

        $binary = sodium_hex2bin($hex);

        return $binary === '' ? null : $binary;
    }

    // The manifest carries the digest as base64; verifyBinary() compares hex.
    // A decode that is not exactly 64 bytes is not a SHA512 digest, so the whole
    // manifest drops rather than reaching the binary check as a bad expectation.
    /**
     * @return array{version: string, sha512_hex: string, published_at: CarbonImmutable}|null
     */
    private function parseManifestBody(string $body): ?array
    {
        $data = Yaml::parse($body);
        if (! is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;
        $sha512Base64 = $data['sha512'] ?? null;
        $releaseDate = $data['releaseDate'] ?? null;
        if (! is_string($version) || ! is_string($sha512Base64) || ! is_string($releaseDate)) {
            return null;
        }

        $sha512Binary = base64_decode($sha512Base64, true);
        if ($sha512Binary === false || strlen($sha512Binary) !== 64) {
            return null;
        }

        return [
            'version' => $version,
            'sha512_hex' => bin2hex($sha512Binary),
            'published_at' => CarbonImmutable::parse($releaseDate),
        ];
    }
}
