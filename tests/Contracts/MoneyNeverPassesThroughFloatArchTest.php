<?php

declare(strict_types=1);
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#money-formatted-through-a-float
 */

// SCOPE. Banned: money reaching a float formatter, and a float landing in a
// minor-unit-named variable. Out of scope: a CHART COORDINATE, which is a
// pixel position rather than a money value — a y-axis needs a number, and the
// loss sits orders of magnitude below display resolution.
//
// The sites relying on that boundary, so a reader can re-check it rather than
// take it on trust. Every chart in the repo now reaches its coordinate through
// Money::majorUnits(), which asks the money value object for the currency's own
// scale instead of dividing by a hardcoded 100 — a JPY row was drawn at a
// hundredth of itself beside an axis already labelled in yen:
//   Modules/Ledger/Public/ValueObjects/Money.php (majorUnits)
//   Modules/Reports/Internal/Support/ChartAmount.php
//   Modules/Forecasting/Internal/Support/ForecastChartView.php
//   Modules/Forecasting/Resources/views/livewire/partials/aggregate-line-chart.blade.php
//   Modules/Recurring/Internal/Http/Livewire/RecurringSeriesDetailPage.php
//
// The boundary holds only while the float stays a coordinate. It does here:
// every one of those charts renders its LABELS through the currency formatter
// window.beatraxLocaliseChart() installs on the y-axis (resources/js/app.js) —
// which ApexCharts also falls back to for tooltip values — and none of them
// enables dataLabels, the one path that would print the raw number. A chart
// that turns dataLabels on, or sets its own formatter, has left this scope.

/** @return list<string> repo-relative PHP and Blade files that ship */
function floatMoneyShippedFiles(): array
{
    $files = [];

    foreach (['Modules', 'app', 'resources'] as $root) {
        $absolute = base_path($root);
        if (! is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
                $files[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    sort($files);

    return $files;
}

/** @return string the file's source with comments removed */
function floatMoneySource(string $relativePath): string
{
    $source = (string) file_get_contents(base_path($relativePath));

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

it('renders no money through a float formatter', function (): void {
    $offenders = [];

    foreach (floatMoneyShippedFiles() as $file) {
        $source = floatMoneySource($file);
        $tainted = floatMoneyTaintedNames($source);

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
                    $offenders[] = $file.':'.$call['line'].' — '.$formatter.'('.trim(PatternScan::replace('/\s+/', ' ', $call['args'])).')';
                }
            }
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
    $offenders = [];

    // The clauses below look for where the result LANDS — a minor-unit name,
    // or a return out of a float — so scaling a ratio into a percentage lands
    // in neither and stays legible.
    $rounds = '/\b(?:round|floor|ceil)\s*\(/';
    $scales = '/\*\s*'.FLOAT_MONEY_DIVISOR.'\b/';
    $intoMinor = '/\$\w*[Mm]inor\w*\s*=[^=]/';
    $outOfFloat = '/\breturn\b[^;]*\(float\)/';

    foreach (floatMoneyShippedFiles() as $file) {
        foreach (floatMoneyStatements(floatMoneySource($file)) as $statement) {
            [$text, $line] = $statement;

            if (preg_match($rounds, $text, $match, PREG_OFFSET_CAPTURE) !== 1 || preg_match($scales, $text) !== 1) {
                continue;
            }

            if (preg_match($intoMinor, $text) === 1 || preg_match($outOfFloat, $text) === 1) {
                $offset = (int) $match[0][1];
                $offenders[] = $file.':'.($line + substr_count($text, "\n", 0, $offset)).' — '.
                    trim(PatternScan::replace('/\s+/', ' ', substr($text, (int) strrpos(substr($text, 0, $offset), "\n"))));
            }
        }
    }

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
    $offenders = [];

    // (float) '1.234,56' is 1.234 in PHP. Every amount a user types can carry
    // a thousands separator, so the cast is a silent hundredfold error.
    $pattern = '/\(float\)\s*\(?\s*\$[\w>\-\[\]\'"()?\s]*?(?:amount|balance|total)/i';

    foreach (floatMoneyShippedFiles() as $file) {
        $source = floatMoneySource($file);

        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match($pattern, $line) === 1) {
                $offenders[] = $file.':'.($number + 1).' — '.trim($line);
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A typed amount is a string in whichever convention the typist uses, and\n".
        "PHP reads \"1.234,56\" as the float 1.234. Parse it with\n".
        "MoneyInput::tryToMinor(), which returns null rather than a guess.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
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
