<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\PatternScanFailedException;
use Modules\Core\Public\Support\PatternScan;

// A pattern that backtracks quadratically, against a subject that cannot match,
// under a limit low enough to be reached in microseconds. This is the whole of
// the failure: PCRE gives up part-way and reports it in a return value, which
// the language then makes indistinguishable from an honest empty answer.
const PATTERN_SCAN_RUNAWAY = '/(a+)+$/';

function patternScanUnmatchableSubject(): string
{
    return str_repeat('a', 40).'b';
}

beforeEach(function (): void {
    $this->restoreBacktrackLimit = (string) ini_get('pcre.backtrack_limit');
});

afterEach(function (): void {
    ini_set('pcre.backtrack_limit', $this->restoreBacktrackLimit);
});

it('leaves a failed scan looking exactly like an empty one when the return is discarded', function (): void {
    ini_set('pcre.backtrack_limit', '1000');

    $matches = [];
    $gaveUp = @preg_match_all(PATTERN_SCAN_RUNAWAY, patternScanUnmatchableSubject(), $matches) === false;

    expect($gaveUp)->toBeTrue()
        ->and(preg_last_error())->not->toBe(PREG_NO_ERROR)
        ->and($matches[0])->toBe([]);
});

it('raises rather than handing back the empty match set of a scan that stopped', function (): void {
    ini_set('pcre.backtrack_limit', '1000');

    expect(fn () => PatternScan::all(PATTERN_SCAN_RUNAWAY, patternScanUnmatchableSubject()))
        ->toThrow(PatternScanFailedException::class);
});

it('names the pattern and what PCRE said, so the reader is not left guessing which scan', function (): void {
    ini_set('pcre.backtrack_limit', '1000');

    try {
        PatternScan::first(PATTERN_SCAN_RUNAWAY, patternScanUnmatchableSubject());
        $raised = null;
    } catch (PatternScanFailedException $exception) {
        $raised = $exception;
    }

    expect($raised)->not->toBeNull()
        ->and($raised->pattern)->toBe(PATTERN_SCAN_RUNAWAY)
        ->and($raised->getMessage())->toContain('Backtrack limit exhausted');
});

it('raises from every reading, so no caller has an unchecked door', function (string $method): void {
    ini_set('pcre.backtrack_limit', '1000');

    $call = [PatternScan::class, $method];
    expect(is_callable($call))->toBeTrue();

    expect(fn () => $call(PATTERN_SCAN_RUNAWAY, patternScanUnmatchableSubject()))
        ->toThrow(PatternScanFailedException::class);
})->with(['matches', 'count', 'first', 'firstWithOffsets', 'all', 'allWithOffsets', 'sets', 'setsWithOffsets']);

// The dataset above is written out by hand, because the readings it drives all
// take (pattern, subject) and the rewriters do not. A reading added later would
// not join it on its own, and the case would keep its name while covering less
// than it says. This asks the class what it offers instead of trusting a list.
it('accounts for every public reading, so a new one cannot arrive untested', function (): void {
    $offered = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(PatternScan::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );

    sort($offered);

    // Two lists, because they are tested two ways: the readings by the dataset
    // above, the rewriters by the cases below it, which pass a replacement.
    expect($offered)->toBe([
        'all', 'allWithOffsets', 'count', 'first', 'firstWithOffsets', 'matches',
        'replace', 'replaceCallback', 'sets', 'setsWithOffsets', 'split',
    ]);
});

it('reads an ordinary subject the way the function it replaces does', function (): void {
    expect(PatternScan::matches('/\d+/', 'a1b'))->toBeTrue()
        ->and(PatternScan::matches('/\d+/', 'abc'))->toBeFalse()
        ->and(PatternScan::count('/\d/', 'a1b2c3'))->toBe(3)
        ->and(PatternScan::first('/(\d)(\w)/', 'x1yz')[2])->toBe('y')
        ->and(PatternScan::all('/(\d)/', 'a1b2')[1])->toBe(['1', '2'])
        ->and(PatternScan::sets('/(\d)/', 'a1b2'))->toBe([['1', '1'], ['2', '2']])
        ->and(PatternScan::first('/nope/', 'abc'))->toBe([]);
});

it('hands back the offsets the offset readings ask for', function (): void {
    expect(PatternScan::firstWithOffsets('/b/', 'abc')[0])->toBe(['b', 1])
        ->and(PatternScan::allWithOffsets('/b/', 'abcb')[0])->toBe([['b', 1], ['b', 3]])
        ->and(PatternScan::setsWithOffsets('/b/', 'abcb')[1][0])->toBe(['b', 3]);
});

// A rewrite that gives up answers null, and `(string) null` is `''`. The
// subject handed to whatever scans next is then empty, so it finds nothing and
// calls the file clean — the same defect as a wrong match count, and quieter,
// because there is no count to look wrong.
it('raises rather than blanking the subject when a replace stops part-way', function (): void {
    ini_set('pcre.backtrack_limit', '1000');

    $subject = patternScanUnmatchableSubject();
    $blanked = (string) @preg_replace(PATTERN_SCAN_RUNAWAY, 'x', $subject);

    expect($blanked)->toBe('', 'The cast this seam replaces deletes the subject rather than failing to clean it.')
        ->and(fn () => PatternScan::replace(PATTERN_SCAN_RUNAWAY, 'x', $subject))
        ->toThrow(PatternScanFailedException::class)
        ->and(fn () => PatternScan::replaceCallback(PATTERN_SCAN_RUNAWAY, static fn (): string => 'x', $subject))
        ->toThrow(PatternScanFailedException::class);
});

it('raises rather than reporting no parts when a split stops part-way', function (): void {
    ini_set('pcre.backtrack_limit', '1000');

    $subject = patternScanUnmatchableSubject();

    expect(@preg_split(PATTERN_SCAN_RUNAWAY, $subject) ?: [])
        ->toBe([], 'The elvis this seam replaces reads a give-up as an input with no parts.')
        ->and(fn () => PatternScan::split(PATTERN_SCAN_RUNAWAY, $subject))
        ->toThrow(PatternScanFailedException::class);
});

it('replaces and splits an ordinary subject the way the functions it replaces do', function (): void {
    expect(PatternScan::replace('/\d/', '#', 'a1b2'))->toBe('a#b#')
        ->and(PatternScan::replace(['/a/', '/b/'], ['x', 'y'], 'ab'))->toBe('xy')
        ->and(PatternScan::replaceCallback('/\d/', static fn (array $m): string => '['.$m[0].']', 'a1'))->toBe('a[1]')
        ->and(PatternScan::split('/,/', 'a,b,c'))->toBe(['a', 'b', 'c'])
        ->and(PatternScan::split('/,/', 'a,,b'))->toBe(['a', '', 'b'])
        ->and(PatternScan::split('/,/', 'nocomma'))->toBe(['nocomma']);
});
