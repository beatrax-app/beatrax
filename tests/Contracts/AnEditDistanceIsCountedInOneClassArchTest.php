<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/architecture/chain-resolution.md
 */

// `levenshtein()` counts BYTES. Divide its answer by a name's CHARACTER length
// and every letter outside ASCII costs two, so the same difference scores half
// as much in Greek or Cyrillic as in Latin — `netflix`/`netflix int` scored
// 0.636 and `νετφλιξ`/`νετφλιξ ιντ` scored 0.364 against a 0.6 threshold.

// Two call sites had it, one fixed in place and one not. `EditDistance` is now
// the single implementation, which is the only reason this is guardable: a
// rule may ban the raw function outright rather than trying to read whether
// the divisor beside it counts the same unit.
const EDIT_DISTANCE_HOME = 'Modules/Core/Public/Support/EditDistance.php';

/**
 * @return list<array{file:string,line:int}>
 */
function editDistanceRawCalls(): array
{
    $found = [];

    /** @var SplFileInfo $entry */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))) as $entry) {
        $path = $entry->getPathname();

        if (! $entry->isFile() || $entry->getExtension() !== 'php' || str_contains($path, '/tests/')) {
            continue;
        }

        // Tokenised, not grepped: the fix left the word in two explanatory
        // comments, and a rule that cannot tell a mention from a call would
        // have reported the comment describing the defect as the defect.
        $tokens = SonarSourceFiles::tokens((string) file_get_contents($path));

        foreach ($tokens as $index => $token) {
            if ($token[0] === T_STRING && strtolower($token[1]) === 'levenshtein' && ($tokens[$index + 1][1] ?? '') === '(') {
                $found[] = ['file' => str_replace(base_path().'/', '', $path), 'line' => $token[2]];
            }
        }
    }

    return $found;
}

it('counts an edit distance in the one class that knows what it counted', function (): void {
    $calls = editDistanceRawCalls();
    $offenders = [];

    foreach ($calls as $call) {
        if ($call['file'] !== EDIT_DISTANCE_HOME) {
            $offenders[] = $call['file'].':'.$call['line'];
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'levenshtein() counts bytes. Anything that divides it by a length, compares it to a',
        'threshold, or reports it as a similarity is asking a question about characters, and',
        'the two disagree for every letter outside ASCII — which is every reader whose',
        'merchants are not spelled in Latin. Call Modules\Core\Public\Support\EditDistance',
        'instead; it decides the unit once:',
        ...$offenders,
    ]));

    // The one legitimate call is the positive control. If it stops being found,
    // the walk has moved off the tree and an empty offender list means nothing.
    expect(array_column($calls, 'file'))->toBe([EDIT_DISTANCE_HOME]);
});

it('reads a call and not the two comments that name the function', function (): void {
    $call = SonarSourceFiles::tokens('<?php $d = levenshtein($a, $b);');
    $mention = SonarSourceFiles::tokens('<?php // the fuzzy arm divided levenshtein()\'s BYTE distance');

    $names = static fn (array $tokens): array => array_values(array_filter(
        array_map(static fn (array $t): string => $t[1], $tokens),
        static fn (string $text): bool => strtolower($text) === 'levenshtein',
    ));

    expect($names($call))->toBe(['levenshtein'])
        ->and($names($mention))->toBe([]);
});
