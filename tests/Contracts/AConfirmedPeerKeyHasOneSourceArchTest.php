<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Pairing is the ceremony that admits a device, and the confirmed registry row
// it writes is the only record of that. A second source beside the lookup — an
// array merged in, a configured list, a key read off disk — would be a trust
// root nobody ever paired with, and every handshake against it still succeeds.

/** @return list<string> every PHP file the shells ship, tests excluded */
function confirmedPeerKeySources(): array
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

            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
                $found[] = $path;
            }
        }
    }

    sort($found);

    return $found;
}

// Comments name the lookup on purpose — the class docblocks describe the very
// gate this scans for — so they are dropped before anything is read.
function confirmedPeerKeyStripped(string $path): string
{
    return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
}

// The statement a call sits inside: from the last separator before it to the
// first one after. That is exactly the span in which an answer could be
// combined with something else, and nothing wider is any of this rule's business.
function confirmedPeerKeyStatementAt(string $source, int $offset): string
{
    $before = substr($source, 0, $offset);
    $start = 0;

    foreach ([';', '{', '}'] as $separator) {
        $at = strrpos($before, $separator);
        $start = $at === false ? $start : max($start, $at + 1);
    }

    $ends = array_filter(
        [strpos($source, ';', $offset), strpos($source, '{', $offset)],
        static fn (int|false $at): bool => $at !== false,
    );

    $end = $ends === [] ? strlen($source) : min($ends);

    return trim(substr($source, $start, $end - $start));
}

function confirmedPeerKeyRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

it('reads a peer static key out of the confirmed registry and combines it with nothing', function (): void {
    $sources = confirmedPeerKeySources();

    expect($sources)->not->toBeEmpty();

    $shapes = [
        '/\A\$\w+\s*=\s*\$[\w>-]+->deviceX25519Keys\([^()]*\)\z/',
        '/\Aforeach\s*\(\s*\$[\w>-]+->deviceX25519Keys\([^()]*\)\s+as\s+[^()]*\)\z/',
        '/\Areturn\s+\$[\w>-]+->deviceX25519Keys\([^()]*\)\z/',
    ];

    $callSites = 0;
    $offenders = [];

    foreach ($sources as $path) {
        $stripped = confirmedPeerKeyStripped($path);

        foreach (PatternScan::allWithOffsets('/->deviceX25519Keys\(/', $stripped)[0] ?? [] as $hit) {
            $callSites++;
            $statement = confirmedPeerKeyStatementAt($stripped, (int) $hit[1]);

            $safe = false;
            foreach ($shapes as $shape) {
                $safe = $safe || PatternScan::matches($shape, $statement);
            }

            if (! $safe) {
                $offenders[] = confirmedPeerKeyRelative($path).': '.$statement;
            }
        }
    }

    expect($callSites)->toBeGreaterThanOrEqual(
        3,
        'the walk found no peer-key lookup at all, which is the same answer a clean tree gives',
    );

    expect($offenders)->toBe([], 'a peer key may be read, and then used as it was read. '
        .'Widening the answer — merging a second map into it, spreading it into a literal, falling back to a '
        .'configured or stored key — creates a trust root pairing never established, and the handshake against '
        .'it succeeds exactly as a real peer would: '.implode(' | ', $offenders));
});

it('anchors that one source on the confirmation the pairing ceremony writes', function (): void {
    $path = base_path('Modules/Sync/Public/Services/DeviceRegistryService.php');

    expect(is_file($path))->toBeTrue();

    $stripped = confirmedPeerKeyStripped($path);
    $at = strpos($stripped, 'function deviceX25519Keys(');

    expect($at)->not->toBeFalse('the lookup every session admits on must still be here to be anchored');

    $next = strpos($stripped, 'public function ', (int) $at);
    $body = substr($stripped, (int) $at, ($next === false ? strlen($stripped) : $next) - (int) $at);

    expect(PatternScan::matches("/->table\(\s*'device_registry'\s*\)/", $body))->toBeTrue(
        'the confirmed-peer map must be read from the registry pairing writes, not from anywhere else',
    );

    expect(PatternScan::matches("/->whereNotNull\(\s*'confirmed_at'\s*\)/", $body))->toBeTrue(
        'a row without a confirmation is a device the household never finished admitting, and dropping that '
        .'filter would silently admit every half-paired and every revoked device to a session',
    );

    expect(PatternScan::matches("/->where\(\s*'user_id'\s*,/", $body))->toBeTrue(
        'one household confirming a device says nothing about another, so the lookup carries its owner',
    );
});
