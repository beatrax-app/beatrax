<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

/**
 * Public contract that ElectronUpdateChannel calls into when it needs
 * the raw `latest.yml` body + its detached Ed25519 signature for a
 * given channel name. Production implementations resolve the bundle's
 * embedded electron-updater runtime; tests bind a fake that returns a
 * static manifest+signature pair so the verification path can be
 * exercised without any network or NativePHP runtime.
 *
 * The fetcher returns an associative array carrying the four fields
 * the channel needs to verify, record, and surface the available
 * update; `null` signals "no manifest is available right now" (the
 * publisher returned a 404, the channel name does not resolve, the
 * runtime is offline, etc.). The channel treats `null` and a
 * signature-verification failure equivalently — neither raises a
 * banner.
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
    /**
     * Fetches the latest manifest body + detached signature for the
     * given channel name (`stable` → `latest.yml`, `preview` →
     * `beta.yml`).
     *
     * @return FetchedManifest|null
     */
    public function fetch(string $channel): ?array;
}
