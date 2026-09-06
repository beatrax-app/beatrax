<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// number_format() and toFixed() write the C locale's marks whatever language the
// page is in. The nav badge shortened 1200 to "1.2k" and the onboarding chip
// sized a file "1,023 KB", both of which a Dutch reader parses as a thousand
// times what was meant, beside money the same page had grouped correctly.
// Fmt::number and Intl.NumberFormat ask the reader's locale instead.

/**
 * Every file a number a reader sees can be written in: PHP, Blade, and the JS
 * that draws beside them. The suite is not one of them -- a fixture asserting
 * about number_format() names the banned call on purpose.
 *
 * @return list<string>
 */
function readerFacingSourceFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources'] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($walk as $file) {
            $path = $file->getPathname();

            if (! PatternScan::matches('/\.(php|blade\.php|js)$/', $path) || str_contains($path, '/tests/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * A comment naming the banned call is a comment explaining why it is banned,
 * which is the opposite of a violation. The `//` that follows a colon is a URL
 * scheme, not the start of one.
 */
function withoutComments(string $source): string
{
    foreach (['~\{\{--.*?--\}\}~s', '~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'] as $pattern) {
        $source = PatternScan::replace($pattern, '', $source);
    }

    return $source;
}

/**
 * @return list<int> the argument count of every number_format() call in $source
 */
function numberFormatArity(string $source): array
{
    $arities = [];
    $offset = 0;

    while (($at = strpos($source, 'number_format(', $offset)) !== false) {
        $depth = 0;
        $args = 1;
        $quote = null;
        $i = $at + strlen('number_format(') - 1;

        for ($len = strlen($source); $i < $len; $i++) {
            $char = $source[$i];

            // The separators this rule is about ARE a comma and a bracket, so
            // the arguments naming them hold one. Counted raw, the machine
            // string `number_format($n, 2, ',', '.')` reads as five arguments
            // and every three-argument call one edit away from it reads as
            // four -- which is the arity that gets waved through below.
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($char === ',' && $depth === 1) {
                $args++;
            }
        }

        $arities[] = $args;
        $offset = $at + 1;
    }

    return $arities;
}

it('does not format a number a reader sees with the C locale marks', function (): void {
    $offenders = [];
    $files = readerFacingSourceFiles();

    // Six and a half thousand files carry the product's reader-facing code. A
    // walk that opened none of them would report every number as localised.
    expect(count($files))->toBeGreaterThan(1000, 'Read '.count($files).' reader-facing files, too few for an empty offender list to mean anything.');

    foreach ($files as $path) {
        $source = withoutComments((string) file_get_contents($path));
        $relative = str_replace(base_path().'/', '', $path);

        foreach (numberFormatArity($source) as $arity) {
            // Both marks named explicitly is the deliberate machine string -- a
            // cursor, a rate, an attribute -- which has to stay stable across
            // locales. It is the call that leans on the defaults that ends up
            // on screen in the wrong language.
            if ($arity < 4) {
                $offenders[] = "{$relative}: number_format() with {$arity} arguments";
            }
        }

        if (str_contains($source, '.toFixed(')) {
            $offenders[] = "{$relative}: .toFixed()";
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "These write digits with a dot the reader's language may use to group thousands. "
        .'Use Fmt::number() in PHP and Intl.NumberFormat in JS, or name both marks '
        ."explicitly when the string is for a machine:\n  ".implode("\n  ", $offenders)
    );
});

it('counts the arguments a number_format call was given, and reads no comment as one', function (): void {
    expect(numberFormatArity('<?php echo number_format($total);'))
        ->toBe([1], 'the call that leans on every default is the one that prints the C locale marks');

    expect(numberFormatArity('<?php echo number_format($total, 2, \',\', \'.\');'))
        ->toBe([4], 'naming both marks is the deliberate machine string this rule leaves alone');

    expect(numberFormatArity('<?php echo number_format(max($a, $b), 2, $sep, $group);'))
        ->toBe([4], 'a comma inside a nested call is not an argument of this one');

    expect(numberFormatArity('<?php echo number_format($total, 2, \',\');'))
        ->toBe([3], 'a call naming the decimal mark and leaning on the default grouping mark is three arguments, '
            .'and reading its comma as a separator is what waved it through');

    expect(numberFormatArity('<?php echo number_format($total, 2, ")", "(");'))
        ->toBe([4], 'a bracket inside a string argument closes no call');

    expect(numberFormatArity(withoutComments('<?php // number_format($total) is banned here')))
        ->toBe([], 'a comment naming the banned call is a comment explaining the ban');

    expect(withoutComments('<a href="https://beatrax.app">x</a>'))
        ->toBe('<a href="https://beatrax.app">x</a>', 'the // of a URL scheme is not the start of a comment');
});
