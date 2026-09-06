<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Pest compiles every test file into one process, so the helper functions they
// declare at file scope share a single global namespace. Two files declaring
// the same name is a fatal — "Cannot redeclare function" — which takes down
// the whole shard, not the two files, and reports at whichever file the runner
// reached second. Three collisions arrived in one branch this way, each of
// them a three-letter prefix of a test class name that another test class also
// abbreviates to.

// Two roots and not the tree, because the rule is about one process: Pest
// loads tests/ and Modules/*/tests/ together, and nothing else. scripts/ is
// the reason this matters — its standalone CLI files declare inkBounds() twice
// between the iOS and Android icon builders, which is harmless because no run
// ever loads both, and a walk of the whole tree would report it as the fatal
// it is not.
const TEST_HELPER_ROOTS = ['Modules', 'tests'];

/**
 * @return array<string, list<string>> helper name => every file declaring it
 */
function declaredTestHelpers(): array
{
    $byName = [];

    foreach (TEST_HELPER_ROOTS as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());
            $contents = (string) file_get_contents($file->getPathname());

            foreach (testHelperNamesIn($contents) as $name) {
                $byName[$name][] = $relative;
            }
        }
    }

    return $byName;
}

/**
 * File scope only: an indented `function` is a method or a closure, and
 * neither reaches the global namespace. Named rather than written inline so
 * the control below drives the same reader the walk drives.
 *
 * @return list<string>
 */
function testHelperNamesIn(string $source): array
{
    return PatternScan::all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $source)[1];
}

/**
 * @param  array<string, list<string>>  $byName
 * @return list<string>
 */
function testHelperCollisionsIn(array $byName): array
{
    $collisions = [];

    foreach ($byName as $name => $files) {
        $distinct = array_values(array_unique($files));

        if (count($distinct) > 1) {
            $collisions[] = $name.' — '.implode(', ', $distinct);
        }
    }

    sort($collisions);

    return $collisions;
}

it('does not let two test files declare the same helper function', function (): void {
    $declared = declaredTestHelpers();

    // Thousands of free helpers stand under these two roots. A run that read
    // none of them found no collision because it stopped, not because there is
    // none — and the failure it is standing in for aborts a whole shard.
    expect(count($declared))->toBeGreaterThan(
        1000,
        'The walk found '.count($declared).' file-scope helper names under '.implode(' and ', TEST_HELPER_ROOTS)
        .', which is what a broken reader looks like rather than a suite that stopped declaring helpers.'
    );

    $collisions = testHelperCollisionsIn($declared);

    expect($collisions)->toBe(
        [],
        'Two files under the roots Pest loads together declare the same file-scope function. Pest shares '
        ."one global namespace across the whole run, so this is a fatal that aborts the shard rather than the file:\n  "
        .implode("\n  ", $collisions)
    );
});

// A guard that cannot go red is a guard that says nothing, and both halves of
// this one are read off a list that is empty when the reader breaks.
it('reads a helper only where one is declared at file scope', function (): void {
    $source = <<<'PHP'
        <?php
        function seedsAFixture(): void {}
        function  spacedName ($argument) {}
        final class NotAHelper
        {
            public function method(): void {}
        }
        $closure = function (): void {};
        // function commentedOut(): void {}
        PHP;

    expect(testHelperNamesIn($source))->toBe(
        ['seedsAFixture', 'spacedName'],
        'The reader has to take the declarations that reach the global namespace and only those: a method '
        .'and a closure are indented or assigned, and neither can collide with anything.'
    );
});

it('names two files declaring one helper and stays quiet on one file declaring it twice', function (): void {
    expect(testHelperCollisionsIn(['seedsAFixture' => ['tests/ATest.php', 'tests/BTest.php']]))
        ->toBe(['seedsAFixture — tests/ATest.php, tests/BTest.php']);

    expect(testHelperCollisionsIn(['seedsAFixture' => ['tests/ATest.php', 'tests/ATest.php']]))
        ->toBe([], 'One file naming a helper twice is a redeclare inside that file, which PHP reports on its own.');
});
