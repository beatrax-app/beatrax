<?php

declare(strict_types=1);

use Modules\Auth\Public\Contracts\AppLockPinShape;
use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/features/auth/architecture.md
 */

// AppLockPinShape says what a PIN is, and the settings screen takes the reader
// at its word. The unlock pad had typed the ceiling out instead — six times,
// across three templates — so raising the constant would have left a reader who
// set a twelve-digit PIN unable to spell it back on a pad that stops at ten.
//
// That is the permanent lockout the constant exists to prevent, and it is
// reached entirely through supported UI: nothing refuses, nothing logs, and the
// only way back in is the sign-out behind "Forgot your PIN?".

// The Alpine state the pad accumulates a PIN in. A template naming it is on the
// PIN path, which is how the fourth pad somebody writes comes under this rule
// without anybody remembering to list it.
const PIN_PAD_STATE = ['pin.length', 'submitPin'];

// A keypad prints the ten digits, so the number 6 is written all over these
// templates as a glyph. What tells a length from a glyph is the position: a
// length is compared against, iterated to, or counted out. The three patterns
// below are those positions, and nothing outside them is read.
const PIN_PAD_ORDERING = '/(?<![=!<>?-])(?:<=|>=|<|>)\s*(\d+)/';

const PIN_PAD_ORDERED = '/(\d+)\s*(?:<=|>=|<|>)/';

const PIN_PAD_ITERATION = '/\bin\s+(\d+)\b/';

const PIN_PAD_COUNTING_CALL = '/\b(?:range|str_repeat|str_pad|array_fill|repeat|padStart|padEnd)\s*\(([^()]*)\)/';

const PIN_PAD_NUMERAL = '/(?<![\w.-])(\d+)(?![\w.-])/';

// Alpine's numeric loop is the dot row's capacity, and it is the one position
// where a bare `in 10` means a count.
const PIN_PAD_ITERATING_ATTRIBUTE = 'x-for';

// An attribute whose value is an expression rather than a class list, a URL or
// a label. Reading only these is what keeps a viewBox, a stroke width and a
// duration utility out of a rule about PIN lengths.
const PIN_PAD_BINDING_ATTRIBUTE = '/^(?:x-|wire:|:|@)/';

/** @return list<int> the lengths AppLockPinShape owns, which no template may restate */
function pinPadOwnedLengths(): array
{
    return [AppLockPinShape::MINIMUM_LENGTH, AppLockPinShape::MAXIMUM_LENGTH];
}

/** @return list<string> every Blade view a reader is shown that carries the pad's own state */
function pinPadTemplates(): array
{
    $found = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        foreach (PIN_PAD_STATE as $state) {
            if (str_contains($source, $state)) {
                $found[] = $path;

                break;
            }
        }
    }

    return $found;
}

/**
 * Every region of one template a length can be written into: the Alpine
 * bindings on each tag, and the PHP the template holds. `line` is where the
 * region opens, so an offset inside it resolves to the line it is written on.
 *
 * @return list<array{label: string, expression: string, line: int, iterates: bool}>
 */
function pinPadExpressions(string $source): array
{
    $regions = [];

    foreach (MarkupSource::tags($source) as $element) {
        foreach ($element->attributes() as $name => $value) {
            if ($value === '' || ! PatternScan::matches(PIN_PAD_BINDING_ATTRIBUTE, $name)) {
                continue;
            }

            $at = $element->offset + (int) strpos($element->startTag, $value);

            $regions[] = [
                'label' => $name,
                'expression' => $value,
                'line' => substr_count(substr($source, 0, $at), "\n") + 1,
                'iterates' => $name === PIN_PAD_ITERATING_ATTRIBUTE,
            ];
        }
    }

    // Blade is not PHP until Blade compiles it, so the `@php` island holding
    // the announcement table reaches a reader only through the seam. It comes
    // back on the lines the template wrote it, which is why this region opens
    // at line one.
    $regions[] = [
        'label' => '@php',
        'expression' => BladePhpSource::of($source),
        'line' => 1,
        'iterates' => false,
    ];

    return $regions;
}

