<?php

declare(strict_types=1);

use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;
use Tests\Contracts\Support\WireCallableMethods;

// `DerivedRowId::for()` mints a 63-bit id so two devices agree on a detector's
// output. A blade that writes it into a JavaScript-evaluated attribute emits a
// number literal, and JS numbers are IEEE doubles: past 2^53 the browser rounds
// before the server sees it, so the action matches no row and does nothing.

// Three review queues were inert this way — chain hints, chain review and the
// recurring approvals — while the anomaly rows beside them worked, because that
// one blade quoted its id. The id must cross as a string.

const JS_EXACT_INTEGER_MAX = 9007199254740991;

// A parameter no container can resolve is one Livewire fills from the wire
// payload, so a class-typed one takes no argument's place and the position a
// coercion lands on is counted among these alone.
const WIRE_FILLED_PARAMETER_TYPES = ['int', 'string', 'float', 'bool', 'array', 'mixed', 'null', 'false', 'true'];

// Each reader's own floor, below which it has stopped rather than found the
// tree clean. They differ because the shapes do: twenty expressions are echoed
// into these attributes, one action is concatenated and handed to a mounted
// component, and two hundred wire arguments resolve to a declared parameter.
const BARE_ID_CANDIDATE_FLOORS = ['echo' => 10, 'concatenation' => 1, 'coercion' => 100];

// The JavaScript that turns the string back into an IEEE double. Unary plus is
// the same operator spelled shortest, and it is the one a reader skims past.
const NUMERIC_COERCIONS = ['parseInt', 'parseFloat', 'Number'];

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

// The same walk over the same templates for the third reader, kept apart from
// the two above because its verdict is a different sentence: what is wrong at
// these sites is not a missing pair of quotes, it is a coercion applied to an
// id the quoting had already delivered intact.
/** @return list<array{file: string, line: int, method: string, index: int, type: string, argument: string}> */
function coercedWireArgumentsIn(string $module): array
{
    $viewsPath = base_path('Modules/'.$module.'/Resources/views');

    if (! is_dir($viewsPath)) {
        return [];
    }

    $found = [];

    foreach ((new Finder)->files()->in($viewsPath)->name('*.blade.php') as $file) {
        foreach (explode("\n", $file->getContents()) as $index => $line) {
            foreach (coercedWireArgumentsOn($line) as $coerced) {
                $found[] = [
                    'file' => str_replace(base_path().'/', '', $file->getRealPath()),
                    'line' => $index + 1,
                    ...$coerced,
                ];
            }
        }
    }

    return $found;
}

/**
 * Adds to, and reads back, how many argument expressions each reader below
 * reached, before any of them decided whether one carries an id. Counted inside
 * them rather than by a second pass over the same patterns: a denominator that
 * re-implements the reading says nothing about whether the reading ran.
 *
 * Kept per reader rather than as one total. A reader that stopped matching is
 * exactly what the number is for, and a shared pot lets the one that stopped
 * hide behind the two that did not.
 *
 * @return array<string, int>
 */
function bareIdCandidatesRead(?string $reader = null, int $add = 0): array
{
    static $totals = ['echo' => 0, 'concatenation' => 0, 'coercion' => 0];

    if ($reader !== null) {
        $totals[$reader] += $add;
    }

    return $totals;
}

// The first way an id reaches a wire attribute: echoed straight into it, and
// sitting bare between one delimiter and the next.
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

    $expressions = [];

    foreach ($attributes as $value) {
        // Both shapes a value travels in: a positional argument, and a
        // property of an object literal handed to $dispatch.
        $inner = PatternScan::all('/[(,:]\s*\{\{\s*([^}]+?)\s*\}\}\s*[,)}]/', $value);

        $expressions = [...$expressions, ...$inner[1]];
    }

    bareIdCandidatesRead('echo', count($expressions));

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
    $found = PatternScan::all('/\(\'\s*\.(.+?)\.\s*\'\)/', $line);

    bareIdCandidatesRead('concatenation', count($found[1]));

    return array_values(array_filter(array_map(trim(...), $found[1]), idBearingExpression(...)));
}

