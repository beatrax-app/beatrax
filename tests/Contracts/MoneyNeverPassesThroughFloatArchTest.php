<?php

declare(strict_types=1);

/*
 * Money is an integer count of minor units, and it stays one on both string
 * boundaries: the one where it becomes something a person reads, and the one
 * where something a person typed becomes an amount.
 *
 * NoFloatMoneyArchTest holds the storage boundary — no REAL column, a string
 * cast on every decimal one. It says nothing about runtime, and runtime is
 * where the conversions actually were. `number_format($minor / 100, …)` was
 * copied to twenty-odd call sites, and `(int) round((float) $typed * 100)` to
 * five, which is how a transaction filter for "1.234,56" came to search for
 * €1.23: PHP reads that string as the float 1.234.
 *
 * MoneyInput is the seam for both directions — tryToMinor() in, formatMinor()
 * out — and Money::format() renders an amount that already knows its currency.
 * Neither ever holds a float.
 *
 * SCOPE. The rule governs money that becomes a STRING. A chart plots money as
 * a coordinate, and there is no integer a charting library will take for a y
 * value; that float becomes a pixel, never digits, so it is outside the rule
 * rather than excused by it. The moment a coordinate is formatted back into an
 * amount, it is inside again.
 */

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
    $pattern = '/(\$\w+)\s*=\s*([^;]*\/\s*'.FLOAT_MONEY_DIVISOR.'[^;]*);/';

    if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

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
                    $offenders[] = $file.':'.$call['line'].' — '.$formatter.'('.trim(preg_replace('/\s+/', ' ', $call['args']) ?? '').')';
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

    // Rounding a product of 100 is a money parse, and it is this repo's most
    // copied one: it accepts scientific notation, rounds a third decimal away
    // in silence, and reads a Dutch-typed "1.234,56" as 1.234. Scaling that
    // is not money — a percentage of a total — rounds no float into cents and
    // stores nothing in a minor-unit name, so neither clause below sees it.
    $rounds = '/\b(?:round|floor|ceil)\s*\(/';
    $scales = '/\*\s*'.FLOAT_MONEY_DIVISOR.'\b/';
    $intoMinor = '/\$\w*[Mm]inor\w*\s*=[^=]/';
    $fromFloat = '/\(float\)/';

    foreach (floatMoneyShippedFiles() as $file) {
        $source = floatMoneySource($file);

        foreach (explode("\n", $source) as $number => $line) {
            if (preg_match($rounds, $line) !== 1 || preg_match($scales, $line) !== 1) {
                continue;
            }

            if (preg_match($intoMinor, $line) === 1 || preg_match($fromFloat, $line) === 1) {
                $offenders[] = $file.':'.($number + 1).' — '.trim($line);
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
    $formatted = Modules\Ledger\Public\ValueObjects\MoneyInput::formatMinor($minor);

    expect(Modules\Ledger\Public\ValueObjects\MoneyInput::tryToMinor($formatted))->toBe(
        $minor,
        'MoneyInput::formatMinor('.$minor.') produced "'.$formatted.'", which '.
        'tryToMinor() does not read back. A value shown in an input and '.
        'submitted untouched must survive the trip.',
    );
})->with([0, 5, -5, 1250, -123450, 123456, 999999999, -999999999]);
