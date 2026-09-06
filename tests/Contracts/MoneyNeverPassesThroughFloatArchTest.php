<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-formatted-through-a-float
 */

// SCOPE. Banned: money reaching a float formatter, and a float landing in a
// minor-unit-named variable. Out of scope: a CHART COORDINATE, which is a pixel
// position rather than a money value — a y-axis needs a number, and the loss
// sits orders of magnitude below display resolution.

// The sites that boundary rests on, each with what earns it and a pattern
// re-read against the file. It was a list in a comment for as long as the rule
// existed: five paths and two conditions nothing re-checked, which is a claim
// about the tree that goes quiet the day one of them moves.
const FLOAT_MONEY_CHART_COORDINATE_SITES = [
    'Modules/Ledger/Public/ValueObjects/Money.php' => [
        'reason' => 'the seam itself: it asks the currency for its own scale instead of dividing by a hardcoded 100, which is what drew a JPY row at a hundredth of itself beside an axis already labelled in yen',
        'proves' => 'majorUnits',
    ],
    'Modules/Reports/Internal/Support/ChartAmount.php' => [
        'reason' => 'the reports coordinate, taken through the seam',
        'proves' => 'majorUnits',
    ],
    'Modules/Forecasting/Internal/Support/ForecastChartView.php' => [
        'reason' => 'the forecast coordinate, taken through the seam',
        'proves' => 'majorUnits',
    ],
    'Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php' => [
        'reason' => 'the aggregate line, taken through the seam',
        'proves' => 'majorUnits',
    ],
    'Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php' => [
        'reason' => 'the series detail chart, taken through the seam',
        'proves' => 'majorUnits',
    ],
    'resources/js/app.js' => [
        'reason' => 'the other half of the boundary: every axis label, and the tooltip ApexCharts falls back to, renders through the currency formatter this installs rather than through the raw coordinate',
        'proves' => 'beatraxLocaliseChart',
    ],
];

// The one option that would print the coordinate itself. While every chart
// leaves it off, the float above a chart never reaches a reader as a number.
const FLOAT_MONEY_RAW_LABEL_PATTERN = '/[\'"]?dataLabels[\'"]?\s*(?:=>|:)\s*[\[{][^\]}]*[\'"]?enabled[\'"]?\s*(?:=>|:)\s*true/is';

const FLOAT_MONEY_SUPPRESSED_LABEL_PATTERN = '/[\'"]?dataLabels[\'"]?\s*(?:=>|:)\s*[\[{][^\]}]*[\'"]?enabled[\'"]?\s*(?:=>|:)\s*false/is';

/** @return list<string> absolute paths to the PHP and Blade that ships */
function floatMoneyShippedFiles(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
}

function floatMoneyRelative(string $path): string
{
    return str_replace(RepoTree::root().'/', '', $path);
}

/** @return string the file's source with comments removed */
function floatMoneySource(string $path): string
{
    $source = (string) file_get_contents($path);

    return preg_replace('#/\*.*?\*/|//[^\n]*|\{\{--.*?--\}\}#s', '', $source) ?? $source;
}

/**
 * Statements rather than lines: a ternary wrapped across four lines is one
 * decision, and where its result lands is on the first of them.
 *
 * @return list<array{0: string, 1: int}> statement text and its opening line
 */
function floatMoneyStatements(string $source): array
{
    $statements = [];
    $line = 1;

    foreach (explode(';', $source) as $chunk) {
        // A chunk this long is template markup between two statements, not a
        // statement; scanning it would pair a name with an unrelated call.
        if (strlen($chunk) <= 400) {
            $statements[] = [$chunk, $line];
        }

        $line += substr_count($chunk, "\n");
    }

    return $statements;
}

/** The divisor that turns an exact count of cents into an inexact float. */
const FLOAT_MONEY_DIVISOR = '(?:100|Money::MINOR_UNITS_PER_MAJOR|self::MINOR_UNITS_PER_MAJOR)';

/**
 * @return list<array{name: string, args: string, line: int}> every call to $function, argument text intact
 */
