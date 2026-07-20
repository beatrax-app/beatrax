<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

/**
 * @link ../../../../.docs/features/core/architecture.md
 *
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
    // `stable` resolves `latest.yml`; `preview` resolves `beta.yml`. Returns
    // null when no manifest is available (404, offline, unknown channel) —
    // treated identically to a signature-verification failure by the caller.
    /**
     * @return FetchedManifest|null
     */
    public function fetch(string $channel): ?array;
}
