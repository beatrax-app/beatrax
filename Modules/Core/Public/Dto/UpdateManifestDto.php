<?php

declare(strict_types=1);

namespace Modules\Core\Public\Dto;

use Carbon\CarbonImmutable;

/**
 * Verified update manifest descriptor — the value
 * `ElectronUpdateChannel::poll()` returns once a fetched manifest has
 * passed Ed25519 signature verification AND its declared binary hash
 * is ready for the SHA512 download check.
 *
 * Every field originates from inside the (now-trusted) manifest body;
 * the DTO carries the values forward to whoever raises the
 * `update.available` / `update.stale` / `update.critical`
 * system_alerts row that the SystemAlertsBanner surfaces.
 */
final readonly class UpdateManifestDto
{
    public function __construct(
        public string $latestVersion,
        public string $sha512Hex,
        public CarbonImmutable $publishedAt,
        public string $channel,
    ) {}
}
