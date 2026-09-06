<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The map an OpLogReplayer is constructed with is the whole admission gate for
// a peer's entries: an entry whose signing device is absent from it is
// quarantined and never written. DeviceRegistryService offers three maps that
// read almost identically at a glance, and only one of them is dangerous.
//
// deviceKeys() is paired-and-confirmed only. signatureVerificationKeys() is
// that map widened by the introductions THIS READER confirmed, which grant
// signature verification and nothing else -- so it is an admission anchor too,
// and the second rule below is what makes saying so safe rather than a hole:
// it reads the widening back and refuses it if it ever becomes anything but
// confirmed introductions.
//
// retainedDeviceKeys() answers for devices whose trust has been REVOKED, so
// that the history they wrote can still be read back. Handing that one to a
// replayer admits a revoked device's new writes as ordinary traffic, and no
// reader ever decided that. It is the one that stays out.

// The two shipped roots hold 6,688 PHP files with the suite left out, and the
// floor sits far under that: a walk that opened none of them finds no replayer
// and reports the same clean tree a correct one does.
const CONFIRMED_KEY_SOURCE_FLOOR = 1_000;

const CONFIRMED_KEY_REPLAY_SITES = [
    'Modules/Mobile/Internal/Sync/LanSyncClient.php',
    // The rebuild command replays this device's own stored log rather than
    // anything arriving from a peer, and it still reads the confirmed-only map:
    // an entry signed by a device this installation never confirmed is exactly
    // the one a rebuild must refuse rather than admit by re-reading it.
    'Modules/Sync/Commands/SyncRebuildCommand.php',
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
    expect(count($sources))->toBeGreaterThan(
        CONFIRMED_KEY_SOURCE_FLOOR,
        'The walk opened '.count($sources).' files of the two shipped roots, so a clean answer here is a walk '
        .'that read almost nothing.'
    );

    $sites = [];
    $wide = [];

    foreach ($sources as $path) {
        $code = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        if (! PatternScan::matches('/new OpLogReplayer\(/', $code)) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $path);
        $sites[] = $relative;

        $anchored = PatternScan::matches('/->deviceKeys\(/', $code)
            || PatternScan::matches('/->signatureVerificationKeys\(/', $code);

        if (! $anchored || PatternScan::matches('/->retainedDeviceKeys\(/', $code)) {
            $wide[] = $relative;
        }
    }

    expect($sites)->toBe(CONFIRMED_KEY_REPLAY_SITES, implode("\n  ", [
        'A new place that builds a replayer is a new place a peer is admitted from.',
        'Add it above once its key map is the confirmed-only one. The tree reads: ',
        implode(', ', $sites),
    ]));

    expect($wide)->toBe([], implode("\n  ", [
        'A replayer is admitted on deviceKeys() or on signatureVerificationKeys(), and on nothing',
        'else. retainedDeviceKeys() answers for revoked devices too, and exists only so a rebuild',
        'can verify the history a removed device already wrote; a replayer built from it accepts',
        'that device new entries as well, which no reader ever asked for. Offenders: ',
        implode(', ', $wide),
    ]));
});

// The second anchor, read back rather than taken on trust. Accepting a wider
// map at a replay site is only safe while the widening is exactly the one the
// reader performs by hand, on a key that verifies signatures and grants nothing
// else. If that method ever draws from somewhere else, this is where it shows.
it('widens the second admission anchor only by an introduction the reader confirmed', function (): void {
    $path = base_path('Modules/Sync/Public/Services/DeviceRegistryService.php');
    $code = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

    $at = strpos($code, 'function signatureVerificationKeys(');

    expect($at)->not->toBeFalse('the second anchor must still be here to be anchored');

    $next = strpos($code, 'public function ', (int) $at + 1);
    $body = substr($code, (int) $at, ($next === false ? strlen($code) : $next) - (int) $at);

    expect(PatternScan::matches("/->table\(\s*'device_introductions'\s*\)/", $body))->toBeTrue(
        'the widening must come from the introductions table and nowhere else',
    );

    expect(PatternScan::matches("/->whereNotNull\(\s*'verification_confirmed_at'\s*\)/", $body))->toBeTrue(
        'an introduction the reader has not confirmed verifies nothing. Dropping this filter would '
        .'admit a key on a peer\'s say-so alone, which is the ceremony this act replaces, not skips',
    );

    expect(PatternScan::matches("/->where\(\s*'user_id'\s*,/", $body))->toBeTrue(
        'one household confirming an introduction says nothing about another',
    );

    expect(PatternScan::matches('/->deviceKeys\(/', $body))->toBeTrue(
        'the paired half has to still be in it, or confirming an introduction would REPLACE the '
        .'devices this household paired with rather than add to them',
    );

    expect(PatternScan::matches("/->whereNotIn\(\s*'device_id'/", $body))->toBeTrue(
        'a device the registry holds a row for is pairing\'s to answer. Without this exclusion an '
        .'introduction confirmed BEFORE that device paired outlives the removal the reader later '
        .'performed, and a revoked device goes on verifying through the weaker door',
    );

    expect(PatternScan::matches("/->from\(\s*'device_registry'\s*\)/", $body))->toBeTrue(
        'the exclusion has to be every registry row, not the confirmed ones: a revoked row is exactly '
        .'the one an introduction must not be allowed to shadow',
    );

    expect(PatternScan::matches('/retainedDeviceKeys|(?<!verification_)confirmed_at/', $body))->toBeFalse(
        'the revoked-device map and device_registry.confirmed_at are the paired half\'s business. '
        .'Reaching for either here would fold a revoked device back in through the weaker door',
    );
});