/**
 * @return list<array{value: int, offset: int}> the numbers written where the
 *                                              region measures a length
 */
function pinPadLengths(string $expression, bool $iterates): array
{
    $patterns = $iterates
        ? [PIN_PAD_ORDERING, PIN_PAD_ORDERED, PIN_PAD_ITERATION]
        : [PIN_PAD_ORDERING, PIN_PAD_ORDERED];
    $found = [];

    foreach ($patterns as $pattern) {
        foreach (PatternScan::allWithOffsets($pattern, $expression)[1] as $match) {
            $found[] = ['value' => (int) $match[0], 'offset' => $match[1]];
        }
    }

    foreach (PatternScan::setsWithOffsets(PIN_PAD_COUNTING_CALL, $expression) as $call) {
        foreach (PatternScan::allWithOffsets(PIN_PAD_NUMERAL, $call[1][0])[1] as $match) {
            $found[] = ['value' => (int) $match[0], 'offset' => $call[1][1] + $match[1]];
        }
    }

    return $found;
}

/** @return list<string> every length one template states as a numeral, named with its site */
function pinPadStatedLengths(string $relative, string $source): array
{
    $owned = pinPadOwnedLengths();
    $stated = [];

    foreach (pinPadExpressions($source) as $region) {
        foreach (pinPadLengths($region['expression'], $region['iterates']) as $length) {
            if (! in_array($length['value'], $owned, true)) {
                continue;
            }

            $line = $region['line'] + substr_count(substr($region['expression'], 0, $length['offset']), "\n");
            $stated[] = $relative.':'.$line.'  '.$region['label'].' states '.$length['value'];
        }
    }

    return $stated;
}

it('reads the pads, the expressions in them, and the numbers written there', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);
    $templates = pinPadTemplates();

    // Two hundred and eighty-three views ship today, three of them pads. A walk
    // that opened none of them reports the same clean tree as one that found
    // nothing wrong, so both denominators are read before any verdict below.
    expect(count($views))->toBeGreaterThan(
        150,
        'The walk opened '.count($views).' Blade views, which is too few to have read the view tree at all.',
    );

    expect(count($templates))->toBeGreaterThan(
        2,
        'The derivation found '.count($templates).' templates carrying the pad\'s PIN state. The desktop pad, its '
        .'partial and the mobile pad all carry it, so a smaller answer is a reader that stopped recognising them.',
    );

    $regions = 0;
    $numerals = 0;

    foreach ($templates as $path) {
        foreach (pinPadExpressions((string) file_get_contents($path)) as $region) {
            $regions++;
            $numerals += PatternScan::count(PIN_PAD_NUMERAL, $region['expression']);
        }
    }

    expect($regions)->toBeGreaterThan(
        20,
        'Only '.$regions.' expressions were read across the pads, so the verdict below is about markup nobody parsed.',
    );

    // The ten digit keys alone put twenty-odd numerals in front of the reader,
    // in both pads, forever. A reading that finds none of them would find no
    // stated length either.
    expect($numerals)->toBeGreaterThan(
        20,
        'The reader found '.$numerals.' numerals in those expressions, which is what a broken numeral scan looks like.',
    );
});

it('states no length AppLockPinShape owns, in any template on the PIN path', function (): void {
    $stated = [];

    foreach (pinPadTemplates() as $path) {
        $relative = str_replace(RepoTree::root().'/', '', $path);

        foreach (pinPadStatedLengths($relative, (string) file_get_contents($path)) as $offence) {
            $stated[] = $offence;
        }
    }

    expect($stated)->toBe([], implode("\n  ", [
        'These write a PIN length out as a numeral, in a position where the number bounds the pad:',
        ...$stated,
        '',
        'AppLockPinShape is the one definition of what a PIN is, and a pad that caps below it is a',
        'permanent lockout reached through supported UI — the settings screen takes a PIN the unlock',
        'screen can then never be made to spell. Reach for the constant:',
        '@use(\'Modules\Auth\Public\Contracts\AppLockPinShape\') at the top of the template, then',
        'AppLockPinShape::MAXIMUM_LENGTH in the expression.',
    ]));
});