function floatMoneyCallsTo(string $source, string $function): array
{
    $calls = [];
    $offset = 0;

    while (($start = strpos($source, $function.'(', $offset)) !== false) {
        $cursor = $start + strlen($function) + 1;
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
            'name' => $function,
            'args' => substr($source, $start + strlen($function) + 1, $cursor - $start - strlen($function) - 2),
            'line' => substr_count($source, "\n", 0, $start) + 1,
        ];
        $offset = $cursor;
    }

    return $calls;
}

/**
 * Names holding a money amount that has already become a float, so a division
 * three lines above a formatter reads the same as one inside it.
 *
 * @return list<string> variable names, '$' included
 */
function floatMoneyTaintedNames(string $source): array
{
    // No brace between the name and the division, or a closure's own name
    // is captured instead of the variable assigned three lines inside it.
    $pattern = '/(\$\w+)\s*=\s*([^;{}]*\/\s*'.FLOAT_MONEY_DIVISOR.'[^;{}]*);/';

    $matches = PatternScan::sets($pattern, $source);

    $tainted = [];
    foreach ($matches as $match) {
        if (stripos($match[2], 'minor') !== false || str_contains($match[2], 'MINOR_UNITS_PER_MAJOR')) {
            $tainted[] = $match[1];
        }
    }

    return array_values(array_unique($tainted));
}

/** @return list<string> `line — formatter(args)` for each money value handed to a float formatter */
function floatMoneyFormatterOffendersIn(string $source): array
{
    $tainted = floatMoneyTaintedNames($source);
    $offenders = [];

    foreach (['number_format', 'formatCurrency'] as $formatter) {
        foreach (floatMoneyCallsTo($source, $formatter) as $call) {
            $divides = preg_match('#/\s*'.FLOAT_MONEY_DIVISOR.'\b#', $call['args']) === 1;
            $namesMinor = stripos($call['args'], 'minor') !== false;

            $carriesTaint = false;
            foreach ($tainted as $name) {
                if (preg_match('/'.preg_quote($name, '/').'\b/', $call['args']) === 1) {
                    $carriesTaint = true;
                    break;
                }
            }

            if (($divides && $namesMinor) || $carriesTaint || $formatter === 'formatCurrency') {
                $offenders[] = $call['line'].' — '.$formatter.'('.trim(PatternScan::replace('/\s+/', ' ', $call['args'])).')';
            }
        }
    }

    return $offenders;
}

/** @return list<string> `line — statement` for each minor-unit amount derived from a float */
function floatMoneyMinorFromFloatOffendersIn(string $source): array
{
    // The clauses below look for where the result LANDS — a minor-unit name,
    // or a return out of a float — so scaling a ratio into a percentage lands
    // in neither and stays legible.
    $rounds = '/\b(?:round|floor|ceil)\s*\(/';
    $scales = '/\*\s*'.FLOAT_MONEY_DIVISOR.'\b/';
    $intoMinor = '/\$\w*[Mm]inor\w*\s*=[^=]/';
    $outOfFloat = '/\breturn\b[^;]*\(float\)/';

    $offenders = [];

    foreach (floatMoneyStatements($source) as [$text, $line]) {
        if (preg_match($rounds, $text, $match, PREG_OFFSET_CAPTURE) !== 1 || preg_match($scales, $text) !== 1) {
            continue;
        }

        if (preg_match($intoMinor, $text) === 1 || preg_match($outOfFloat, $text) === 1) {
            $offset = (int) $match[0][1];
            $offenders[] = ($line + substr_count($text, "\n", 0, $offset)).' — '.
                trim(PatternScan::replace('/\s+/', ' ', substr($text, (int) strrpos(substr($text, 0, $offset), "\n"))));
        }
    }

    return $offenders;
}

