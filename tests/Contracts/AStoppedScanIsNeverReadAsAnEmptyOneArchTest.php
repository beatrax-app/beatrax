<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\PatternScanFailedException;
use Modules\Core\Public\Support\PatternScan;

// preg_match_all answers false -- not zero -- when the PCRE engine gives up on
// a backtrack or JIT stack limit, and it leaves the matches out-parameter empty
// when it does. A guard that ignores the return value therefore reports a clean
// tree over a scan that never ran, which is the one answer a guard must never
// give, and this tree has already shipped one. PatternScan is where that false
// becomes a throw, so it is the only place allowed to call the matchers
// directly.
//
// Read with the tokeniser rather than with a pattern of its own: a regex scan
// of the regex scanners would be the very thing being guarded against, and
// would answer "nothing found" on the day it stopped reading.
//
// ARegexThatNeverRanIsNotNoMatchArchTest holds the whole tree to a weaker rule:
// it accepts `=== 1` and `=== false` beside the seam, because both spellings
// separate a give-up from an empty answer at the point they are written. The
// rules below are the guard tree's own, and they are stricter in three places
// that a repository-wide rule cannot afford to be.
//
// First, preg_match_all is barred outright here rather than merely checked.
// Its backtracking accumulates across a whole subject, so a file that grows
// crosses the limit -- that is the failure this tree has actually shipped --
// and `=== false` then `continue` is the shape it shipped as: the file leaves
// the walk and nothing in the output says which files were left. The 309
// single-shot preg_match reads left raw stop at the first hit; they are held
// to the two rules below and to the tree-wide one, not to this.
//
// Second, the replacers are covered at all. A stripper that gives up answers
// null; `preg_replace(...) ?? $source` degrades to scanning the unstripped
// text, which biases toward a FALSE POSITIVE somebody investigates.
// `(string) preg_replace(...)` and `?? ''` hand the scan an empty subject
// instead, which is a guaranteed silent green, so those two spellings are the
// rule and the tolerant one is deliberately left alone.
//
// Third, the walk asserts its own denominator before it reports.
// @link ../../.docs/conventions/arch-invariants.md#a-walk-that-stops-reading-must-say-so

const STOPPED_SCAN_SEAM = 'Modules/Core/Public/Support/PatternScan.php';

const STOPPED_SCAN_PCRE = ['preg_match_all', 'preg_match', 'preg_replace', 'preg_replace_callback'];

/** @return list<string> every file the repository's guards are written in */
function stoppedScanGuardFiles(): array
{
    $files = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests/Contracts'), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $files[] = $file->getPathname();
        }
    }

    foreach ((array) glob(base_path('Modules/*/tests/Arch/*.php')) as $path) {
        $files[] = (string) $path;
    }

    sort($files);

    return $files;
}

/**
 * The significant tokens of $source, each as its id (null for punctuation), its
 * text and its line.
 *
 * @return list<array{0: int|null, 1: string, 2: int}>
 */
function stoppedScanTokens(string $source): array
{
    $significant = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = [$token[0], $token[1], $token[2]];

            continue;
        }

        $significant[] = [null, $token, 0];
    }

    return $significant;
}

/**
 * Every direct call to one of the PCRE functions, paired with what the caller
 * does with the answer: discards it -- the call is a statement of its own --
 * or turns a give-up into an empty subject, by casting it to string or
 * coalescing it to ''.
 *
 * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
 * @return list<array{name: string, line: int, discarded: bool, emptied: bool}>
 */
function stoppedScanMatcherCalls(array $tokens): array
{
    $calls = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] !== T_STRING || ! in_array($tokens[$i][1], STOPPED_SCAN_PCRE, true)) {
            continue;
        }

        $next = $tokens[$i + 1] ?? [null, '', 0];

        if ($next[0] !== null || $next[1] !== '(') {
            continue;
        }

        // A method or a declaration of the same name is a different symbol.
        $previous = $tokens[$i - 1] ?? [null, '', 0];

        if (in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }

        $depth = 0;
        $close = null;

        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j][0] !== null) {
                continue;
            }
            if ($tokens[$j][1] === '(') {
                $depth++;
            } elseif ($tokens[$j][1] === ')') {
                $depth--;
                if ($depth === 0) {
                    $close = $j;
                    break;
                }
            }
        }

        if ($close === null) {
            continue;
        }

        $after = $tokens[$close + 1] ?? [null, '', 0];
        $opens = ($previous[0] === null && in_array($previous[1], ['{', '}', ';'], true)) || $previous[0] === T_OPEN_TAG;
        $ends = $after[0] === null && $after[1] === ';';

        $fallback = $tokens[$close + 2] ?? [null, '', 0];
        $emptied = $previous[0] === T_STRING_CAST
            || ($after[0] === T_COALESCE && in_array($fallback[1], ["''", '""'], true));

        $calls[] = [
            'name' => $tokens[$i][1],
            'line' => $tokens[$i][2],
            'discarded' => $opens && $ends,
            'emptied' => $emptied,
        ];
    }

    return $calls;
}

