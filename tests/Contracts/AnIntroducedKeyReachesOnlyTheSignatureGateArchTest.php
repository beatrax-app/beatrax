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

// Every call site of the widened author set. It answers what a device may CARRY
// for a peer — registry rows and confirmed introductions alike — and carries no
// key. A second caller is the courier set and the vouching set being conflated,
// which is the one edit this whole boundary is shaped to refuse.
const CARRIED_AUTHOR_CALL_SITES = [
    'Modules/Sync/Internal/Transport/IntroductionOffers.php',
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

// One method's source, ending at its own four-space closing brace rather than at
// the next declaration: a body that ran on to the following method would pass a
// scan for what it must not contain by borrowing the neighbour's text.
function introducedKeyBodyIn(string $source, string $method): string
{
    $at = strpos($source, 'function '.$method.'(');

    if ($at === false) {
        return '';
    }

    $end = strpos($source, "\n    }\n", $at);

    return substr($source, $at, ($end === false ? strlen($source) : $end) - $at);
}

function introducedKeyBodyOf(string $relativePath, string $method): string
{
    return introducedKeyBodyIn(introducedKeyStripped($relativePath), $method);
}

it('lets nothing but the registry and its own service read a relayed key', function (): void {
    $sources = introducedKeySources();

    expect($sources)->not->toBeEmpty('The walk read no production file at all, so the reader list below would be empty whatever the tree holds.');

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
    foreach (['deviceKeys', 'deviceX25519Keys'] as $method) {
        $body = introducedKeyBodyOf('Modules/Sync/Public/Services/DeviceRegistryService.php', $method);

        expect($body)->not->toBe('', $method.'() must still be here to be anchored');

        expect(str_contains($body, 'device_introductions'))->toBeFalse(
            $method.'() is what a Noise handshake and a GDK epoch wrap are judged against. Reading an '
            .'introduced key here would let one confirmed device admit another to the transport and to '
            .'key material, which is exactly the boundary an introduction must not cross',
        );
    }
});

it('offers a relayed identity no transport key to carry', function (): void {
    $migrations = (array) glob(base_path('Modules/Sync/Database/Migrations/*introduce*.php'));

    expect($migrations)->toHaveCount(1, 'One migration declares the introductions table. Finding none means this rule read nothing; '
        .'finding two means the shape is declared twice and the column check below covers one of them.');

    $source = (string) file_get_contents((string) $migrations[0]);

    expect(str_contains($source, 'x25519'))->toBeFalse(
        'an introduction carries the signing half only. Without a column for it, a widened query cannot '
        .'produce a Noise static key for an introduced device even by mistake',
    );
});

it('lets one place ask which authors a device may carry ops for', function (): void {
    $callers = [];

    foreach (introducedKeySources() as $path) {
        if (str_contains(introducedKeyStripped($path), '->authorIdsWithAKeyOnFile(')) {
            $callers[] = $path;
        }
    }

    sort($callers);
    $expected = CARRIED_AUTHOR_CALL_SITES;
    sort($expected);

    expect($callers)->toBe($expected, 'The set a device may carry signed ops for is wider than the set it may '
        .'vouch from, and the two answer different questions about the same author. A second caller is where '
        .'they get conflated, and the conflation is a chain of vouches laundering trust');
});

it('gives the courier device ids and no key to compose an identity from', function (): void {
    $body = introducedKeyBodyOf('Modules/Sync/Public/Services/DeviceRegistryService.php', 'authorIdsWithAKeyOnFile');

    expect($body)->not->toBe('', 'authorIdsWithAKeyOnFile() must still be here to be anchored')
        ->and(str_contains($body, 'device_introductions'))->toBeTrue(
            'an author reachable only through a confirmed introduction is one this reader can verify, and '
            .'withholding its ops from a third device that can verify it too is the gap this set closes',
        )
        ->and(str_contains($body, 'public_key'))->toBeFalse(
            'a relay is a courier, not an authority. Selecting a key here would put the material an '
            .'introduction offer is built from into the one set that spans both doors, and a vouch composed '
            .'from it would be this device vouching on the strength of somebody else\'s vouch',
        );
});

it('reads one method body without borrowing the next one', function (): void {
    $source = <<<'PHP'
        final class PlantedRegistry
        {
            public function deviceKeys(): array
            {
                return $this->rows('device_registry');
            }

            public function authorIdsWithAKeyOnFile(): array
            {
                return $this->rows('device_introductions');
            }
        }
        PHP;

    expect(str_contains(introducedKeyBodyIn($source, 'deviceKeys'), 'device_introductions'))
        ->toBeFalse('the anchored method is the paired-only one, and a body running on to its neighbour would read as introducing keys');

    expect(str_contains(introducedKeyBodyIn($source, 'authorIdsWithAKeyOnFile'), 'device_introductions'))
        ->toBeTrue('the courier set does read the introductions table, and a reader that found nothing would pass both halves');

    expect(introducedKeyBodyIn($source, 'aMethodThatIsNotThere'))
        ->toBe('', 'a method that is gone reads as an empty body, which every case above asserts against before reading it');
});

it('composes an introduction offer from the paired-only map alone', function (): void {
    $body = introducedKeyBodyOf('Modules/Sync/Internal/Transport/IntroductionOffers.php', 'introductionsFor');

    expect($body)->not->toBe('', 'introductionsFor() must still be here to be anchored')
        ->and(str_contains($body, '->deviceKeys('))->toBeTrue(
            'only a device this install confirmed through a two-party ceremony may be vouched for',
        );

    foreach (['->signatureVerificationKeys(', '->authorIdsWithAKeyOnFile(', '->carriedAuthorsFor('] as $wider) {
        expect(str_contains($body, $wider))->toBeFalse(
            'relaying signed DATA onward grants nobody anything, because the reader checks it against a key '
            .'it confirmed itself. Relaying the IDENTITY onward from '.$wider.' would let this device vouch '
            .'for an author it knows only because a peer vouched for it, which is the one hop that launders',
        );
    }
});
