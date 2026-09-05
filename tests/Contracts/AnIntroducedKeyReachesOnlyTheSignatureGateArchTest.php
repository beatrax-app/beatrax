<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A key a peer relayed and the reader confirmed grants signature verification
// and nothing else. That is not a thing to remember: this pins the two doors it
// may pass through, so widening either one fails the build rather than quietly
// admitting a device nobody paired with to a session or an epoch fan-out.

// Where an introduced key may be read at all. The registry is the one map that
// composes it; the service owns the table's lifecycle. Anything else naming the
// table is a third reader, and a third reader is a second trust root.
const INTRODUCED_KEY_READERS = [
    'Modules/Sync/Internal/Pairing/DeviceIntroductionService.php',
    'Modules/Sync/Public/Services/DeviceRegistryService.php',
];

// Every call site of the one map that carries an introduced key. Each builds
// the author map an op-log replay verifies against, or the list of authors this
// device advertises it can verify — which must be the same set or the device
// asks for ops it will refuse.
const INTRODUCED_KEY_CALL_SITES = [
    'Modules/Sync/Providers/SyncServiceProvider.php',
    'Modules/Sync/Internal/Transport/SyncWebSocketHandler.php',
    'Modules/Sync/Internal/Transport/IntroductionOffers.php',
    'Modules/Sync/Public/Services/HistoryReprojector.php',
    'Modules/Mobile/Internal/Sync/LanSyncClient.php',
];

/** @return list<string> every production PHP file the shells ship */
function introducedKeySources(): array
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

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }

            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }

            $found[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($found);

    return $found;
}

// Comments name the table on purpose — the whole point of several of them is to
// say which map does NOT reach it — so they are dropped before anything is read.
function introducedKeyStripped(string $relativePath): string
{
    return PatternScan::replace(
        '#/\*.*?\*/|//[^\n]*#s',
        '',
        (string) file_get_contents(base_path($relativePath)),
    );
}

it('lets nothing but the registry and its own service read a relayed key', function (): void {
    $sources = introducedKeySources();

    expect($sources)->not->toBeEmpty();

    $readers = [];

    foreach ($sources as $path) {
        if (str_contains(introducedKeyStripped($path), 'device_introductions')) {
            $readers[] = $path;
        }
    }

    sort($readers);

    expect($readers)->toBe(INTRODUCED_KEY_READERS, 'a fourth reader of device_introductions is a second trust '
        .'root: the table exists so that no query over device_registry — the table every confirmed-only, '
        .'transport-admitting and epoch-delivering lookup reads — can return a key nobody paired with');
});

it('hands the map that carries a relayed key only to an op-log signature check', function (): void {
    $callers = [];

    foreach (introducedKeySources() as $path) {
        if (str_contains(introducedKeyStripped($path), '->signatureVerificationKeys(')) {
            $callers[] = $path;
        }
    }

    sort($callers);
    $expected = INTRODUCED_KEY_CALL_SITES;
    sort($expected);

    expect($callers)->toBe($expected, 'A confirmed introduction grants signature verification only. '
        .'Every call site here builds OpLogReplayer\'s author map or the list of authors this device advertises '
        .'it can verify. A new one is a new capability being granted to a device nobody paired with');
});

it('keeps the transport and epoch anchors reading the paired-only registry', function (): void {
    $stripped = introducedKeyStripped('Modules/Sync/Public/Services/DeviceRegistryService.php');

    foreach (['deviceKeys', 'deviceX25519Keys'] as $method) {
        $at = strpos($stripped, 'function '.$method.'(');

        expect($at)->not->toBeFalse($method.'() must still be here to be anchored');

        $next = strpos($stripped, 'public function ', (int) $at + 1);
        $body = substr($stripped, (int) $at, ($next === false ? strlen($stripped) : $next) - (int) $at);

        expect(str_contains($body, 'device_introductions'))->toBeFalse(
            $method.'() is what a Noise handshake and a GDK epoch wrap are judged against. Reading an '
            .'introduced key here would let one confirmed device admit another to the transport and to '
            .'key material, which is exactly the boundary an introduction must not cross',
        );
    }
});

it('offers a relayed identity no transport key to carry', function (): void {
    $migrations = (array) glob(base_path('Modules/Sync/Database/Migrations/*introduce*.php'));

    expect($migrations)->toHaveCount(1);

    $source = (string) file_get_contents((string) $migrations[0]);

    expect(str_contains($source, 'x25519'))->toBeFalse(
        'an introduction carries the signing half only. Without a column for it, a widened query cannot '
        .'produce a Noise static key for an introduced device even by mistake',
    );
});
