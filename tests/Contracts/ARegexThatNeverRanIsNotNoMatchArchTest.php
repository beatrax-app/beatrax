<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\PatternScanFailedException;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RegexReturnSites;

// `preg_match` and `preg_match_all` return false when PCRE stops part-way --
// a JIT stack, backtrack or recursion limit, or a subject the pattern's
// encoding cannot read -- and they leave the match array empty when they do.
// A call site that throws the return away and reads $matches therefore cannot
// tell "found nothing" from "never ran", and the dangerous direction is the
// silent one: a guard that ran out of JIT stack reports a clean tree.
//
// It has happened here twice. This is the rule that stops the third.

it('leaves no preg_match whose answer cannot tell a failed scan from an empty one', function (): void {
    $files = RegexReturnSites::files();
    expect($files)->not->toBe([]);

    $offenders = [];
    $root = base_path().'/';

    foreach ($files as $path) {
        $relative = str_replace($root, '', $path);

        if ($relative === RegexReturnSites::SEAM) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'preg_match')) {
            continue;
        }

        foreach (RegexReturnSites::uncheckedIn($source) as $site) {
            $offenders[] = $relative.':'.$site['line'].'  '.$site['call'].'(…) '.$site['followedBy'];
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These calls read a PCRE answer that may be `false`, in a position where false is',
        'indistinguishable from "no match":',
        '',
        ...array_slice($offenders, 0, 40),
        '',
        'Take the checked reading: Modules\Core\Public\Support\PatternScan::first(), ::all(),',
        '::sets(), ::matches() or ::count() run the scan and raise when PCRE gave up.',
        '',
        'Two written forms are accepted instead, and only these two, because both separate',
        'the failure from the empty answer: `=== 1` / `!== 1` (exactly one match; anything',
        'else, failure included, is not a match) and `=== false` / `!== false` (the failure',
        'itself, handled). Either may sit against the call or against a variable the call',
        'was assigned to, up to the end of the function. `> 0`, `=== 0`, `(bool)`, `!` and a',
        'bare `if` all fold false into the answer, and a discarded return folds it into',
        '$matches.',
    ]));
});

// The rule above sends every contributor it stops to one class. That advice is
// only worth giving while the class really raises, so the promise in the failure
// message is asserted here rather than left to the module that declares it.
it('sends the reader to a seam that raises instead of handing back an empty answer', function (): void {
    $restore = (string) ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000');

    try {
        expect(fn () => PatternScan::all('/(a+)+$/', str_repeat('a', 40).'b'))
            ->toThrow(PatternScanFailedException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $restore);
    }
});

// A guard that cannot go red is a guard that says nothing. These are the shapes
// it was written for, checked against the reader rather than against the tree,
// so a rewrite of the tokeniser cannot quietly stop finding them.
it('finds the shape it was written for and leaves the two checked forms alone', function (string $body, bool $flagged): void {
    $found = RegexReturnSites::uncheckedIn('<?php '.$body);

    expect($found !== [])->toBe($flagged);
})->with([
    'a discarded return' => ['preg_match_all($p, $s, $m); return $m[0];', true],
    'a bare if' => ['if (preg_match($p, $s)) { return 1; }', true],
    'a negation' => ['if (! preg_match($p, $s)) { return 1; }', true],
    'a bool cast' => ['return (bool) preg_match($p, $s);', true],
    'a count above zero' => ['return preg_match_all($p, $s, $m) > 0;', true],
    'a count against zero' => ['return preg_match_all($p, $s, $m) === 0;', true],
    'an unchecked assignment' => ['$n = preg_match($p, $s, $m); return $m;', true],
    'an assignment read only for truth' => ['$n = preg_match($p, $s, $m); if ($n) { return $m; } return null;', true],
    'an assignment compared above zero' => ['$n = preg_match_all($p, $s, $m); if ($n > 0) { return $m; } return null;', true],
    'an assignment whose only reader is the next function' => [
        'function a() { $n = preg_match($p, $s, $m); return $m; } function b(int $n): bool { return $n === 1; }',
        true,
    ],
    'an argument to an assertion' => ['expect(preg_match($p, $s))->toBe(1);', true],
    'exactly one match' => ['if (preg_match($p, $s, $m) === 1) { return $m[1]; }', false],
    'not exactly one match' => ['if (preg_match($p, $s) !== 1) { return null; }', false],
    'the failure itself' => ['if (preg_match_all($p, $s, $m) === false) { throw new RuntimeException("x"); }', false],
    'the failure, negated' => ['if (preg_match_all($p, $s, $m) !== false) { return $m; }', false],
    'the same comparison written the other way round' => ['if (1 === preg_match($p, $s, $m)) { return $m; }', false],
    'the failure, assigned and raised on a line below' => [
        '$found = preg_match($p, $s); if ($found === false) { throw new RuntimeException("x"); } return $found === 1;',
        false,
    ],
    'the count, assigned and raised on a line below' => [
        '$hit = preg_match_all($p, $s, $m); if ($hit === false) { throw new RuntimeException("x"); } return $m[1];',
        false,
    ],
    'a match count assigned and compared with one below' => [
        '$n = preg_match($p, $s, $m); if ($n === 1) { return $m; } return null;',
        false,
    ],
    'a method that happens to share the name' => ['return $this->preg_match($p, $s);', false],
    'the name inside a string' => ['$hint = "call preg_match here";', false],
    'the name inside a comment' => ['// preg_match($p, $s, $m); is what this used to do', false],
]);

// The rule above is exactly as wide as the walk under it, and the walk is a
// hand-written list of directory names. A list like that does not go wrong
// when it is written; it goes wrong when the tree grows a directory and nobody
// remembers the list exists. So the walk is asked to account for the tree
// rather than trusted to describe it: a top-level directory holding PHP is
// either scanned or named as somebody else's to scan, and there is no third
// answer that passes.
it('opens every top-level directory that holds PHP, or names who does', function (): void {
    expect(RegexReturnSites::unscannedRootsHoldingPhp())->toBe([], implode("\n", [
        'These directories hold PHP that the unchecked-preg_match walk never opens, so a',
        'call site in them is unguarded and reads as clean:',
        '',
        ...RegexReturnSites::unscannedRootsHoldingPhp(),
        '',
        'Add each to RegexReturnSites::SCANNED_ROOTS, or to ROOTS_COVERED_ELSEWHERE with',
        'the reason another walk reaches it.',
    ]));
});

// A walk that reached nothing would satisfy every assertion above it, and the
// two lists are only meaningful if the names in them are real. Both are held
// against the filesystem so that deleting a directory is as loud as adding one.
it('names only directories that exist, and reaches the files it claims', function (): void {
    $missing = array_values(array_filter(
        RegexReturnSites::SCANNED_ROOTS,
        static fn (string $root): bool => ! is_dir(base_path($root)),
    ));

    expect($missing)->toBe([], 'SCANNED_ROOTS names directories that are not there: '.implode(', ', $missing));

    $files = RegexReturnSites::files();
    expect(count($files))->toBeGreaterThan(8000);

    $roots = [];
    foreach ($files as $path) {
        $roots[explode('/', str_replace(base_path().'/', '', $path))[0]] = true;
    }

    expect(array_keys($roots))->toContain('Modules', 'tests', '.claude');
});
