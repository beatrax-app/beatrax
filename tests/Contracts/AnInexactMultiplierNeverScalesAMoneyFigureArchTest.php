<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// A decimal written in source is stored as the nearest double, and for most of
// them that is not the number typed. 1.15 is a shade under, so 115% of EUR
// 12.90 came out EUR 14.83 where the figure is EUR 14.835 — a suppression band
// a hundredth inside the one the reader asked to mute, matched on exactly.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-that-left-its-seam

// Whether the constant happens to land on the right side of its own rounding
// is a property of the number, not of the code: 0.85, 0.95, 1.05 and 0.92 are
// all exact over every amount this app can hold, and 1.15 is wrong 51,289
// times under EUR 20,000. Nobody checked which was which, and nobody should
// have to. CrossCurrencyTotal::percentOf() takes a whole percent and answers
// in integers.
const INEXACT_MULTIPLIER_PINS = [
    'Modules/Recurring/Internal/Queries/RecurringSeriesProjector.php' => [
        'reason' => 'a float multiplier that only ever builds an ORDER BY key and its matching cursor, so the ordering is what it decides and no figure is stored or shown from it',
        'proves' => '/ORDER BY|orderBy/',
    ],
];

// The identifiers that read as an amount rather than as any other number.
const INEXACT_MULTIPLIER_NAMES_MONEY = '/[Aa]mount|[Mm]inor|[Bb]alance|magnitude/';

// Truncation back to an integer is what makes the double's error a stored cent
// rather than an intermediate nobody keeps.
const INEXACT_MULTIPLIER_TRUNCATES = '/\(int\)|intdiv/';

/**
 * Every `const float` the tree declares, by name. A site referencing one from
 * another file reads as exactly what a literal does, and the two spellings of
 * the same hazard must not need two rules.
 *
 * @return array<string, true>
 */
function inexactMultiplierFloatConstants(): array
{
    $names = [];

    foreach (BackendSourceFiles::all() as $path) {
        foreach (PatternScan::all('/const\s+float\s+([A-Z][A-Z_0-9]*)/', (string) file_get_contents($path))[1] as $name) {
            $names[(string) $name] = true;
        }
    }

    return $names;
}

/**
 * Statements rather than lines: the multiplier, the amount and the cast back
 * to an integer are one decision, and a ternary spreads it over four lines.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<array{line: int, text: string, hasFloat: bool}>
 */