it('reaches for the constant in every template on the PIN path', function (): void {
    $silent = [];

    foreach (pinPadTemplates() as $path) {
        if (! str_contains((string) file_get_contents($path), 'AppLockPinShape::')) {
            $silent[] = str_replace(RepoTree::root().'/', '', $path);
        }
    }

    expect($silent)->toBe([], implode("\n  ", [
        'These draw a PIN pad and never name the rule that says how long a PIN is. Whatever bounds the',
        'pad instead is a second definition, free to drift from the one the settings screen enforces:',
        ...$silent,
    ]));
});

// Every verdict above is read off one reader, and a reader that stopped
// recognising a position reports the clean tree a compliant one does. These
// plant each way a length is written, and each way a number that is not one.
/** @param list<int> $expected */
it('finds a length wherever a pad bounds itself, and leaves a printed digit alone', function (string $expression, bool $iterates, array $expected): void {
    expect(array_column(pinPadLengths($expression, $iterates), 'value'))->toBe(
        $expected,
        'The reader answered wrongly for: '.$expression,
    );
})->with([
    'the cap on what the keypad accumulates' => ['if (this.pin.length < 10) {', false, [10]],
    'the same cap written the other way round' => ['if (10 > this.pin.length) {', false, [10]],
    'a floor the pad refuses to submit under' => ['submitPin() { if (this.pin.length >= 6) {', false, [6]],
    'the dot row Alpine iterates' => ['i in 10', true, [10]],
    'the announcement table the count indexes, both bounds of it' => ['range(0, 10)', false, [0, 10]],
    'the constant in each of those places' => ['if (this.pin.length < {{ AppLockPinShape::MAXIMUM_LENGTH }}) {', false, []],
    'the same loop taking its bound from the constant' => ['i in {{ AppLockPinShape::MAXIMUM_LENGTH }}', true, []],
    'the same table taking its bound from the constant' => ['range(0, AppLockPinShape::MAXIMUM_LENGTH)', false, [0]],
    'the digit keys a keypad prints' => ['[1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit', false, []],
    'the digit range a keystroke is tested against' => ["const k = \$event.key; if (k >= '0' && k <= '9') {", false, []],
    'the dot the row paints, which is not a count' => ["i <= pin.length ? 'bg-slate-900' : 'bg-slate-200'", false, []],
    'a trailing slice, which counts from an end rather than to a length' => ['this.pin = this.pin.slice(0, -1);', false, []],
    'an arrow, which is not a comparison' => ["'digit' => 6, 'max' => 10", false, []],
    'a property read, which is not a comparison either' => ['$element->offset > $start', false, []],
    'the in inside the pad\'s own state name' => ['this.pin += 10', true, []],
]);

// The reader is only worth having if it is pointed at the pads, so the
// derivation gets its own control: a template that accumulates a PIN is on the
// path, and one that merely says the word is not.
it('tells a template that accumulates a PIN from one that only mentions one', function (): void {
    $reads = static fn (string $source): bool => array_filter(
        PIN_PAD_STATE,
        static fn (string $state): bool => str_contains($source, $state),
    ) !== [];

    expect($reads('<template x-for="i in 10"><span x-bind:class="i <= pin.length"></span></template>'))
        ->toBeTrue('a template painting the dot row off the accumulating PIN is a pad');

    expect($reads('<button x-on:click="submitPin()">OK</button>'))
        ->toBeTrue('a template sending the accumulated PIN is a pad');

    expect($reads('<p>{{ Lang::get(\'auth::app_lock.pin_row_label\') }}</p>'))
        ->toBeFalse('a template naming a PIN in a label draws no pad and bounds no length');
});
