<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// number_format() and toFixed() write the C locale's marks whatever language the
// page is in. The nav badge shortened 1200 to "1.2k" and the onboarding chip
// sized a file "1,023 KB", both of which a Dutch reader parses as a thousand
// times what was meant, beside money the same page had grouped correctly.
// Fmt::number and Intl.NumberFormat ask the reader's locale instead.

/**
 * Both marks named explicitly is the deliberate machine string -- a cursor, a
 * rate, an attribute -- which has to stay stable across locales. It is the call
 * that leans on the defaults that ends up on screen in the wrong language.
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
        $i = $at + strlen('number_format(') - 1;

        for ($len = strlen($source); $i < $len; $i++) {
            $char = $source[$i];

            if ($char === '(') {
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

    foreach (readerFacingSourceFiles() as $path) {
        $source = withoutComments((string) file_get_contents($path));
        $relative = str_replace(base_path().'/', '', $path);

        foreach (numberFormatArity($source) as $arity) {
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
