<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

// A test file only runs if some <testsuite> in phpunit.xml names a directory
// above it, and then only if the shard resolver hands that suite to one of the
// three runners. Neither half announces itself when it stops being true: a
// module whose tests/ tree is added without the matching <directory> entry is
// collected by nothing, and the run it never joined still reports green with a
// slightly smaller number nobody was watching.
//
// This repository has lost tests both ways already — once to a testsuite that
// matched no file, once to a test file no testsuite matched — which is why the
// resolver reads phpunit.xml rather than a checked-in manifest. That closed the
// half where a suite exists and is forgotten. This closes the half where the
// suite was never written, and the half where the resolver drops one.
//
// The resolver is a script the pipeline shells out to and nothing else covers.
// Running it here is the only place its output is compared against the input it
// was derived from.

/**
 * @return array<string, list<string>> testsuite name => the directories it collects
 */
function everyTestFileSuiteDirectories(): array
{
    $document = new DOMDocument;
    $document->load(base_path('phpunit.xml'));

    $suites = [];

    foreach ((new DOMXPath($document))->query('//testsuites/testsuite') ?: [] as $suite) {
        if (! $suite instanceof DOMElement) {
            continue;
        }

        $name = $suite->getAttribute('name');
        $directories = [];

        foreach ($suite->getElementsByTagName('directory') as $directory) {
            $resolved = realpath(base_path(trim($directory->textContent)));

            if ($resolved !== false) {
                $directories[] = $resolved;
            }
        }

        $suites[$name] = $directories;
    }

    return $suites;
}

/**
 * @return list<string> absolute paths of every test file the repository holds
 */
function everyTestFileInTheTree(): array
{
    $roots = array_merge([base_path('tests')], glob(base_path('Modules/*/tests')) ?: []);
    $files = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), 'Test.php')) {
                $files[] = (string) realpath($file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

// Both loops below pass over an empty collection, so the sizes are asserted
// first: a walk that found nothing and a phpunit.xml that parsed to nothing
// would each clear every assertion in this file while proving the opposite of
// what it claims. The floors are deliberately far below the real counts —
// they catch a broken scan, not a deleted module.
it('finds the testsuites and the test files it is about to check', function (): void {
    expect(count(everyTestFileSuiteDirectories()))->toBeGreaterThan(
        20,
        'phpunit.xml parsed to almost no testsuite, so the collection check below would call every file uncollected or none.',
    )
        ->and(count(everyTestFileInTheTree()))->toBeGreaterThan(
            1_500,
            'The walk found almost no test file, so the empty uncollected list below is a tree nobody read.',
        );
});

it('collects every test file the repository holds into a testsuite', function (): void {
    $directories = array_merge(...array_values(everyTestFileSuiteDirectories()));

    $uncollected = array_values(array_filter(
        everyTestFileInTheTree(),
        fn (string $file): bool => ! array_any(
            $directories,
            fn (string $directory): bool => str_starts_with($file, $directory.DIRECTORY_SEPARATOR),
        ),
    ));

    $relative = array_map(
        fn (string $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file),
        $uncollected,
    );

    expect($relative)->toBe([], 'no testsuite in phpunit.xml collects these, so they run nowhere: '.implode(', ', $relative));
});

// The resolver is the pipeline's, called the way the pipeline calls it. A suite
// dropped between phpunit.xml and the shards is the same silence as a suite
// that was never written, one step further down.
it('hands every testsuite to exactly one shard', function (): void {
    $shards = 3;
    $assigned = [];

    for ($shard = 1; $shard <= $shards; $shard++) {
        $process = new Process(
            ['python3', base_path('.github/scripts/shard-testsuites.py'), '--shard', (string) $shard, '--of', (string) $shards],
            base_path(),
        );
        $process->run();

        $output = trim($process->getOutput());

        expect($process->getExitCode())->toBe(
            0,
            "the pipeline resolves shard {$shard}/{$shards} with this script and it did not succeed: ".$process->getErrorOutput(),
        );

        foreach (explode(',', $output) as $suite) {
            $assigned[] = trim($suite);
        }
    }

    $names = array_keys(everyTestFileSuiteDirectories());
    sort($names);
    $sorted = $assigned;
    sort($sorted);

    expect($sorted)->toBe(
        $names,
        'The three shards between them must resolve to exactly the testsuites phpunit.xml declares. A '
        .'suite the resolver drops runs nowhere, and the run it never joined still reports green with a '
        .'slightly smaller number nobody was watching.',
    )
        ->and($assigned)->toHaveCount(count(array_unique($assigned)));
});
