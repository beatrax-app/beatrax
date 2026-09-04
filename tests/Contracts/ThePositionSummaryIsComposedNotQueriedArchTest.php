<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Position\Public\Services\PositionQuery;

// The dashboard, the periodic digest and the navigation badge all quote one
// position, and they agree because all three resolve through the same
// neighbours' public seams. A SELECT written here against a neighbour's own
// table freezes that neighbour's rules at the moment it was typed — a refund
// counted on one surface and excluded on the other — and nothing goes red
// when the digest and the screen part company.

// The actor a queued digest loads before it composes for them. It is the
// person, not a feature's ledger, which is why it is the one Model this
// module may name.
const POSITION_COMPOSED_MODEL_ALLOW_LIST = [
    'Modules\Core\Models\User' => 'the actor a digest job composes for; not a feature table',
];

/** @return list<string> absolute paths to every PHP file the Position module ships */
function positionComposedSources(): array
{
    $root = base_path('Modules/Position');

    if (! is_dir($root)) {
        return [];
    }

    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }

        // A test may name a connection it asserts is absent, and a fixture
        // writes rows directly on purpose. Neither ships.
        if (str_contains($path, '/tests/')) {
            continue;
        }

        $found[] = $path;
    }

    sort($found);

    return $found;
}

function positionComposedRelative(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

function positionComposedStripped(string $path): string
{
    return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));
}

it('reaches the database through no connection, builder or facade of its own', function (): void {
    $sources = positionComposedSources();

    // Counted before anything is read: a walk that resolved nothing reports
    // the same clean tree a clean tree reports.
    expect(count($sources))->toBeGreaterThanOrEqual(5);

    $reachingTheDatabase = [];

    foreach ($sources as $path) {
        $source = positionComposedStripped($path);

        if (PatternScan::matches(
            '/\bDB::'
            .'|Illuminate\\\\Database\\\\(?:DatabaseManager|ConnectionInterface|Query\\\\Builder)'
            .'|Illuminate\\\\Support\\\\Facades\\\\DB'
            .'|->connection\s*\('
            .'|->table\s*\(\s*[\'"]'
            .'|->(?:select|where|order|group|having|join)Raw\s*\(/',
            $source,
        )) {
            $reachingTheDatabase[] = positionComposedRelative($path);
        }
    }

    expect($reachingTheDatabase)->toBe([], implode("\n", [
        'These compose the position from a query of their own rather than from a neighbour:',
        ...$reachingTheDatabase,
        '',
        'The position exists so that one figure answers every surface. A raw read',
        'copies a neighbour\'s rules instead of asking for them, and the copy stops',
        'tracking the original the day that neighbour changes. Ask the owning',
        'module\'s Public service for the figure and let it decide what counts.',
    ]));
});

it('declares only other features public services as the collaborators it composes from', function (): void {
    $constructor = (new ReflectionClass(PositionQuery::class))->getConstructor();

    expect($constructor)->not->toBeNull();

    /** @var ReflectionMethod $constructor */
    $parameters = $constructor->getParameters();

    expect(count($parameters))->toBeGreaterThanOrEqual(4);

    $offenders = [];

    foreach ($parameters as $parameter) {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

        if (PatternScan::matches('/^Modules\\\\[A-Za-z]+\\\\Public\\\\/', $name)) {
            continue;
        }

        $offenders[] = $parameter->getName().': '.$name;
    }

    expect($offenders)->toBe([], implode("\n", [
        'These are collaborators the composed position takes that are not another feature\'s public surface:',
        ...$offenders,
        '',
        'Every figure in the summary is somebody else\'s answer, asked for through',
        'the seam that owns it. A connection, a builder or an internal class here',
        'means this module started deciding what a neighbour\'s numbers mean.',
    ]));
});

it('names no other feature\'s model beyond the one the digest loads its actor from', function (): void {
    $sources = positionComposedSources();

    expect(count($sources))->toBeGreaterThanOrEqual(5);

    $named = [];

    foreach ($sources as $path) {
        $source = positionComposedStripped($path);

        foreach (PatternScan::all('/\bModules\\\\[A-Za-z]+\\\\Models\\\\[A-Za-z]+/', $source)[0] as $symbol) {
            if (array_key_exists($symbol, POSITION_COMPOSED_MODEL_ALLOW_LIST)) {
                continue;
            }

            $entry = positionComposedRelative($path).' → '.$symbol;

            if (! in_array($entry, $named, true)) {
                $named[] = $entry;
            }
        }
    }

    sort($named);

    expect($named)->toBe([], implode("\n", [
        'These read a neighbour\'s rows through its Eloquent model rather than through its service:',
        ...$named,
        '',
        'A model is a table with a nicer spelling. Reading one still bypasses the',
        'rules its owner applies — which types count, which accounts are internal,',
        'which currency has no rate — and those rules are the whole figure.',
    ]));
});
