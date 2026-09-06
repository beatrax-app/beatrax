<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\PatternScanFailedException;
use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\ReplaceReturnSites;

// `preg_replace` returns null and `preg_split` returns false when PCRE stops
// part-way -- a JIT stack, backtrack or recursion limit, or a subject the
// pattern's encoding cannot read. Neither value is a plausible success value,
// so the failure is knowable; the cost is that the two shortest ways to read it
// throw the knowledge away. `(string) null` is `''`, so a cleaning step that
// gives up does not fail to clean the subject -- it deletes it. `?: []` turns a
// failed split into "this input had no parts".
//
// The sibling rule for `preg_match` is ARegexThatNeverRanIsNotNoMatchArchTest,
// and the guard tree's own copy is AStoppedScanIsNeverReadAsAnEmptyOneArchTest.
// This one covers the code that ships.
//
// The sibling steps over PatternScan; this rule does not, and that is deliberate.
// The seam compares every replacer answer with `=== null` or `=== false` a line
// below, which is the reading asked for here, so a step-over would excuse nothing
// while hiding the one file this rule's own advice points at.

// A walk that read nothing reports the same clean tree as a walk that found
// nothing, so both denominators are asserted before the verdict: the walk opens
// 6,667 files and the reader recognises 79 replacer calls in them.
const REPLACE_NEVER_RAN_FILE_FLOOR = 1_000;

const REPLACE_NEVER_RAN_CALL_FLOOR = 25;

it('leaves no preg_replace or preg_split whose failure reaches the program as an empty answer', function (): void {
    $files = ReplaceReturnSites::files();

    $offenders = [];
    $calls = 0;
    $root = base_path().'/';

    foreach ($files as $path) {
        $relative = str_replace($root, '', $path);
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        if (! str_contains($source, 'preg_replace') && ! str_contains($source, 'preg_split')) {
            continue;
        }

        $calls += ReplaceReturnSites::callsIn($source);

        foreach (ReplaceReturnSites::uncheckedIn($source) as $site) {
            $offenders[] = $relative.':'.$site['line'].'  '.$site['call'].'(…) '.$site['followedBy'];
        }
    }

    expect(count($files))->toBeGreaterThan(
        REPLACE_NEVER_RAN_FILE_FLOOR,
        'The walk opened '.count($files).' files, so its verdict covers only part of the tree.'
    );

    expect($calls)->toBeGreaterThan(
        REPLACE_NEVER_RAN_CALL_FLOOR,
        'The reader recognised '.$calls.' replacer calls in '.count($files)
        .' files, which is what a broken tokeniser looks like: a walk finding nothing cannot report anything.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These calls let a PCRE give-up reach the program as an ordinary empty answer:',
        '',
        ...array_slice($offenders, 0, 40),
        '',
        'Take the checked reading: Modules\Core\Public\Support\PatternScan::replace(),',
        '::replaceCallback() or ::split() run the pattern and raise when PCRE gave up.',
        '',
        'A `(string)` or `(array)` cast is refused because it spells the give-up as an',
        'empty subject, and `?? \'\'` / `?: []` because they spell it as an empty answer.',
        'A fallback that names a real value is accepted -- `?? $subject` degrades to the',
        'text uncleaned, which is a wrong answer somebody investigates rather than a',
        'silent one. So are `=== null` / `=== false`, `is_string()` / `is_array()`, and',
        '`??=`, each of which separates the failure from the answer.',
    ]));
});

// The rule above sends every contributor it stops to one class. That advice is
// only worth giving while the class really raises, so the promise in the failure
// message is asserted here rather than left to the module that declares it.
it('sends the reader to a seam that raises instead of blanking the subject', function (string $call): void {
    $restore = (string) ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        $subject = str_repeat('a', 40).'b';

        expect(fn () => $call === 'replace'
            ? PatternScan::replace('/(a+)+$/', 'x', $subject)
            : PatternScan::split('/(a+)+$/', $subject))
            ->toThrow(PatternScanFailedException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $restore);
    }
})->with(['replace', 'split']);

// A guard that cannot go red is a guard that says nothing. These are the shapes
// it was written for, checked against the reader rather than against the tree,
// so a rewrite of the tokeniser cannot quietly stop finding them.
it('finds the shapes it was written for and leaves the checked readings alone', function (string $body, bool $flagged): void {
    $found = ReplaceReturnSites::uncheckedIn('<?php '.$body);

    expect($found !== [])->toBe($flagged);
})->with([
    'a string cast' => ['return (string) preg_replace($p, "", $s);', true],
    'an array cast' => ['return (array) preg_split($p, $s);', true],
    'a coalesce to the empty string' => ['return preg_replace($p, "", $s) ?? "";', true],
    'an elvis to the empty array' => ['return preg_split($p, $s) ?: [];', true],
    'a coalesce to the empty array' => ['return preg_split($p, $s) ?? [];', true],
    'a coalesce to null' => ['return preg_replace($p, "", $s) ?? null;', true],
    'an argument nobody checked' => ['return strlen(preg_replace($p, "", $s));', true],
    'a callback replace, cast' => ['return (string) preg_replace_callback($p, $f, $s);', true],
    'an assignment nothing ever tests' => ['$x = preg_replace($p, "", $s); return $x;', true],
    'a variable tested only inside a later function' => ['$x = preg_replace($p, "", $s); return $x; } function later($x) { return $x === null;', true],
    'a fallback that names the subject' => ['return preg_replace($p, "", $s) ?? $s;', false],
    'the failure itself' => ['$x = preg_replace($p, "", $s); if ($x === null) { throw new RuntimeException("x"); } return $x;', false],
    'the failed split' => ['$x = preg_split($p, $s); return $x === false ? [] : $x;', false],
    'a type test' => ['$x = preg_replace($p, "", $s); return is_string($x) ? $x : $s;', false],
    'a split type test' => ['$x = preg_split($p, $s); return is_array($x) ? $x : [$s];', false],
    'a coalescing assignment on the next line' => ['$x = preg_replace($p, "", $s); $x ??= $s; return $x;', false],
    'a check past the branch that assigned it' => ['if ($a) { $x = preg_replace($p, "", $s); } else { $x = preg_replace($q, "", $s); } if ($x === null) { return ""; } return $x;', false],
    'the comparison written the other way round' => ['if (null === preg_replace($p, "", $s)) { return ""; } return $s;', false],
    'a method that happens to share the name' => ['return $this->preg_replace($p, $s);', false],
    'the name inside a string' => ['$hint = "call preg_replace here";', false],
    'the name inside a comment' => ['// preg_split($p, $s); is what this used to do', false],
]);
