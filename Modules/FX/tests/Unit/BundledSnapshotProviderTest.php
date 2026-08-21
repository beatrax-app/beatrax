<?php

declare(strict_types=1);

use Modules\FX\Internal\Providers\BundledSnapshotProvider;
use Modules\FX\Public\Exceptions\RateFetchException;

// The last provider in the fallback chain, so it reads the snapshot that ships
// in the bundle. Every other FX test drives a fake provider, which leaves the
// shipped file itself unasserted — and a corrupt one only surfaces when the
// two online providers are already down.
it('reads the shipped snapshot as the last-resort provider', function (): void {
    $provider = new BundledSnapshotProvider;

    expect($provider->key())->toBe('bundled')
        ->and($provider->priority())->toBe(0);

    $snapshot = $provider->fetch();

    expect($snapshot['date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
        ->and($snapshot['rates'])->toHaveKeys(['USD', 'GBP'])
        ->and(count($snapshot['rates']))->toBeGreaterThanOrEqual(30)
        ->and((float) $snapshot['rates']['USD'])->toBeGreaterThan(0.5)
        ->and((float) $snapshot['rates']['USD'])->toBeLessThan(2.0);
});

// RateProviderRegistry catches only RateFetchException, so a malformed file
// escaping as a JsonException would take the whole fallback chain with it.
it('reports a missing snapshot as a rate-fetch failure', function (): void {
    $provider = new BundledSnapshotProvider(sys_get_temp_dir().'/beatrax-no-such-snapshot.json');

    expect(fn (): array => $provider->fetch())->toThrow(RateFetchException::class);
});
