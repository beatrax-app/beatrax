<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// The rule is quoted in comments explaining it; those are the point, not a
// violation. Skipped per LINE rather than per file, so a file that documents
// the rule is still scanned for a staging call under the paragraph.
/**
 * @return list<int> the 1-based line of every staging call the source makes
 */
function sharedTempDirLinesIn(string $source): array
{
    $lines = [];

    foreach (explode("\n", $source) as $number => $line) {
        $code = trim($line);

        if (str_starts_with($code, '//') || str_starts_with($code, '*')) {
            continue;
        }

        if (str_contains($line, 'sys_get_temp_dir')) {
            $lines[] = $number + 1;
        }
    }

    return $lines;
}

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#plaintext-staged-in-the-shared-temp-dir
 */
it('stages nothing in the shared temp directory', function (): void {
    $offenders = [];
    $walked = 0;

    // Every root that ships PHP, not Modules and app alone: a release script or
    // a bootstrap file staging a file in /tmp leaks its name and size just as
    // readably, and neither was in the walk's sight.
    $roots = array_values(array_filter(
        array_map(base_path(...), ['Modules', 'app', 'bootstrap', 'config', 'database', 'routes', 'scripts']),
        is_dir(...),
    ));

    $finder = (new Finder)->files()->in($roots)->name('*.php')->notPath('tests');

    foreach ($finder as $file) {
        $walked++;

        foreach (sharedTempDirLinesIn($file->getContents()) as $line) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.$line;
        }
    }

    expect($walked)->toBeGreaterThan(2000, 'The walk read almost no PHP, so a clean answer below is the walk being broken rather than the tree being right.');

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['sys_get_temp_dir() is world-traversable (/tmp is 1777), so anything staged there',
            'leaks at least its name and size, and a plain fopen lands at 0644. Stage under a',
            '0700 directory instead: UserDataPathService::appPath(\'tmp-…\') + mkdir 0700. Offenders:'],
        $offenders,
    )));
});

it('reads a staging call and not the paragraph above it explaining the ban', function (): void {
    // Five files in this tree carry that paragraph, which is why the skip is
    // per line: a whole-file exemption for the prose would have hidden the call.
    $source = "<?php\n"
        ."// A 0700 directory under app storage, NEVER sys_get_temp_dir(): /tmp\n"
        ." * is world-traversable at 1777, so sys_get_temp_dir() leaks a name.\n"
        ."\$path = sys_get_temp_dir().'/staged.eml';\n";

    expect(sharedTempDirLinesIn($source))->toBe([4], 'The line reader is either missing the staging call or counting the comments that explain the ban.');
});
