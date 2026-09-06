<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\ValueObjects\Money;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-locale-argument-passed-to-moneyformat
 */

/**
 * @return list<array{args: string, line: int}> every ->format(...) call, argument text intact
 */
function moneyFormatCalls(string $source): array
{
    $calls = [];
    $offset = 0;

    while (($start = strpos($source, '->format(', $offset)) !== false) {
        $cursor = $start + strlen('->format(');
        $depth = 1;

        while ($depth > 0 && $cursor < strlen($source)) {
            $depth += match ($source[$cursor]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $cursor++;
        }

        $calls[] = [
            'args' => substr($source, $start + strlen('->format('), $cursor - $start - strlen('->format(') - 1),
            'line' => substr_count($source, "\n", 0, $start) + 1,
        ];
        $offset = $cursor;
    }

    return $calls;
}

/**
 * Any locale anywhere in the argument list, not just a bare literal — a
 * computed one is the shape that survived the rule's first pass.
 *
 * @return list<array{locale: string, line: int}>
 */
function moneyFormatLocaleOffendersIn(string $source): array
{
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $source) ?? $source;
    $offenders = [];

    foreach (moneyFormatCalls($stripped) as $call) {
        $named = PatternScan::first('/[\'"]([a-z]{2}[_-][A-Z]{2})[\'"]/', $call['args']);

        if ($named !== []) {
            $offenders[] = ['locale' => (string) $named[1], 'line' => $call['line']];
        }
    }

    return $offenders;
}

it('hands format() no locale to override the reader\'s own', function (): void {
    // Every root that ships. The walk opened Modules/, app/ and resources/,
    // which left routes/, config/ and the second composer root's own config
    // outside a rule whose subject is "wherever an amount is rendered".
    $files = RepoTree::files(RepoTree::PRODUCTION_PHP);

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $calls = 0;
    $offenders = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(RepoTree::root().'/', '', $path);
        $calls += count(moneyFormatCalls($source));

        foreach (moneyFormatLocaleOffendersIn($source) as $offender) {
            $offenders[] = $relative.':'.$offender['line'].' — format(… '.$offender['locale'].' …)';
        }
    }

    // Read before the verdict: the walk is a balanced-paren reader rather than
    // a pattern, and one that stopped at the first file reports the same empty
    // list a clean tree does. The floor sits far under today's 154 calls.
    expect($calls)->toBeGreaterThan(
        50,
        'the walk found '.$calls.' ->format() calls, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A hardcoded locale renders every amount in one language's separators\n".
        "and symbol position, whoever is reading — nl_NL turns a German \n".
        "\"1.234,56 \u{20AC}\" into \"\u{20AC} 1.234,56\". Drop the argument: Money::format()\n".
        "already follows the active locale, on every runtime. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('gives Money::format() no locale parameter to pass in the first place', function (): void {
    $signature = new ReflectionMethod(Money::class, 'format');

    expect($signature->getNumberOfParameters())->toBe(
        0,
        'Money::format() takes the locale the reader is already on. A parameter '.
        'here is an invitation to override that per call site, which is exactly '.
        'how thirty of them came to render USD with Dutch separators.',
    );
});

// Thirty call sites were fixed and nothing was left to find, so the reader is
// driven against planted sources. The near-misses are the shapes that share the
// method name and must stay legible: a date format string, a locale in a
// comment, and a nested call whose closing paren the reader has to walk to.
it('finds a locale anywhere in the argument list, and nowhere else', function (): void {
    expect(moneyFormatLocaleOffendersIn('<?php echo $money->format(\'nl_NL\');'))
        ->toBe([['locale' => 'nl_NL', 'line' => 1]])
        ->and(moneyFormatLocaleOffendersIn('<?php echo $money->format($this->pick(\'de_DE\'), 2);'))
        ->toBe([['locale' => 'de_DE', 'line' => 1]])
        ->and(moneyFormatLocaleOffendersIn('<?php echo $date->format(\'Y-m-d\');'))->toBe([])
        ->and(moneyFormatLocaleOffendersIn('<?php echo $money->format();'))->toBe([])
        ->and(moneyFormatLocaleOffendersIn("<?php // \$money->format('nl_NL') used to be here\n"))->toBe([]);

    // The reader walks to the balanced close rather than the next `)`, so a
    // callable argument is one call and not two halves of one.
    expect(moneyFormatCalls('<?php $a->format(fn () => g(1, 2));'))
        ->toBe([['args' => 'fn () => g(1, 2)', 'line' => 1]]);
});
