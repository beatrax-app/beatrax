<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// There is no account and no server, so a device's own signing and
// key-agreement secrets are the whole of its identity: a copy of one on a
// second machine is the right to write as the first, and revoking afterwards
// takes none of that back. Today nothing copies one — the halves rest in a
// single sealed per-user key file, and what travels is signatures, sealed
// boxes and public halves. What was missing is anything that keeps it so.

const PRIVATE_KEY_HALF_PATTERN = '/(?:ed25519|x25519)SecretKeyHex|(?:ed25519|x25519)_secret_key_hex/i';

// The two shipped roots hold 6,688 PHP files with the suite left out, and the
// floor sits far under that: a walk that opened none of them reports the same
// tree a walk that found no travelling key reports.
const PRIVATE_KEY_SOURCE_FLOOR = 1_000;

// Each entry names a file that reads a private half and what it does with it
// that ends on this machine. The `proves` pattern re-checks the reason: when
// the file stops matching, the exemption has outlived what earned it and this
// fails there rather than waving the site on for another year.
const PRIVATE_KEY_HOLDERS = [
    'Modules/Mobile/Internal/Sync/LanSyncClient.php' => [
        'reason' => 'the local static secret of a Noise handshake; the handshake output crosses, the key does not',
        'proves' => '/NoiseHandshakeState/',
    ],
    'Modules/Sync/Internal/Crypto/GdkEpochControlHandler.php' => [
        'reason' => 'opens a sealed box addressed to this device; the secret is an input to the open, never an output',
        'proves' => '/sodium_crypto_box_seal_open/',
    ],
    'Modules/Sync/Internal/Crypto/GdkRotationService.php' => [
        'reason' => 'signs an epoch wrap and zeroes the raw key in a finally; what the wrap carries is the signature',
        'proves' => '/sodium_memzero\(\$senderSecretBin\)/',
    ],
    'Modules/Sync/Internal/Identity/DeviceIdentityDto.php' => [
        'reason' => 'the shape the sealed key file decrypts to, and the one place both halves are named together',
        'proves' => '/final readonly class DeviceIdentityDto/',
    ],
    'Modules/Sync/Internal/Identity/DeviceIdentityService.php' => [
        'reason' => 'mints the pair and seals it straight into the key file under the app-lock key',
        'proves' => '/sealedFile->writeSealed\(/',
    ],
    'Modules/Sync/Internal/OpLog/OpLogWriterFactory.php' => [
        'reason' => 'hands the signing key to the writer that signs this device own entries',
        'proves' => '/OpLogWriter/',
    ],
    'Modules/Sync/Internal/Pairing/LanPairingFramePuller.php' => [
        'reason' => 'signs the proof this device presents to collect frames waiting for it',
        'proves' => '/PairingFrame::pullProofMessage/',
    ],
    'Modules/Sync/Internal/Pairing/PairingFrameCourier.php' => [
        'reason' => 'signs the confirm frame, which is why a relay that swaps a sealing key fails the peer verify',
        'proves' => '/PairingFrame::confirmSigningMessage/',
    ],
    'Modules/Sync/Public/Services/SyncDaemonIdentity.php' => [
        'reason' => 'hands the transport half to a local child process as environment rather than resting it on disk',
        'proves' => '/ENV_SECRET/',
    ],
];

// Where the sealed key file's path is composed, and the only place it may be:
// four callers each spelled their own copy of it until this existed, and the
// rule below is asked of this file because this is the file that decides.
const PRIVATE_KEY_FILE_LOCATOR = 'Modules/Sync/Internal/Identity/DeviceIdentityFile.php';

// Naming the key file is not locating it. This map says which files an account
// owns so a deletion and an export can walk them, opens nothing, and keys its
// template to the same account id. `proves` re-checks that reason.
const PRIVATE_KEY_PATH_DECLARERS = [
    'Modules/Core/Internal/Storage/UserDataLocations.php' => '#sync/identity/%d\.enc#',
];

// The two ways a device identity comes into being, and the only two there may
// be: minted here, or unsealed from this install own key file.
const PRIVATE_KEY_IDENTITY_SOURCES = [
    'Modules/Sync/Internal/Identity/DeviceIdentityLoader.php' => 'unseal',
    'Modules/Sync/Internal/Identity/DeviceIdentityService.php' => 'mint',
];

/** @return list<string> every PHP file the shells ship, tests excluded, migrations included */
function privateKeyScannedSources(): array
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

            // A test mints throwaway keypairs and names both halves on
            // purpose, and a fixture holds whole identities. Neither ships.
            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $found[] = $path;
        }
    }

    sort($found);

    return $found;
}

function privateKeyRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

// Comments are stripped so a docblock naming the field it forbids is not read
// as a use of it — this file's own prose would otherwise trip the rule.
function privateKeyCode(string $path): string
{
    return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
}

// The DTO carries both secret halves and its toArray() emits them under the
// same names the rule above hunts for — so a caller that hands the WHOLE object
// to a serialiser ships the key while naming no field at all, and the pattern
// above reads the file as clean. One site does it, sealing the key-file to disk.
/** @var array<string, string> path => why it may serialise the identity whole */
const PRIVATE_KEY_SERIALIZERS = [
    'Modules/Sync/Internal/Identity/DeviceIdentityService.php' => 'Seals the key-file this device keeps: the array is encrypted under the app-lock KEK on the next line and never leaves the disk it is written to.',
];

const PRIVATE_KEY_WHOLE_IDENTITY = '/(?:json_encode|serialize|var_export|http_build_query|->toJson)\s*\([^;]*\$\w*(?:identity|dto)\w*\b|\$\w*(?:identity|dto)\w*->toArray\s*\(/i';

it('hands no serialiser a device identity whole', function (): void {
    $offenders = [];
    $seen = 0;

    foreach (privateKeyScannedSources() as $path) {
        $code = privateKeyCode($path);

        if (! str_contains($code, 'DeviceIdentityDto')) {
            continue;
        }

        $seen++;

        if (! PatternScan::matches(PRIVATE_KEY_WHOLE_IDENTITY, $code)) {
            continue;
        }

        $relative = privateKeyRelative($path);

        if (! array_key_exists($relative, PRIVATE_KEY_SERIALIZERS)) {
            $offenders[] = $relative;
        }
    }

    // The denominator: a reader that stopped recognising the DTO would report
    // no serialiser in a tree that has one.
    expect($seen)->toBeGreaterThan(
        4,
        'Only '.$seen.' files were read as naming DeviceIdentityDto, so a clean answer below is the reader and not the tree.',
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'These hand a device identity to a serialiser whole. toArray() emits both secret',
        'halves, so the key travels without the file ever naming a secret field — which is',
        'the one shape the rule below cannot see. Take the public half, or pin it above.',
        'The tree reads: '.implode(', ', $offenders),
    ]));
});

it('keeps the private half of a device identity to the files that spend it here', function (): void {
    $sources = privateKeyScannedSources();

    // Counted first: a walk that resolved nothing would report a tree where no
    // private key travels, which is the answer a clean tree gives.
    expect(count($sources))->toBeGreaterThan(
        PRIVATE_KEY_SOURCE_FLOOR,
        'The walk opened '.count($sources).' files of the two shipped roots, so a clean answer here is a walk '
        .'that read almost nothing.'
    );

    $holders = [];

    foreach ($sources as $path) {
        if (PatternScan::matches(PRIVATE_KEY_HALF_PATTERN, privateKeyCode($path))) {
            $holders[] = privateKeyRelative($path);
        }
    }

    expect($holders)->toBe(array_keys(PRIVATE_KEY_HOLDERS), implode("\n  ", [
        'A device signing or key-agreement secret is the whole of that device identity, and',
        'a second machine holding one can write as the first for as long as the key lives.',
        'Reach for the public half, a signature over the message, or a box sealed to the',
        'peer public key. If a new file genuinely has to spend the secret locally, pin it',
        'above with the reason it never leaves and a pattern that proves the reason.',
        'The tree reads: '.implode(', ', $holders),
    ]));
});

