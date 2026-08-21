<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#plaintext-staged-in-the-shared-temp-dir
 */
it('stages nothing in the shared temp directory', function (): void {
    $offenders = [];

    $finder = (new Finder)->files()->in([base_path('Modules'), base_path('app')])->name('*.php')->notPath('tests');

    foreach ($finder as $file) {
        foreach (explode("\n", $file->getContents()) as $number => $line) {
            // The rule is quoted in comments explaining it; those are the
            // point, not a violation.
            $code = trim($line);

            if (str_starts_with($code, '//') || str_starts_with($code, '*')) {
                continue;
            }

            if (str_contains($line, 'sys_get_temp_dir')) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($number + 1);
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['sys_get_temp_dir() is world-traversable (/tmp is 1777), so anything staged there',
            'leaks at least its name and size, and a plain fopen lands at 0644. Stage under a',
            '0700 directory instead: UserDataPathService::appPath(\'tmp-…\') + mkdir 0700. Offenders:'],
        $offenders,
    )));
});
