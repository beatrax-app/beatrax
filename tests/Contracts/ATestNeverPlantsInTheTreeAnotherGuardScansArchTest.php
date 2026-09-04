<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// OneSpellingPerSyntheticIbanArchTest proved its own guard by writing a probe
// into Modules/Core/Internal/. Alone that is correct. Under --parallel, every
// other guard enumerating Modules/ could list the probe and then find it
// deleted, and go red naming a class no branch ever wrote.

/** @return list<string> roots an arch guard enumerates, so a file appearing in one races it */
function guardedSourceRoots(): array
{
    return ['Modules', 'app', 'resources', 'routes', 'config', 'lang', 'database', 'bootstrap', 'scripts', 'mobile-app'];
}

/** @return array<string, string> absolute path => path relative to $root */
function testFilesUnder(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $paths = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $paths[$file->getPathname()] = str_replace($root.'/', '', $file->getPathname());
        }
    }

    ksort($paths);

    return $paths;
}

// A repo-relative path when the write names one, else null. Resolves the two
// shapes the suite uses: a base_path() call inline in the write, and a local
// assigned one earlier in the same file.
/** @param array<string, string> $assigned variable name => repo-relative path */
function plantTargetOf(string $argument, array $assigned): ?string
{
    if (preg_match('/^base_path\(\s*[\'"]([^\'"$]*)/', $argument, $inline) === 1) {
        return $inline[1];
    }

    return preg_match('/^\$(\w+)\b/', $argument, $variable) === 1
        ? $assigned[$variable[1]] ?? null
        : null;
}

/** @return array<string, string> variable name => the repo-relative path it was assigned */
function basePathAssignments(string $source): array
{
    $matches = PatternScan::sets('/\$(\w+)\s*=\s*[^;\n]*base_path\(\s*[\'"]([^\'"$]*)/', $source);

    $assigned = [];

    foreach ($matches as $match) {
        $assigned[$match[1]] = $match[2];
    }

    return $assigned;
}

/** @return list<string> one line per write, naming the file, its line and the root it plants in */
function plantsInGuardedRoots(string $label, string $source): array
{
    $matches = PatternScan::setsWithOffsets(
        '/\b(file_put_contents|mkdir|touch|copy|rename|symlink)\s*\(\s*([^,)]+)/',
        $source,
    );

    $assigned = basePathAssignments($source);
    $offenders = [];

    foreach ($matches as $match) {
        $target = plantTargetOf(trim($match[2][0]), $assigned);

        // A write under a tests/ directory is in nobody's scan: every guard that
        // walks a source root skips those paths already.
        if ($target === null || str_contains($target, '/tests/') || ! in_array(explode('/', $target)[0], guardedSourceRoots(), true)) {
            continue;
        }

        $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
        $offenders[] = $label.':'.$line.' writes '.$target;
    }

    return $offenders;
}

/** @return array{offenders: list<string>, scanned: int} */
function guardedRootPlantings(string ...$roots): array
{
    $offenders = [];
    $scanned = 0;

    foreach ($roots as $root) {
        foreach (testFilesUnder($root) as $path => $label) {
            $scanned++;
            $offenders = [...$offenders, ...plantsInGuardedRoots($label, (string) file_get_contents($path))];
        }
    }

    return ['offenders' => $offenders, 'scanned' => $scanned];
}

// mobile-app/tests is a symlink onto this same directory, which is how one test
// file resolves from both composer roots. Walking it as well would read every
// file twice and report every offender twice.
/** @return list<string> every distinct directory in this repo that holds tests */
function everyTestRoot(): array
{
    return [base_path('tests'), ...glob(base_path('Modules/*/tests')) ?: []];
}

it('never writes a file into a directory another guard is scanning', function (): void {
    $result = guardedRootPlantings(...everyTestRoot());

    expect($result['scanned'])->toBeGreaterThan(100, 'the scan read almost no test files — it is broken, not the suite');

    expect($result['offenders'])->toBe([], implode("\n  ", [
        'A test that writes into a scanned source root races every guard walking that root '
            ."in a parallel worker: the file is listed, then gone by the time it is read, and an\n"
            .'  unrelated test fails naming a path no branch touched. Plant under '
            .'sys_get_temp_dir() and give the scanner that directory instead. Offenders:',
        ...$result['offenders'],
    ]));
});

// Both fixtures are assembled rather than written out, so this file does not
// read as a planting test to the scan above — the guard has to hold for itself
// before it is worth applying to anything else.
it('goes red on a planted write and stays green on a temp-dir one', function (): void {
    $write = 'file_put_'.'contents';
    $root = sys_get_temp_dir().'/planting-guard-'.bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);

    $plants = "<?php\n\$probe = base_path('Modules/Core/Internal/ScratchProbe.php');\n".$write."(\$probe, '<?php');\n";
    $behaves = "<?php\n\$probe = sys_get_temp_dir().'/ScratchProbe.php';\n".$write."(\$probe, '<?php');\n"
        ."mkdir(base_path('Modules/Core/tests/scratch'));\n";

    file_put_contents($root.'/PlantsTest.php', $plants);
    file_put_contents($root.'/BehavesTest.php', $behaves);

    try {
        expect(guardedRootPlantings($root)['offenders'])
            ->toBe(['PlantsTest.php:3 writes Modules/Core/Internal/ScratchProbe.php']);
    } finally {
        unlink($root.'/PlantsTest.php');
        unlink($root.'/BehavesTest.php');
        rmdir($root);
    }
});
