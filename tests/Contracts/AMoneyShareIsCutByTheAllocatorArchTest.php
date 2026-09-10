<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

// Rounding each part of a split on its own drifts by up to half a minor unit
// per part, so the parts stop adding up to the whole they came from: three legs
// of a $30.00 card charge printed $10.00 + $10.00 + $9.99 in the column an
// accountant sums, and a 200 000-case fuzz drifted on a quarter of them. The
// allocator cuts the shares and hands the remainder back to the same set the
// rounding took it from.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam

const MONEY_ALLOCATOR = 'Modules/FX/Public/Services/CrossCurrencyTotal.php';

// Integer-truncating arithmetic on a minor-unit figure, outside the allocator.
// Each entry is one that does not cut a whole into parts, and says what it does
// instead; `proves` re-checks that against the code.
const MONEY_SHARE_PINS = [
    'Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php' => [
        'reason' => 'a matching tolerance around a settlement, used to decide whether two rows are the same payment',
        'proves' => '/AMOUNT_BAND_PERCENT/',
    ],
    'Modules/Chains/Public/Support/SettlementTolerance.php' => [
        'reason' => 'how far a statement may be off before it stops counting as paid; a threshold, not a share of anything',
        'proves' => '/FLOOR_MINOR/',
    ],
    'Modules/Counterparties/Resources/views/livewire/profile-tabs/bank.blade.php' => [
        'reason' => 'a bar width rather than a slice: the numerator is one row magnitude and the denominator the largest magnitude on the panel, and the integer it rounds to never leaves the width it draws',
        'proves' => '/width: \{\{ \$pct \}\}%/',
    ],
    'Modules/Recurring/Internal/Detectors/DetectedSeries.php' => [
        'reason' => 'a cadence rewritten as its monthly equivalent, which is a rate and not a slice: nothing else has to add back up to the yearly figure',
        'proves' => '/SeriesCadence::Quarterly/',
    ],
    'Modules/Recurring/Internal/Queries/RecurringSeriesProjector.php' => [
        'reason' => 'a float multiplier that only ever builds an ORDER BY key, so the ordering is what it decides and no figure is stored or shown from it',
        'proves' => '/ORDER BY|orderBy/',
    ],
];

/**
 * Every place a minor-unit figure is multiplied or divided and then truncated
 * to an integer, as path => the expression text, beside how many truncating
 * expressions of any kind the walk read to find them.
 *
 * A bare `$minor` is not one of these: the identifier has to READ as money
 * (`$latestAmountMinor`, `settled_amount_minor`, `pointMinor`), or a semantic
 * version's minor component counts as a share of a card charge.
 *
 * @return array{cuts: list<array{path: string, line: int, expression: string}>, read: int}
 */
function moneyShareCuts(): array
{
    $cuts = [];
    $read = 0;

    foreach (BackendSourceFiles::all() as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);
        $read += moneyShareTruncationsIn($tokens);
        $cuts = array_merge($cuts, moneyShareCutsIn(
            str_replace(base_path().'/', '', $path),
            $tokens,
        ));
    }

    return ['cuts' => $cuts, 'read' => $read];
}

// Every truncation the walk saw, money or not. The offender list is floored on
// this rather than on its own length: a tree that grows more correct shrinks
// the second, so a floor there falls to a fix and reads as a walk that stopped.
/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function moneyShareTruncationsIn(array $tokens): int
{
    $read = 0;

    foreach ($tokens as $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_INT_CAST || ($token[0] === T_STRING && $token[1] === 'intdiv')) {
            $read++;
        }
    }

    return $read;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<array{path: string, line: int, expression: string}>
 */
function moneyShareCutsIn(string $relative, array $tokens): array
{
    $cuts = [];
    $count = count($tokens);
    $texts = array_map(
        static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
        $tokens,
    );

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i])) {
            continue;
        }

        $isIntdiv = $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'intdiv';
        $isCast = $tokens[$i][0] === T_INT_CAST;

        if (! $isIntdiv && ! $isCast) {
            continue;
        }

        $expression = $isIntdiv
            ? moneyShareCallArguments($texts, $i)
            : moneyShareCastOperand($texts, $i);

        if (preg_match('/[A-Za-z_]+_?[Mm]inor\b/', $expression) !== 1) {
            continue;
        }
        if (preg_match('#[*/]#', $expression) !== 1) {
            continue;
        }

        $cuts[] = [
            'path' => $relative,
            'line' => $tokens[$i][2],
            'expression' => trim(preg_replace('/\s+/', ' ', $expression) ?? $expression),
        ];
    }

    return $cuts;
}

/**
 * @param  list<string>  $texts
 */