it('still holds each private-key holder to the reason it was granted for', function (): void {
    expect(PRIVATE_KEY_HOLDERS)->not->toBe([], 'The pin map is empty, so this rule proves nothing about it.');

    foreach (PRIVATE_KEY_HOLDERS as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

it('assembles a device identity only by minting one or by opening this install own key file', function (): void {
    $sources = privateKeyScannedSources();

    expect(count($sources))->toBeGreaterThan(
        PRIVATE_KEY_SOURCE_FLOOR,
        'The walk opened '.count($sources).' files of the two shipped roots, so a clean answer here is a walk '
        .'that read almost nothing.'
    );

    $builders = [];

    foreach ($sources as $path) {
        $code = privateKeyCode($path);

        if (PatternScan::matches('/new DeviceIdentityDto\(/', $code)) {
            $builders[privateKeyRelative($path)] = 'mint';
        }

        if (PatternScan::matches('/DeviceIdentityDto::fromArray\(/', $code)) {
            $builders[privateKeyRelative($path)] = 'unseal';
        }
    }

    ksort($builders);

    expect($builders)->toBe(PRIVATE_KEY_IDENTITY_SOURCES, implode("\n  ", [
        'An escrow or a recovery path is a third way to obtain a private half, and it would',
        'have to build one of these. There are two: minting a fresh pair for this device, and',
        'decrypting the key file this install already holds. Anything that assembles an',
        'identity from material that arrived from elsewhere reconstructs a key this device',
        'was never meant to hold. The tree reads: '.json_encode($builders),
    ]));
});

it('keys that file to the account rather than to any peer', function (): void {
    $locator = privateKeyCode(base_path(PRIVATE_KEY_FILE_LOCATOR));

    // One value in the path and nothing else. A path taking a peer device id
    // would be a per-peer key store, which is the shape an escrow takes before
    // anybody calls it one.
    expect($locator)->toMatch(
        '#sprintf\(\x27/%s\.enc\x27, \$userId\)#',
        PRIVATE_KEY_FILE_LOCATOR.' no longer builds the sealed key file path from the account id alone. A path '
        .'taking a peer device id is a per-peer key store, which is an escrow before anybody calls it one.'
    );

    // The format names anything, so it is the signature that fixes what it can
    // name. Both entry points take an account id and no device.
    expect($locator)->toMatch('#function path\(int \$userId\)#')
        ->and($locator)->toMatch('#function exists\(int \$userId\)#');
});

// The rule above reads one file, so it is only worth what "one file" is worth.
// It read the loader until the path moved out of it, and passed over the move
// rather than catching it: a guard pointed at a file that no longer decides the
// thing says nothing about the thing. So the tree is swept for a second builder.
it('builds that path in one place, so the rule above is asked of the whole tree', function (): void {
    $sources = privateKeyScannedSources();

    expect(count($sources))->toBeGreaterThan(
        PRIVATE_KEY_SOURCE_FLOOR,
        'The walk opened '.count($sources).' files of the two shipped roots, so a clean answer here is a walk '
        .'that read almost nothing.'
    );

    $builders = [];

    foreach ($sources as $path) {
        if (PatternScan::matches('#sync/identity#', privateKeyCode($path))) {
            $builders[privateKeyRelative($path)] = true;
        }
    }

    foreach (PRIVATE_KEY_PATH_DECLARERS as $declarer => $proves) {
        expect($builders)->toHaveKey(
            $declarer,
            $declarer.' no longer names the key file at all. It was exempted because it declares where an '
            .'account keeps its files rather than locating one, and an exemption that names nothing is spent.'
        );

        expect(privateKeyCode(base_path($declarer)))->toMatch(
            $proves,
            $declarer.' still names the key file, but no longer in the shape that earned its exemption.'
        );

        unset($builders[$declarer]);
    }

    expect(array_keys($builders))->toBe([PRIVATE_KEY_FILE_LOCATOR], implode("\n  ", [
        'A second file composes the sealed key file path. There is one, and the rule above reads it: put the',
        'path there and call it, or this becomes a rule about whichever copy somebody happened to point at.',
        'The tree reads: '.json_encode(array_keys($builders)),
    ]));
});

// A guard that cannot go red says nothing, and the sweeps above are read off one
// pattern and one stripper. Both are checked against the shapes they were
// written for rather than against the tree.
it('reads a private half, and reads neither a public one nor a comment naming one', function (string $line, bool $holds): void {
    $path = sys_get_temp_dir().'/private-key-'.bin2hex(random_bytes(8)).'.php';

    try {
        file_put_contents($path, "<?php\n".$line."\n");

        expect(PatternScan::matches(PRIVATE_KEY_HALF_PATTERN, privateKeyCode($path)))->toBe(
            $holds,
            'The reader answered '.var_export(! $holds, true).' for a line it has to read as '
            .($holds ? 'a private half' : 'something else').': '.$line
        );
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
})->with([
    'the signing half, camelCase' => ['$key = $identity->ed25519SecretKeyHex;', true],
    'the transport half, snake_case' => ["\$key = \$row['x25519_secret_key_hex'];", true],
    'the same name in a different case' => ['$key = $identity->Ed25519SecretKeyHex;', true],
    'the public half' => ['$key = $identity->ed25519PublicKeyHex;', false],
    'prose naming the half it forbids' => ['// never copy ed25519SecretKeyHex to a peer', false],
    'a docblock naming it' => ['/** @var string $ed25519SecretKeyHex */', false],
]);
