<?php

declare(strict_types=1);

use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

// `DerivedRowId::for()` mints a 63-bit id so two devices agree on a detector's
// output. A blade that writes it into a JavaScript-evaluated attribute emits a
// number literal, and JS numbers are IEEE doubles: past 2^53 the browser rounds
// before the server sees it, so the action matches no row and does nothing.

// Three review queues were inert this way — chain hints, chain review and the
// recurring approvals — while the anomaly rows beside them worked, because that
// one blade quoted its id. The id must cross as a string.

const JS_EXACT_INTEGER_MAX = 9007199254740991;

/** @return list<string> modules whose rows carry ids past 2^53, read off the call sites */
function modulesMintingDerivedIds(): array
{
    $modules = [];

    foreach (phpSourceFiles() as $file) {
        $source = $file->getContents();

        // Both ways a row gets an id past 2^53: computed from the row's own
        // identity, and minted where no two devices could compute one.
        if (! str_contains($source, 'DerivedRowId::for(') && ! str_contains($source, 'DeviceMintedRowId::mint(')) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getRealPath());

        if (preg_match('#^Modules/([^/]+)/#', $relative, $m) === 1) {
            $modules[$m[1]] = true;
        }
    }

    return array_keys($modules);
}

function phpSourceFiles(): Finder
{
    return (new Finder)->files()->in(base_path('Modules'))->name('*.php')->notPath('tests');
}

// Every attribute the browser evaluates as JavaScript, plus the Alpine calls
// that reach the component the same way.
/** @return list<array{file: string, line: int, attribute: string, argument: string}> */
function bareIdArgumentsIn(string $module): array
{
    $viewsPath = base_path('Modules/'.$module.'/Resources/views');

    if (! is_dir($viewsPath)) {
        return [];
    }

    $offenders = [];

    foreach ((new Finder)->files()->in($viewsPath)->name('*.blade.php') as $file) {
        $lines = explode("\n", $file->getContents());

        foreach ($lines as $index => $line) {
            foreach (bareIdEchoesOn($line) as $argument) {
                $offenders[] = [
                    'file' => str_replace(base_path().'/', '', $file->getRealPath()),
                    'line' => $index + 1,
                    'attribute' => trim($line),
                    'argument' => $argument,
                ];
            }
        }
    }

    return $offenders;
}

// An argument sitting bare between a delimiter and the next one: a quoted id
// puts a quote between `(` and `{{`, so quoting is what this stops matching.
/** @return list<string> */
function bareIdEchoesOn(string $line): array
{
    // Scoped to the attribute the browser evaluates, not the whole line: a
    // style or aria-label sitting beside a wire:click echoes values too, and
    // those are not arguments to anything.
    $attributes = PatternScan::all('/(?:wire:[\w.:-]+|x-on:[\w.:-]+|@[\w.:-]+)="([^"]*)"/', $line)[1];

    if ($attributes === []) {
        return [];
    }

    $matches = [[], []];

    foreach ($attributes as $value) {
        // Both shapes a value travels in: a positional argument, and a
        // property of an object literal handed to $dispatch.
        $inner = PatternScan::all('/[(,:]\s*\{\{\s*([^}]+?)\s*\}\}\s*[,)}]/', $value);

        $matches[1] = [...$matches[1], ...$inner[1]];
    }

    $found = count($matches[1]);

    $bare = [];

    foreach ($matches[1] as $expression) {
        if (str_contains($expression, 'Js::from')) {
            continue;
        }

        if (preg_match('/(^|->|::|\[\'|\[")\s*[a-zA-Z_]*[iI][dD]\s*$/', $expression) === 1) {
            $bare[] = $expression;
        }
    }

    return $bare;
}

it('never lets a module that mints derived ids write one as a bare number', function (): void {
    $minting = modulesMintingDerivedIds();

    // A scan that matched nothing would pass every assertion below it.
    expect($minting)->not->toBeEmpty('no module calls DerivedRowId::for — the scan is broken, not the code');

    $offenders = [];

    foreach ($minting as $module) {
        foreach (bareIdArgumentsIn($module) as $offender) {
            $offenders[] = $offender['file'].':'.$offender['line'].' passes {{ '.$offender['argument']
                .' }} unquoted — a derived id past 2^53 is rounded by the browser';
        }
    }

    expect($offenders)->toBe([], implode("\n  ", ['Ids must cross to the browser as strings:', ...$offenders]));
});

it('reads an id back whichever way the wire delivered it', function (): void {
    $derived = DerivedRowId::for('chain_links', ['a' => 1]);

    expect($derived)->toBeGreaterThan(JS_EXACT_INTEGER_MAX)
        ->and(DerivedRowId::fromWire((string) $derived))->toBe($derived)
        ->and(DerivedRowId::fromWire($derived))->toBe($derived)
        ->and(DerivedRowId::fromWire('not-a-number'))->toBe(0);
});

// The rounding this whole rule exists to prevent, stated as arithmetic so the
// reason survives without a browser to demonstrate it.
it('shows why the number literal cannot carry the id', function (): void {
    $id = 4844448748085860555;

    expect($id)->toBeGreaterThan(JS_EXACT_INTEGER_MAX)
        ->and((int) (float) $id)->not->toBe($id);
});