/** @return list<string> `line — text` for each typed amount cast to a float */
function floatMoneyCastOffendersIn(string $source): array
{
    // (float) '1.234,56' is 1.234 in PHP. Every amount a user types can carry
    // a thousands separator, so the cast is a silent hundredfold error.
    $pattern = '/\(float\)\s*\(?\s*\$[\w>\-\[\]\'"()?\s]*?(?:amount|balance|total)/i';

    $offenders = [];

    foreach (explode("\n", $source) as $number => $line) {
        if (preg_match($pattern, $line) === 1) {
            $offenders[] = ($number + 1).' — '.trim($line);
        }
    }

    return $offenders;
}

it('renders no money through a float formatter', function (): void {
    // Every root that ships, from the one place a scope is declared. The walk
    // opened Modules/, app/ and resources/, so a seeder or a route closure
    // formatting an amount sat outside all three of these rules.
    $files = floatMoneyShippedFiles();

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $offenders = [];

    foreach ($files as $path) {
        foreach (floatMoneyFormatterOffendersIn(floatMoneySource($path)) as $offender) {
            $offenders[] = floatMoneyRelative($path).':'.$offender;
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "number_format() and formatCurrency() take a float, so an exact count of\n".
        "cents stops being exact on the way to the screen — and picks up whatever\n".
        "separators the call site hardcoded on the way. Render from the integer:\n".
        "Money::ofMinor(\$minor, \$currency)->format() for an amount on display,\n".
        "MoneyInput::formatMinor(\$minor) for one going back into an input.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('derives no minor-unit amount from a float', function (): void {
    $files = floatMoneyShippedFiles();
    $statements = 0;
    $offenders = [];

    foreach ($files as $path) {
        $source = floatMoneySource($path);
        $statements += count(floatMoneyStatements($source));

        foreach (floatMoneyMinorFromFloatOffendersIn($source) as $offender) {
            $offenders[] = floatMoneyRelative($path).':'.$offender;
        }
    }

    // Read before the verdict: this rule splits on `;` and drops any chunk over
    // 400 characters, so a reader that stopped splitting would report a clean
    // tree over nothing. The floor sits far under today's 65,166.
    expect($statements)->toBeGreaterThan(
        10000,
        'the walk read '.$statements.' statements across '.count($files).' files, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "Multiplying a float by 100 to get cents is a parser, and the repo has\n".
        "one: MoneyInput::tryToMinor() reads either separator convention, refuses\n".
        "a third decimal instead of rounding it away, and never touches a float.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('casts no typed amount to a float', function (): void {
    $files = floatMoneyShippedFiles();
    $lines = 0;
    $offenders = [];

    foreach ($files as $path) {
        $source = floatMoneySource($path);
        $lines += substr_count($source, "\n") + 1;

        foreach (floatMoneyCastOffendersIn($source) as $offender) {
            $offenders[] = floatMoneyRelative($path).':'.$offender;
        }
    }

    // The floor sits far under today's 410,240.
    expect($lines)->toBeGreaterThan(
        50000,
        'the walk read '.$lines.' lines across '.count($files).' files, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A typed amount is a string in whichever convention the typist uses, and\n".
        "PHP reads \"1.234,56\" as the float 1.234. Parse it with\n".
        "MoneyInput::tryToMinor(), which returns null rather than a guess.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('still holds each chart-coordinate site to the seam that puts it outside these rules', function (): void {
    $expired = [];

    foreach (FLOAT_MONEY_CHART_COORDINATE_SITES as $relative => $pin) {
        $path = RepoTree::root().'/'.$relative;

        if (! is_file($path)) {
            $expired[] = $relative.' is gone, and it was '.$pin['reason'];

            continue;
        }

        if (! str_contains((string) file_get_contents($path), $pin['proves'])) {
            $expired[] = $relative.' no longer holds "'.$pin['proves'].'", so it is no longer '.$pin['reason'];
        }
    }

    expect($expired)->toBe([], implode("\n", [
        'The chart-coordinate boundary rests on these, and one of them has stopped doing what put it',
        'outside the three rules above:',
        ...$expired,
    ]));
});

// The boundary holds only while the float stays a coordinate. A chart that
// turns dataLabels on prints the raw number beside the axis, and the loss that
// sits below display resolution is suddenly on the screen as digits.
it('lets no chart print the coordinate itself', function (): void {
    $files = floatMoneyShippedFiles();
    $suppressed = 0;
    $offenders = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        if (PatternScan::matches(FLOAT_MONEY_SUPPRESSED_LABEL_PATTERN, $source)) {
            $suppressed++;
        }

        if (PatternScan::matches(FLOAT_MONEY_RAW_LABEL_PATTERN, $source)) {
            $offenders[] = floatMoneyRelative($path);
        }
    }

    // Read before the verdict: with no chart configuring dataLabels at all, an
    // empty offender list says nothing about whether the reader can see one.
    expect($suppressed)->toBeGreaterThan(
        0,
        'no chart in the tree switches dataLabels off, so this reader has never matched the shape it looks for.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These enable ApexCharts dataLabels, which prints the coordinate itself rather than the label',
        'window.beatraxLocaliseChart() formats:',
        ...$offenders,
        '',
        'A chart coordinate is allowed to be a float because no reader ever sees the number. Turning',
        'the labels on ends that, and the amount belongs back on Money::ofMinor(...)->format().',
    ]));
});

// All three rules above are lists that come back empty over a clean tree and
// empty over a reader that stopped reading, so the readers are driven against
// planted sources. Each near-miss is a shape the tree really carries: a chart
// coordinate, a percentage scaled by 100, and a date cast.
it('sees money reaching a float formatter, a float becoming cents, and an amount cast to one', function (): void {
    expect(floatMoneyFormatterOffendersIn('<?php echo number_format($amountMinor / 100, 2);'))
        ->toBe(['1 — number_format($amountMinor / 100, 2)'])
        ->and(floatMoneyFormatterOffendersIn("<?php \$euros = \$amountMinor / 100;\necho number_format(\$euros, 2);"))
        ->toBe(['2 — number_format($euros, 2)'])
        ->and(floatMoneyFormatterOffendersIn('<?php echo formatCurrency($x);'))
        ->toBe(['1 — formatCurrency($x)'])
        ->and(floatMoneyFormatterOffendersIn('<?php $y = $money->majorUnits();'))->toBe([])
        ->and(floatMoneyFormatterOffendersIn('<?php echo number_format($count, 0);'))->toBe([]);

    expect(floatMoneyMinorFromFloatOffendersIn('<?php $amountMinor = (int) round($typed * 100);'))
        ->toHaveCount(1)
        ->and(floatMoneyMinorFromFloatOffendersIn('<?php $percent = round($ratio * 100, 1);'))->toBe([]);

    expect(floatMoneyCastOffendersIn('<?php $v = (float) $row->amount;'))
        ->toBe(['1 — <?php $v = (float) $row->amount;'])
        ->and(floatMoneyCastOffendersIn('<?php $v = (float) $row->confidence;'))->toBe([])
        ->and(floatMoneyCastOffendersIn('<?php $v = (int) $row->amount_minor;'))->toBe([]);

    expect(PatternScan::matches(FLOAT_MONEY_RAW_LABEL_PATTERN, "'dataLabels' => ['enabled' => true],"))->toBeTrue()
        ->and(PatternScan::matches(FLOAT_MONEY_RAW_LABEL_PATTERN, 'dataLabels: { enabled: true },'))->toBeTrue()
        ->and(PatternScan::matches(FLOAT_MONEY_RAW_LABEL_PATTERN, "'dataLabels' => ['enabled' => false],"))->toBeFalse();
});

it('keeps MoneyInput a round trip', function (int $minor): void {
    // formatMinor() groups thousands, so this is the assertion that stops the
    // group mark and the decimal mark being chosen independently: whatever
    // formatMinor() writes, tryToMinor() must read back.
    $formatted = MoneyInput::formatMinor($minor);

    expect(MoneyInput::tryToMinor($formatted))->toBe(
        $minor,
        'MoneyInput::formatMinor('.$minor.') produced "'.$formatted.'", which '.
        'tryToMinor() does not read back. A value shown in an input and '.
        'submitted untouched must survive the trip.',
    );
})->with([0, 5, -5, 1250, -123450, 123456, 999999999, -999999999]);
