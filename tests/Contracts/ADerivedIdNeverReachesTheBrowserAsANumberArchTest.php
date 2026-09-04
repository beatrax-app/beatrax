<?php

declare(strict_types=1);

use Modules\Core\Public\Support\DerivedRowId;
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

// A derived id is a value and it travels: a goal id minted in Goals is rendered
// in a Ledger blade, and Tax and DevMode mint nothing at all yet write these ids
// into wire attributes today. Scanning only the minting module opens none of
// those files, so the scan follows the blades instead of the call sites.
/** @return list<string> every module that ships blades */
function modulesShippingViews(): array
{
    $modules = [];

    foreach ((new Finder)->directories()->in(base_path('Modules'))->depth(0) as $directory) {
        if (is_dir($directory->getRealPath().'/Resources/views')) {
            $modules[] = $directory->getFilename();
        }
    }

    sort($modules);

    return $modules;
}

function phpSourceFiles(): Finder
{
    return (new Finder)->files()->in(base_path('Modules'))->name('*.php')->notPath('tests');
}

// Every attribute the browser evaluates as JavaScript, plus the Alpine calls
// that reach the component the same way, plus the wire actions a blade builds
// as a string and hands to a component that renders them into its own.
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
            foreach ([...bareIdEchoesOn($line), ...bareIdConcatenationsOn($line)] as $argument) {
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

// The first way an id reaches a wire attribute: echoed straight into it, and
// sitting bare between one delimiter and the next.
/** @return list<string> */
function bareIdEchoesOn(string $line): array
{
    // Scoped to the attribute the browser evaluates, not the whole line: a
    // style or aria-label sitting beside a wire:click echoes values too, and
    // those are not arguments to anything.
    $attributes = preg_match_all('/(?:wire:[\w.:-]+|x-on:[\w.:-]+|@[\w.:-]+)="([^"]*)"/', $line, $found) === false
        ? []
        : $found[1];

    if ($attributes === []) {
        return [];
    }

    $expressions = [];

    foreach ($attributes as $value) {
        // Both shapes a value travels in: a positional argument, and a
        // property of an object literal handed to $dispatch.
        $hit = preg_match_all('/[(,:]\s*\{\{\s*([^}]+?)\s*\}\}\s*[,)}]/', $value, $inner);

        if ($hit === false) {
            throw new RuntimeException('preg_match_all failed on: '.$value);
        }

        $expressions = [...$expressions, ...$inner[1]];
    }

    return array_values(array_filter($expressions, idBearingExpression(...)));
}

// The second way an id reaches a wire attribute, and the one that hid a whole
// second call site: the blade does not write the attribute, it concatenates the
// call into a string and hands it to a mounted component that renders it into a
// wire:click of its own. Every x-core::confirm-strip works this way, so the
// button that asks the question was quoted and the button that answers it was
// not — on three pages whose ids are minted past 2^53 today.
/** @return list<string> */
function bareIdConcatenationsOn(string $line): array
{
    $hit = preg_match_all('/\(\'\s*\.(.+?)\.\s*\'\)/', $line, $found);

    if ($hit === false) {
        throw new RuntimeException('preg_match_all failed on: '.$line);
    }

    return array_values(array_filter(array_map(trim(...), $found[1]), idBearingExpression(...)));
}

// What both scans are looking for, and what quoting takes away from them: a
// quote lands between the delimiter and the value, so neither pattern reaches
// the expression any more. The closing `']` is optional rather than absent: an
// id read out of an array is written `$row['id']`, and requiring the expression
// to END on the letters hid every one of those — Forecasting's minted scenario
// mutations among them.
function idBearingExpression(string $expression): bool
{
    if (str_contains($expression, 'Js::from')) {
        return false;
    }

    return preg_match('/(^\$?|->|::|\[\'|\[")\s*[a-zA-Z_]*[iI][dD]\s*(\'\]|"\])?\s*$/', $expression) === 1;
}

it('never lets a blade write a derived id as a bare number', function (): void {
    $rendering = modulesShippingViews();

    // Two scans that matched nothing would pass every assertion below them: one
    // says these ids are minted at all, the other says there are blades to read.
    expect(modulesMintingDerivedIds())->not->toBeEmpty('no module calls DerivedRowId::for — the scan is broken, not the code');
    expect($rendering)->not->toBeEmpty('no module ships Resources/views — the scan is broken, not the code');

    $offenders = [];

    foreach ($rendering as $module) {
        foreach (bareIdArgumentsIn($module) as $offender) {
            $offenders[] = $offender['file'].':'.$offender['line'].' passes '.$offender['argument']
                .' unquoted — a derived id past 2^53 is rounded by the browser';
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