function inexactMultiplierStatements(array $tokens): array
{
    $statements = [];
    $current = [];
    $line = 0;

    foreach ($tokens as $token) {
        if (! is_array($token) && $token === ';') {
            $statements[] = inexactMultiplierStatement($current, $line);
            $current = [];
            $line = 0;

            continue;
        }

        if ($line === 0 && is_array($token)) {
            $line = $token[2];
        }

        $current[] = $token;
    }

    return $statements;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array{line: int, text: string, hasFloat: bool}
 */
function inexactMultiplierStatement(array $tokens, int $line): array
{
    $text = '';
    $hasFloat = false;

    foreach ($tokens as $token) {
        if (is_array($token)) {
            $text .= $token[1];
            $hasFloat = $hasFloat || in_array($token[0], [T_DNUMBER, T_DOUBLE_CAST], true);

            continue;
        }

        $text .= $token;
    }

    return ['line' => $line, 'text' => $text, 'hasFloat' => $hasFloat];
}

/**
 * @param  array{line: int, text: string, hasFloat: bool}  $statement
 * @param  array<string, true>  $floatConstants
 */
function inexactMultiplierScalesMoney(array $statement, array $floatConstants): bool
{
    if (! PatternScan::matches('#[*/]#', $statement['text'])
        || ! PatternScan::matches(INEXACT_MULTIPLIER_NAMES_MONEY, $statement['text'])
        || ! PatternScan::matches(INEXACT_MULTIPLIER_TRUNCATES, $statement['text'])) {
        return false;
    }

    if ($statement['hasFloat']) {
        return true;
    }

    foreach (array_keys($floatConstants) as $name) {
        if (PatternScan::matches('/\b'.preg_quote($name, '/').'\b/', $statement['text'])) {
            return true;
        }
    }

    return false;
}

it('scales no money figure by a number the machine cannot hold', function (): void {
    $floatConstants = inexactMultiplierFloatConstants();
    $offenders = [];
    $pinned = [];
    $read = 0;

    // One of the two spellings this rule reads is a named constant, so a
    // collector that found none would leave half the rule answering nothing.
    expect(count($floatConstants))->toBeGreaterThan(
        10,
        'Found '.count($floatConstants).' float constants, too few for this tree.',
    );

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (inexactMultiplierStatements(BackendSourceFiles::codeTokens($path)) as $statement) {
            $read++;

            if (! inexactMultiplierScalesMoney($statement, $floatConstants)) {
                continue;
            }

            if (array_key_exists($relative, INEXACT_MULTIPLIER_PINS)) {
                $pinned[$relative] = true;

                continue;
            }

            $offenders[] = $relative.':'.$statement['line'].'  '.trim((string) PatternScan::replace('/\s+/', ' ', $statement['text']));
        }
    }

    // Read before the verdict: a walk that stopped splitting reports the same
    // clean tree a clean tree does. The floor sits far under today's 62,893.
    expect($read)->toBeGreaterThan(
        20000,
        'Read '.$read.' statements, too few for an empty offender list to mean anything.',
    );

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A decimal in source is the nearest double to it, which for 1.15 is under',
        'the number typed: 115% of EUR 12.90 rounded to EUR 14.83, and a band the',
        'reader muted stopped a cent short of its own edge. A whole percent of an',
        'amount is CrossCurrencyTotal::percentOf(), which answers in integers.',
        'Anything finer than a percent is a rate, and RateTable converts at those.',
        'Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(
        array_keys(INEXACT_MULTIPLIER_PINS),
        'A pinned multiplier is no longer reached by the rule it was written for, so the entry excuses nothing and goes.',
    );
});

it('still holds each pinned multiplier to the reason it was granted for', function (): void {
    foreach (INEXACT_MULTIPLIER_PINS as $relative => $pin) {
        expect((string) file_get_contents(base_path($relative)))
            ->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

it('reads a literal, a named float constant and a cast, and leaves whole numbers alone', function (): void {
    $source = <<<'PHP'
        <?php
        final class PlantedMultiplier
        {
            private const float BAND = 1.15;
            private const string RATE = '0.92';

            public function literal(int $amountMinor): int
            {
                return (int) round($amountMinor * 1.15);
            }

            public function named(int $amountMinor): int
            {
                return (int) round(self::BAND * $amountMinor);
            }

            public function cast(int $amountMinor): int
            {
                return (int) round($amountMinor * (float) self::RATE);
            }

            public function wholePercent(int $amountMinor, int $percent): int
            {
                return intdiv($amountMinor * $percent, 100);
            }

            public function notMoney(float $seconds): int
            {
                return (int) ($seconds * 1.5);
            }
        }
        PHP;

    $constants = ['BAND' => true];
    $found = [];

    foreach (inexactMultiplierStatements(BackendSourceFiles::tokensOf('Planted.php', $source)) as $statement) {
        if (inexactMultiplierScalesMoney($statement, $constants)) {
            $found[] = trim((string) PatternScan::replace('/\s+/', ' ', $statement['text']));
        }
    }

    // The split is on `;` alone, so a statement carries whatever preceded it
    // with no semicolon of its own — a signature and its brace. That widens
    // what a statement names, which can only add offenders, never hide one.
    expect($found)->toBe([
        'public function literal(int $amountMinor): int { return (int) round($amountMinor * 1.15)',
        '} public function named(int $amountMinor): int { return (int) round(self::BAND * $amountMinor)',
        '} public function cast(int $amountMinor): int { return (int) round($amountMinor * (float) self::RATE)',
    ], 'a literal, a float constant and a float cast each scale an amount; a whole percent and a duration do not');
});