// A sweep that reads nothing reports the same clean tree as a sweep that found
// nothing, which is the failure this whole file exists to name. Both counts are
// therefore asserted before either verdict is read: the walk below sees 209
// files holding 357 PCRE calls, and the floors sit far enough under those that
// only a broken walk or a broken tokeniser trips them.
const STOPPED_SCAN_FILE_FLOOR = 150;

const STOPPED_SCAN_CALL_FLOOR = 100;

/**
 * Every PCRE call in the guard tree, keyed by the file and line it sits on,
 * beside the number of files that answer for. The seam is not in this walk --
 * it lives in Modules/Core/Public -- and is read on its own below.
 *
 * @return array{files: int, calls: list<array{file: string, name: string, line: int, discarded: bool, emptied: bool}>}
 */
function stoppedScanTree(): array
{
    $files = 0;
    $calls = [];

    foreach (stoppedScanGuardFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $files++;

        foreach (stoppedScanMatcherCalls(stoppedScanTokens((string) file_get_contents($path))) as $call) {
            $calls[] = ['file' => $relative, ...$call];
        }
    }

    return ['files' => $files, 'calls' => $calls];
}

it('reads the whole guard tree before it reports on any of it', function (): void {
    $tree = stoppedScanTree();

    expect($tree['files'])->toBeGreaterThan(
        STOPPED_SCAN_FILE_FLOOR,
        'The guard-file walk found '.$tree['files'].' files, so the two sweeps below would pass over almost nothing.'
    );

    expect(count($tree['calls']))->toBeGreaterThan(
        STOPPED_SCAN_CALL_FLOOR,
        'The tokeniser found '.count($tree['calls']).' matcher calls in '.$tree['files']
        .' files, which is too few to have read them: a sweep finding nothing cannot report anything.'
    );
});

it('routes every whole-subject scan in the guard tree through the seam that can throw', function (): void {
    $offenders = [];

    foreach (stoppedScanTree()['calls'] as $call) {
        if ($call['name'] === 'preg_match_all') {
            $offenders[] = $call['file'].':'.$call['line'];
        }
    }

    expect($offenders)->toBe(
        [],
        'These call preg_match_all directly, so a scan that stops reading is read as a scan that found nothing. '
        .'Call PatternScan::all(), PatternScan::sets(), PatternScan::allWithOffsets(), '
        ."PatternScan::setsWithOffsets() or PatternScan::count() instead:\n  "
        .implode("\n  ", $offenders)
    );
});

it('never turns a scan that gave up into an empty subject', function (): void {
    $offenders = [];

    foreach (stoppedScanTree()['calls'] as $call) {
        if ($call['emptied']) {
            $offenders[] = $call['file'].':'.$call['line'].' — '.$call['name'];
        }
    }

    expect($offenders)->toBe(
        [],
        'These cast a PCRE answer to string or coalesce it to an empty string, so an engine that gave up hands '
        .'the scan below an empty subject and it reports the file clean. Call PatternScan::replace() or '
        ."PatternScan::replaceCallback(), which throw instead:\n  ".implode("\n  ", $offenders)
    );
});

it('never throws away what a matcher answered', function (): void {
    $offenders = [];

    foreach (stoppedScanTree()['calls'] as $call) {
        if ($call['discarded']) {
            $offenders[] = $call['file'].':'.$call['line'].' — '.$call['name'];
        }
    }

    expect($offenders)->toBe(
        [],
        'These discard what the matcher answered, so false -- the engine giving up -- reads as an empty result. '
        ."Take the seam's return value instead:\n  ".implode("\n  ", $offenders)
    );
});

it('leaves the seam itself the one place the PCRE functions are called raw', function (): void {
    $source = (string) file_get_contents(base_path(STOPPED_SCAN_SEAM));

    $names = [];

    foreach (stoppedScanMatcherCalls(stoppedScanTokens($source)) as $call) {
        $names[$call['name']] = true;
        expect($call['discarded'])->toBeFalse(STOPPED_SCAN_SEAM.':'.$call['line'].' discards its own answer.');
        expect($call['emptied'])->toBeFalse(STOPPED_SCAN_SEAM.':'.$call['line'].' empties its own subject.');
    }

    expect(array_keys($names))->toEqualCanonicalizing(
        STOPPED_SCAN_PCRE,
        'The seam no longer wraps every PCRE function this guard holds to a rule elsewhere.'
    );

    // A subject that gives up rather than answering is what the seam exists
    // for, so it is checked against a real one: the nested quantifier below
    // exhausts the backtrack limit instead of returning zero matches.
    expect(static fn (): array => PatternScan::all('/(?:a+)+$/', str_repeat('a', 100_000).'b'))
        ->toThrow(PatternScanFailedException::class)
        ->and(static fn (): string => PatternScan::replace('/(?:a+)+$/', '', str_repeat('a', 100_000).'b'))
        ->toThrow(PatternScanFailedException::class);
});
