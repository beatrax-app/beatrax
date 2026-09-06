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

// A connection, a builder, a facade or a raw fragment: the four ways a module
// composing from its neighbours could start reading their tables itself.
function positionComposedReachesTheDatabase(string $source): bool
{
    return PatternScan::matches(
        '/\bDB::'
        .'|Illuminate\\\\Database\\\\(?:DatabaseManager|ConnectionInterface|Query\\\\Builder)'
        .'|Illuminate\\\\Support\\\\Facades\\\\DB'
        .'|->connection\s*\('
        .'|->table\s*\(\s*[\'"]'
        .'|->(?:select|where|order|group|having|join)Raw\s*\(/',
        $source,
    );
}

/** @return list<string> every `Modules\X\Models\Y` the source names, in order, deduplicated */
function positionComposedModelsNamed(string $source): array
{
    return array_values(array_unique(PatternScan::all('/\bModules\\\\[A-Za-z]+\\\\Models\\\\[A-Za-z]+/', $source)[0]));
}

it('reaches the database through no connection, builder or facade of its own', function (): void {
    $sources = positionComposedSources();

    // Counted before anything is read: a walk that resolved nothing reports
    // the same clean tree a clean tree reports. Six files today.
    expect(count($sources))->toBeGreaterThanOrEqual(5, 'the Position module walk read almost nothing — the root is wrong, not the module.');

    $reachingTheDatabase = [];

    foreach ($sources as $path) {
        if (positionComposedReachesTheDatabase(positionComposedStripped($path))) {
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

// PositionQuery by name, not "this module": PositionDigestDispatch is the
// other Public service here and it takes Illuminate's Dispatcher, which is
// correct for a seam whose whole job is to queue a job. The rule is about the
// class that composes the summary.
it('declares only other features public services as the collaborators PositionQuery composes from', function (): void {
    $constructor = (new ReflectionClass(PositionQuery::class))->getConstructor();

    expect($constructor)->not->toBeNull('PositionQuery declares no constructor at all, so it takes no collaborators to judge.');

    /** @var ReflectionMethod $constructor */
    $parameters = $constructor->getParameters();

    expect(count($parameters))->toBeGreaterThanOrEqual(4, 'PositionQuery composes from almost nothing — the summary stopped being composed, or reflection read the wrong class.');

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

    expect(count($sources))->toBeGreaterThanOrEqual(5, 'the Position module walk read almost nothing — the root is wrong, not the module.');

    $named = [];
    $allowed = [];

    foreach ($sources as $path) {
        foreach (positionComposedModelsNamed(positionComposedStripped($path)) as $symbol) {
            if (array_key_exists($symbol, POSITION_COMPOSED_MODEL_ALLOW_LIST)) {
                $allowed[$symbol] = true;

                continue;
            }

            $entry = positionComposedRelative($path).' → '.$symbol;

            if (! in_array($entry, $named, true)) {
                $named[] = $entry;
            }
        }
    }

    sort($named);

    // An allowed model nothing names any more is a claim about this module that
    // stopped being true, and it would otherwise sit here excusing nothing.
    expect(array_keys($allowed))->toBe(array_keys(POSITION_COMPOSED_MODEL_ALLOW_LIST), implode("\n", [
        'An entry in POSITION_COMPOSED_MODEL_ALLOW_LIST excuses nothing in this module any more.',
        'Delete it: an exemption nobody reaches reads as considered to every reader after it, and',
        'the next model to be named here inherits the excuse. Reached: '.implode(', ', array_keys($allowed)),
    ]));

    expect($named)->toBe([], implode("\n", [
        'These read a neighbour\'s rows through its Eloquent model rather than through its service:',
        ...$named,
        '',
        'A model is a table with a nicer spelling. Reading one still bypasses the',
        'rules its owner applies — which types count, which accounts are internal,',
        'which currency has no rate — and those rules are the whole figure.',
    ]));
});

it('sees each of the four ways a query gets written here, and reads a seam call as a seam call', function (): void {
    $reaching = [
        'a facade' => 'return DB::table(\'transactions\')->sum(\'amount_minor\');',
        'a named connection' => 'return $this->db->connection()->table(\'accounts\')->count();',
        'a builder import' => 'use Illuminate\Database\Query\Builder;',
        'a raw fragment' => 'return $query->selectRaw(\'sum(amount_minor)\');',
    ];

    $composing = [
        'a public seam' => 'return $this->glance->for($user, $period);',
        'a DTO' => 'return new PositionSummaryDto(summary: $summary, netWorth: $netWorth);',
        // `->table(` on a variable is a caller passing a name through, not this
        // module deciding a table: the pattern deliberately wants a literal.
        'a table name it was handed' => 'return $this->rows->table($table)->get();',
    ];

    $wrong = [];

    foreach ($reaching as $shape => $source) {
        if (! positionComposedReachesTheDatabase($source)) {
            $wrong[] = 'missed '.$shape;
        }
    }

    foreach ($composing as $shape => $source) {
        if (positionComposedReachesTheDatabase($source)) {
            $wrong[] = 'flagged '.$shape;
        }
    }

    expect($wrong)->toBe([], "The database reader answers the wrong way round:\n  ".implode("\n  ", $wrong));
});

it('sees a neighbour\'s model named inline as well as imported', function (): void {
    $planted = <<<'PHP'
        use Modules\Ledger\Models\Transaction;

        $latest = \Modules\Budgets\Models\Envelope::query()->first();
        $again = Modules\Ledger\Models\Transaction::query()->count();
        $ours = PositionSummaryDto::class;
        PHP;

    expect(positionComposedModelsNamed($planted))->toBe(
        ['Modules\Ledger\Models\Transaction', 'Modules\Budgets\Models\Envelope'],
        'The model reader must see an import, an inline fully-qualified reference and a repeat of '
        .'the first as one symbol — an import scan alone is blind to the inline spelling, which is '
        .'exactly how a model reaches a module nothing declared it in.',
    );
});