function moneyShareCallArguments(array $texts, int $index): string
{
    $depth = 0;
    $arguments = '';

    for ($i = $index + 1, $count = count($texts); $i < $count; $i++) {
        $text = $texts[$i];

        if ($text === '(') {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }

        $arguments .= $text;
    }

    return $arguments;
}

// Up to the end of the cast expression: the comma or semicolon that closes it,
// or the bracket that closes whatever it was written inside.
/**
 * @param  list<string>  $texts
 */
function moneyShareCastOperand(array $texts, int $index): string
{
    $depth = 0;
    $operand = '';

    for ($i = $index + 1, $count = count($texts); $i < $count; $i++) {
        $text = $texts[$i];

        if (in_array($text, ['(', '['], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']'], true)) {
            $depth--;
            if ($depth < 0) {
                break;
            }
        } elseif (($text === ';' || $text === ',') && $depth <= 0) {
            break;
        }

        $operand .= $text;
    }

    return $operand;
}

it('cuts a money share in the one place that hands the remainder back', function (): void {
    $walk = moneyShareCuts();

    // A walk that reads nothing finds no arithmetic and reports a clean tree.
    expect($walk['read'])->toBeGreaterThan(300, 'Read '.$walk['read'].' truncating expressions, too few for an empty offender list to mean anything.');

    $offenders = [];
    $pinned = [];

    foreach ($walk['cuts'] as $cut) {
        if ($cut['path'] === MONEY_ALLOCATOR) {
            continue;
        }

        if (array_key_exists($cut['path'], MONEY_SHARE_PINS)) {
            $pinned[$cut['path']] = true;

            continue;
        }

        $offenders[] = $cut['path'].':'.$cut['line'].'  '.$cut['expression'];
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A whole cut into parts is CrossCurrencyTotal::apportion(); a whole derived',
        'from the parts and converted is distribute(). Both round the parts and then',
        'spread what the rounding lost back over the same set, so the parts sum to',
        'the whole exactly. Rounding a part here loses that. Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(
        array_keys(MONEY_SHARE_PINS),
        'A pinned cut is no longer reached by the rule it was written for, so the entry excuses nothing and goes.',
    );
});

it('still holds each pinned cut to the reason it was granted for', function (): void {
    foreach (MONEY_SHARE_PINS as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

it('reads a minor-unit figure cut into parts, and leaves a figure that is not money alone', function (): void {
    $source = <<<'PHP'
        <?php
        final class PlantedShare
        {
            public function cut(int $totalMinor, int $share, int $parts): int
            {
                return intdiv($totalMinor * $share, $parts);
            }

            public function version(int $minor, int $parts): int
            {
                return intdiv($minor * 2, $parts);
            }

            public function whole(int $totalMinor): int
            {
                return (int) $totalMinor;
            }
        }
        PHP;

    expect(array_column(moneyShareCutsIn('Planted.php', BackendSourceFiles::tokensOf('Planted.php', $source)), 'expression'))->toBe(
        ['$totalMinor * $share, $parts'],
        'a minor-unit figure multiplied and truncated is the cut this rule is about; a semantic version minor '
        .'is not money, and a cast with no arithmetic in it divides nothing',
    );
});

it('keeps that one allocator answering more than one module', function (): void {
    $callers = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === MONEY_ALLOCATOR) {
            continue;
        }

        $source = (string) file_get_contents($path);
        if (preg_match('/(::|->)(apportion|distribute)\(/', $source) !== 1) {
            continue;
        }

        $callers[explode('/', $relative)[1]] = true;
    }

    $modules = array_keys($callers);
    sort($modules);

    // Two modules cutting a whole into parts is what makes this a seam rather
    // than a helper one caller happens to own. Tax splits a native amount over
    // its legs; Reports converts a currency's subtotal over its rows.
    expect(count($modules))->toBeGreaterThan(1, implode("\n  ", [
        'CrossCurrencyTotal is the shared allocator, and it is shared only while',
        'more than one module reaches it. A single caller means the arithmetic has',
        'been re-inlined somewhere and the seam is on its way back to being local.',
        'Modules calling it: '.implode(', ', $modules),
    ]));
});

it('leaves the remainder spread with nowhere else to be written', function (): void {
    $offenders = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === MONEY_ALLOCATOR) {
            continue;
        }

        $tokens = BackendSourceFiles::codeTokens($path);
        $source = implode('', array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            $tokens,
        ));

        // The spread's own shape: a leftover counted down onto parts chosen by
        // position. Written a second time it drifts from the first, and the two
        // give the same figures different cents.
        if (preg_match('/\$remainder/', $source) === 1 && preg_match('/%\s*count\(/', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'The remainder a rounding lost is spread by CrossCurrencyTotal, largest',
        'magnitude first and ties broken by position, so the same figures always',
        'land the same cents on the same parts. A second spreader answers the same',
        'question differently. Offenders:',
        ...$offenders,
    ]));
});
