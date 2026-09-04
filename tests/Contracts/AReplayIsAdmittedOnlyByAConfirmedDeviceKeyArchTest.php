<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The map an OpLogReplayer is constructed with is the whole admission gate for
// a peer's entries: an entry whose signing device is absent from it is
// quarantined and never written. DeviceRegistryService offers two maps that
// differ by one WHERE clause -- deviceKeys() is confirmed-only, and
// retainedDeviceKeys() also answers for devices whose trust has been revoked,
// so that the history they wrote can still be read back. Handing the wider one
// to a replayer would admit a revoked device's new writes as ordinary traffic,
// and the two calls read almost identically at a glance.

const CONFIRMED_KEY_REPLAY_SITES = [
    'Modules/Mobile/Internal/Sync/LanSyncClient.php',
    'Modules/Sync/Internal/Transport/SyncWebSocketHandler.php',
    'Modules/Sync/Providers/SyncServiceProvider.php',
    'Modules/Sync/Public/Services/HistoryReprojector.php',
];

/** @return list<string> every PHP file the shells ship, tests excluded */
function confirmedKeyScannedSources(): array
{
    $found = [];

    foreach (['app', 'Modules'] as $directory) {
        $root = base_path($directory);

        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            // Tests build a replayer over a throwaway map of their own, which
            // is how a hostile peer is modelled at all.
            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

it('builds every shipped replayer from the confirmed-only device-key map', function (): void {
    $sources = confirmedKeyScannedSources();

    // Counted first: a walk that resolved nothing would report that no replayer
    // is built from the wider map, which is what a correct tree also reports.
    expect($sources)->not->toBeEmpty();

    $sites = [];
    $wide = [];

    foreach ($sources as $path) {
        $code = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        if (! PatternScan::matches('/new OpLogReplayer\(/', $code)) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $path);
        $sites[] = $relative;

        if (! PatternScan::matches('/->deviceKeys\(/', $code) || PatternScan::matches('/->retainedDeviceKeys\(/', $code)) {
            $wide[] = $relative;
        }
    }

    expect($sites)->toBe(CONFIRMED_KEY_REPLAY_SITES, implode("\n  ", [
        'A new place that builds a replayer is a new place a peer is admitted from.',
        'Add it above once its key map is the confirmed-only one. The tree reads: ',
        implode(', ', $sites),
    ]));

    expect($wide)->toBe([], implode("\n  ", [
        'DeviceRegistryService::deviceKeys() is the admission anchor because it filters on',
        'confirmed_at; retainedDeviceKeys() answers for revoked devices too, and exists only',
        'so a rebuild can verify the history a removed device already wrote. A replayer built',
        'from the wider map accepts that device new entries as well. Offenders: ',
        implode(', ', $wide),
    ]));
});