// The third reader, and a shape the two above cannot see by construction: the
// blade writes the id correctly, quoted, into an attribute, and JavaScript
// reads it back out of that attribute and coerces it. Every echo the two scans
// examine is already past by then, so the rounding happens where neither looks.

// The rule is positional rather than blanket, because the same call carries
// both kinds of id: a transaction id that may be derived, beside a category id
// that is a per-device autoincrement the parameter types `int`. Coercing the
// second is legitimate and coercing the first is the defect.
/**
 * @return list<array{method: string, index: int, type: string, argument: string}>
 */
function coercedWireArgumentsOn(string $line): array
{
    $declared = wireArgumentTypes();
    $coerced = [];
    $examined = 0;

    foreach (wireCallsOn($line) as $call) {
        foreach (wireCallArguments($call['arguments']) as $index => $argument) {
            // A name no component declares is not a wire action. $dispatch,
            // $set and Alpine's own helpers all read the same to a pattern,
            // and a method taking fewer arguments than the call writes is the
            // same answer: nothing here lands on a parameter.
            $spellings = $declared[$call['method']][$index] ?? null;

            if ($spellings === null) {
                continue;
            }

            $examined++;

            if (! numericallyCoerced($argument)) {
                continue;
            }

            $coerced[] = [
                'method' => $call['method'],
                'index' => $index,
                'type' => implode(' / ', $spellings),
                'argument' => $argument,
            ];
        }
    }

    bareIdCandidatesRead('coercion', $examined);

    return $coerced;
}

/**
 * Every wire-callable method by name, with the type each ARGUMENT lands on.
 *
 * Keyed by name and not by component because the call sites are Blade: a shared
 * component renders its mounting component's action into an attribute of its
 * own, so no file path resolves the method for it. Where two components declare
 * the same name, every spelling declared at that position is kept and the rule
 * below reads them all — over-reporting a coercion is a review, under-reporting
 * one is the defect this file exists for.
 *
 * @return array<string, array<int, list<string>>> method => argument index => the spellings declared at it
 */
function wireArgumentTypes(): array
{
    static $types = null;

    if ($types !== null) {
        return $types;
    }

    $spellings = [];

    foreach (WireCallableMethods::components() as $component) {
        foreach (WireCallableMethods::invokableOn($component) as $method) {
            $index = 0;

            foreach ($method->getParameters() as $parameter) {
                $spelling = $parameter->getType() === null ? 'mixed' : (string) $parameter->getType();

                if (! wireFillsParameter($spelling)) {
                    continue;
                }

                $spellings[$method->getName()][$index][$spelling] = true;
                $index++;
            }
        }
    }

    $types = array_map(
        static fn (array $positions): array => array_map(array_keys(...), $positions),
        $spellings,
    );

    return $types;
}

// A union is filled from the payload only when every arm of it is, so
// `int|string` is an argument and `CurrentUser` is an injection.
function wireFillsParameter(string $spelling): bool
{
    foreach (explode('|', ltrim($spelling, '?')) as $arm) {
        if (! in_array(strtolower($arm), WIRE_FILLED_PARAMETER_TYPES, true)) {
            return false;
        }
    }

    return true;
}

/**
 * Both spellings a blade reaches a component method by: the Alpine call, and
 * the wire attribute whose whole value is the call.
 *
 * @return list<array{method: string, arguments: string}>
 */
function wireCallsOn(string $line): array
{
    $calls = [];

    foreach (PatternScan::setsWithOffsets('/(?:\$wire\.|wire:[\w.:-]+="\s*)([A-Za-z_]\w*)\s*\(/', $line) as $set) {
        $calls[] = [
            'method' => $set[1][0],
            'arguments' => wireCallArgumentList($line, $set[0][1] + strlen($set[0][0]) - 1),
        ];
    }

    return $calls;
}

