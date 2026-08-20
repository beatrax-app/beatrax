<?php

declare(strict_types=1);

/*
 * Money::format() picks the locale from the currency: EUR reads in the Dutch
 * convention, everything else in US English, which is how a card statement
 * reads. Thirty call sites passed 'nl_NL' anyway, and in the ones that were
 * not pinned to EUR that renders a dollar or sterling amount with Dutch
 * separators — $1.234,56 — for a user in any of the app's 26 locales.
 *
 * Passing the locale you already have is never an improvement over letting
 * the value object choose, so the argument is gone: format() takes none.
 * This guards the signature as well as the call sites, because a parameter
 * that exists is a parameter somebody will pass.
 */

/** @return list<string> repo-relative PHP and Blade files under Modules/, app/ and resources/ */
function moneyFormatRenderingFiles(): array
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
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

it('hands format() no locale to override the one the currency implies', function (): void {
    $offenders = [];

    foreach (moneyFormatRenderingFiles() as $file) {
        $source = (string) file_get_contents(base_path($file));
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

        if (preg_match_all('/->format\(\s*[\'"]([A-Za-z]{2}[_-][A-Za-z]{2}[^\'"]*)[\'"]/', $stripped, $matches) === false) {
            continue;
        }

        foreach ($matches[1] as $locale) {
            $offenders[] = $file.' — format(\''.$locale.'\')';
        }
    }

    expect($offenders)->toBe(
        [],
        "A hardcoded locale renders a foreign currency in someone else's\n".
        "separators. Drop the argument — Money::format() already resolves nl_NL\n".
        "for EUR and en_US for everything else, on every runtime. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});

it('gives Money::format() no locale parameter to pass in the first place', function (): void {
    $signature = new ReflectionMethod(Modules\Ledger\Public\ValueObjects\Money::class, 'format');

    expect($signature->getNumberOfParameters())->toBe(
        0,
        'Money::format() decides the locale from the currency. A parameter here '.
        'is an invitation to override that per call site, which is exactly how '.
        'thirty of them came to render USD with Dutch separators.',
    );
});
