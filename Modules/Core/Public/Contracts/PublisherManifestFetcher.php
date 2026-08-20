<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

/**
 * @phpstan-type FetchedManifest array{
 *     body: string,
 *     signature: string,
 *     latest_version: string,
 *     sha512_hex: string,
 *     published_at: \Carbon\CarbonImmutable
 * }
 */
interface PublisherManifestFetcher
{
    // Null when no manifest is available (404, offline, unknown channel) —
    // the caller treats that identically to a signature-verification failure.
    /**
     * @return FetchedManifest|null
     */
    public function fetch(string $channel): ?array;
}
