<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\PatternScanFailedException;
use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\PcreCallSites;
use Tests\Contracts\Support\RegexReturnSites;

// `preg_match` and `preg_match_all` return false when PCRE stops part-way --
// a JIT stack, backtrack or recursion limit, or a subject the pattern's
// encoding cannot read -- and they leave the match array empty when they do.
// A call site that throws the return away and reads $matches therefore cannot
// tell "found nothing" from "never ran", and the dangerous direction is the
// silent one: a guard that ran out of JIT stack reports a clean tree.
//
// It has happened here twice. This is the rule that stops the third.

// A walk that read nothing reports the same clean tree as a walk that found
// nothing, so both denominators are asserted before the verdict below: the walk
// opens 9,655 PHP files and the reader recognises 573 matcher calls in them,
// far enough above the floors that only a narrowed walk trips them.
const REGEX_NEVER_RAN_FILE_FLOOR = 5_000;

const REGEX_NEVER_RAN_CALL_FLOOR = 200;

it('leaves no preg_match whose answer cannot tell a failed scan from an empty one', function (): void {
    $files = RegexReturnSites::files();

    $offenders = [];
    $calls = 0;
    $root = base_path().'/';

    foreach ($files as $path) {
        $relative = str_replace($root, '', $path);

        if ($relative === RegexReturnSites::SEAM) {
            continue;
        }

        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        if (! str_contains($source, 'preg_match')) {
            continue;
        }

        $tokens = PcreCallSites::significantTokens($source);

        foreach (array_keys($tokens) as $index) {
            $calls += PcreCallSites::callOpensAt($tokens, $index, RegexReturnSites::SCANNED_FUNCTIONS) === null ? 0 : 1;
        }

        foreach (RegexReturnSites::uncheckedIn($source) as $site) {
            $offenders[] = $relative.':'.$site['line'].'  '.$site['call'].'(…) '.$site['followedBy'];
        }
    }

    expect(count($files))->toBeGreaterThan(
        REGEX_NEVER_RAN_FILE_FLOOR,
        'The walk opened '.count($files).' files, so its verdict covers a fraction of the tree.'
    );

    expect($calls)->toBeGreaterThan(
        REGEX_NEVER_RAN_CALL_FLOOR,
        'The reader recognised '.$calls.' matcher calls in '.count($files)
        .' files, which is what a broken tokeniser looks like: a walk finding nothing reports nothing.'
    );

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

// The rule grants one exemption and it is a whole file, so it is held to the
// house rule for a pin: it has to still excuse something. PatternScan hands each
// raw matcher answer to `self::tally(…)` as an argument rather than comparing
// it, and the day that stops being true the step-over goes.
it('still needs the one file it steps over', function (): void {
    $seam = base_path(RegexReturnSites::SEAM);

    expect(is_file($seam))->toBeTrue(
        RegexReturnSites::SEAM.' is the file this rule steps over, and it is not there any more. '
        .'Delete the step-over, or point it at wherever the checked reading moved to.'
    );

    expect(RegexReturnSites::uncheckedIn((string) file_get_contents($seam)))->not->toBe(
        [],
        RegexReturnSites::SEAM.' no longer reads a matcher answer this rule would refuse, so the step-over above '
        .'excuses nothing while still hiding the whole file. Delete it and let the seam be walked like the rest.'
    );
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

// The walk's own account of the tree used to be asserted here. It moved to
// AScannerAccountsForTheWholeTreeArchTest, which holds every scanner to the
// same rule through RepoTree rather than this one to a list of its own.
