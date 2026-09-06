<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

use Modules\Core\Public\Enums\UpdateChannel;

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
    // Null when no manifest is available — offline, no feed configured, or a
    // channel whose manifest this release did not publish. The caller treats
    // all of them as it treats a signature that does not verify: silence.
    /**
     * @return FetchedManifest|null
     */
    public function fetch(UpdateChannel $channel): ?array;
}