// Read by balancing rather than by a pattern: `parseInt(x, 10)` brings a
// parenthesis and a comma of its own. A call that does not close on this line
// yields the rest of it rather than nothing — every argument but the last is
// still whole, and a reader answering "no arguments" would report it clean.
function wireCallArgumentList(string $line, int $open): string
{
    $depth = 0;
    $quote = null;
    $length = strlen($line);

    for ($index = $open; $index < $length; $index++) {
        $character = $line[$index];

        if ($quote !== null) {
            if ($character === $quote && $line[$index - 1] !== '\\') {
                $quote = null;
            }

            continue;
        }

        if ($character === "'" || $character === '"') {
            $quote = $character;

            continue;
        }

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;

            if ($depth === 0) {
                return substr($line, $open + 1, $index - $open - 1);
            }
        }
    }

    return substr($line, $open + 1);
}

/**
 * @return list<string> the arguments of one call, split where the call splits
 *                      them and not inside a nested one
 */
function wireCallArguments(string $arguments): array
{
    if (trim($arguments) === '') {
        return [];
    }

    $split = [];
    $buffer = '';
    $depth = 0;
    $quote = null;
    $length = strlen($arguments);

    for ($index = 0; $index < $length; $index++) {
        $character = $arguments[$index];

        if ($quote !== null) {
            $buffer .= $character;

            if ($character === $quote && $arguments[$index - 1] !== '\\') {
                $quote = null;
            }

            continue;
        }

        if ($character === "'" || $character === '"') {
            $quote = $character;
            $buffer .= $character;

            continue;
        }

        if (str_contains('([{', $character)) {
            $depth++;
        } elseif (str_contains(')]}', $character)) {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $split[] = trim($buffer);
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    $split[] = trim($buffer);

    return $split;
}

// A parameter accepting a string as well as an int is this repository's
// spelling for "the id arriving here may be derived, so it crosses quoted": it
// is the one shape DerivedRowId::fromWire() takes. Read as a set of arms rather
// than as a literal, because reflection prints a union in its own canonical
// order and not in the one the source declares.
function parameterMayBeDerived(string $spelling): bool
{
    $arms = array_map(strtolower(...), explode('|', ltrim($spelling, '?')));

    return in_array('int', $arms, true) && in_array('string', $arms, true);
}

function numericallyCoerced(string $argument): bool
{
    foreach (NUMERIC_COERCIONS as $coercion) {
        if (PatternScan::matches('/\b'.$coercion.'\s*\(/', $argument)) {
            return true;
        }
    }

    return PatternScan::matches('/^\+\s*[A-Za-z_$(]/', $argument);
}

// What both scans are looking for, and what quoting takes away from them: a
// quote lands between the delimiter and the value, so neither pattern reaches
// the expression any more. The closing `']` is optional rather than absent: an
// id read out of an array is written `$row['id']`, and requiring the expression
// to END on the letters hid every one of those — Forecasting's minted scenario
// mutations among them.
function idBearingExpression(string $expression): bool
{
    // Js::from is the quoting this rule asks for, spelled as a helper rather
    // than as a pair of quotes: it emits a JSON string literal, so the browser
    // never sees a number to round.
    if (str_contains($expression, 'Js::from')) {
        return false;
    }

    // A cast is a wrapper, not a different value: `(int) $accountId` is the
    // account, and an anchor that has to start on the `$` reads straight past
    // the `(`. `(int) $entry->id` was caught only because `->` gave it a second
    // way in, which is why the shape looked covered.
    $operand = trim(PatternScan::replace('/^\(\s*(int|integer|float|double|string|bool|boolean)\s*\)\s*/i', '', $expression));

    return preg_match('/(^\$?|->|::|\[\'|\[")\s*[a-zA-Z_]*[iI][dD]\s*(\'\]|"\])?\s*$/', $operand) === 1;
}

it('never lets a blade write a derived id as a bare number', function (): void {
    $rendering = modulesShippingViews();

    // Two scans that matched nothing would pass every assertion below them: one
    // says these ids are minted at all, the other says there are blades to read.
    expect(modulesMintingDerivedIds())->not->toBeEmpty('no module calls DerivedRowId::for — the scan is broken, not the code');
    expect($rendering)->not->toBeEmpty('no module ships Resources/views — the scan is broken, not the code');

    $offenders = [];
    $coerced = [];
    $cleared = [];

    foreach ($rendering as $module) {
        foreach (bareIdArgumentsIn($module) as $offender) {
            $offenders[] = $offender['file'].':'.$offender['line'].' passes '.$offender['argument']
                .' unquoted — a derived id past 2^53 is rounded by the browser';
        }

        foreach (coercedWireArgumentsIn($module) as $site) {
            $where = $site['file'].' '.$site['method'].' argument '.$site['index'].' typed '.$site['type'];

            if (! array_any(explode(' / ', $site['type']), parameterMayBeDerived(...))) {
                $cleared[] = $where;

                continue;
            }

            $coerced[] = $site['file'].':'.$site['line'].' coerces '.$site['argument'].' into '.$site['method']
                .'() argument '.$site['index'].', typed '.$site['type']
                .' — the browser rounds it before fromWire() ever reads it';
        }
    }

    // A floor for each reader separately. It catches the one failure a floor
    // can catch — a reader that stopped — and a shared total would let the one
    // that stopped hide behind the two that did not.
    foreach (bareIdCandidatesRead() as $reader => $seen) {
        expect($seen)->toBeGreaterThanOrEqual(
            BARE_ID_CANDIDATE_FLOORS[$reader],
            'The '.$reader.' reader reached '.$seen.' argument expressions, so it stopped matching rather than '
            .'the tree being clean.',
        );
    }

    // The live artefact the floors stand beside, and the one that says the
    // coercion rule is positional rather than blanket: the select on a triage
    // row coerces its SECOND argument, a category id the component types ?int,
    // and the reader has to have reached that site and cleared it.
    expect($cleared)->toContain(
        'Modules/Categorization/Resources/views/livewire/triage-inbox.blade.php selectForRow argument 1 typed ?int',
    );

    expect($offenders)->toBe([], implode("\n  ", ['Ids must cross to the browser as strings:', ...$offenders]));

    expect($coerced)->toBe([], implode("\n  ", [
        'An id a component types int|string is one that may be derived, so nothing may coerce it on the way out:',
        ...$coerced,
    ]));
});

// The two readers and the id test are the whole of the verdict, so each is
// driven over the shape it has to catch and the quoting that takes it away.
it('sees an id passed bare in either shape, and leaves a quoted one alone', function (): void {
    $echoed = '<button wire:click="approve({{ $link->id }})">yes</button>';
    $quoted = '<button wire:click="approve(\'{{ $link->id }}\')">yes</button>';
    $dispatched = '<button x-on:click="$dispatch(\'open\', { id: {{ $row[\'id\'] }} })">open</button>';
    $cast = '<button wire:click="approve({{ (int) $entry->id }})">yes</button>';
    $amount = '<button wire:click="approve({{ $row->amount_minor }})">yes</button>';
    $handedOn = '<x-core::confirm-strip :action="\'reject(\'.$link->id.\')\'" />';
    $handedOnQuoted = '<x-core::confirm-strip :action="\'reject(\\\'\'.$link->id.\'\\\')\'" />';

    expect(bareIdEchoesOn($echoed))->toBe(['$link->id'], 'An id echoed bare into a wire action went unread.');
    expect(bareIdEchoesOn($quoted))->toBe([], 'A quoted id is what this rule asks for, and it was reported.');
    expect(bareIdEchoesOn($dispatched))->toBe(['$row[\'id\']'], 'An id in a $dispatch object literal went unread.');
    expect(bareIdEchoesOn($cast))->toBe(['(int) $entry->id'], 'A cast is a wrapper, not a different value, and it went unread.');
    expect(bareIdEchoesOn($amount))->toBe([], 'A value that is not an id was reported as one.');

    expect(bareIdConcatenationsOn($handedOn))->toBe(['$link->id'], 'An id concatenated into an action string went unread.');
    expect(bareIdConcatenationsOn($handedOnQuoted))->toBe([], 'A quoted concatenation is what this rule asks for, and it was reported.');

    expect(idBearingExpression('Js::from($link->id)'))->toBeFalse('Js::from emits a JSON string, so it is the quoting and not the defect.');
});

// The third reader gets the same treatment, against the two signatures the
// rule turns on. They are read off the tree rather than written out here: a
// synthetic case standing on a synthetic signature would agree with itself
// whatever the components declare.
it('sees a coerced argument only where the parameter it lands on may be derived', function (): void {
    $declared = wireArgumentTypes();

    expect($declared['selectForRow'] ?? [])->toBe(
        [0 => ['string|int'], 1 => ['?int']],
        'This rule is read positionally off these two spellings, so a signature that no longer says int|string then '
        .'?int leaves the cases below agreeing with nothing.',
    );
    expect($declared['setHorizon'] ?? [])->toBe(
        [0 => ['int']],
        'The green half of the rule needs a wire method whose first argument a component types plain int.',
    );

    $atADerivedPosition = '<div x-on:keydown="$wire.selectForRow(parseInt(row.dataset.txid, 10), 4)"></div>';
    $unaryPlus = '<div x-on:keydown="$wire.selectForRow(+row.dataset.txid, 4)"></div>';
    $atAnIntPosition = '<select x-on:change="$wire.selectForRow(\'{{ $row->transactionId }}\', $event.target.value ? parseInt($event.target.value, 10) : null)"></select>';
    $wholeCallAtAnIntPosition = '<button wire:click="setHorizon(Number(chosen))">go</button>';
    $quotedAtADerivedPosition = '<select x-on:change="$wire.selectForRow(\'{{ $row->transactionId }}\', null)"></select>';
    $notAWireAction = '<button wire:click="$dispatch(\'open\', { id: parseInt(raw, 10) })">open</button>';

    expect(coercedWireArgumentsOn($atADerivedPosition))->toBe(
        [['method' => 'selectForRow', 'index' => 0, 'type' => 'string|int', 'argument' => 'parseInt(row.dataset.txid, 10)']],
        'A coercion on the argument that may carry a derived id went unread.',
    );
    expect(coercedWireArgumentsOn($unaryPlus))->toBe(
        [['method' => 'selectForRow', 'index' => 0, 'type' => 'string|int', 'argument' => '+row.dataset.txid']],
        'Unary plus is the same coercion spelled shortest, and it went unread.',
    );
    expect(coercedWireArgumentsOn($atAnIntPosition))->toBe(
        [['method' => 'selectForRow', 'index' => 1, 'type' => '?int', 'argument' => '$event.target.value ? parseInt($event.target.value, 10) : null']],
        'The reader has to reach the coercion the shipped select does, so that the verdict can clear it on the type.',
    );
    expect(coercedWireArgumentsOn($wholeCallAtAnIntPosition))->toBe(
        [['method' => 'setHorizon', 'index' => 0, 'type' => 'int', 'argument' => 'Number(chosen)']],
        'A wire attribute whose whole value is the call is the second spelling, and it went unread.',
    );
    expect(coercedWireArgumentsOn($quotedAtADerivedPosition))->toBe([], 'A quoted argument is what this rule asks for, and it was reported.');
    expect(coercedWireArgumentsOn($notAWireAction))->toBe([], 'A $dispatch payload lands on no component parameter, and it was reported.');
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
