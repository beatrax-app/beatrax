<?php

declare(strict_types=1);

// Pest compiles every test file into one process, so the helper functions they
// declare at file scope share a single global namespace. Two files declaring
// the same name is a fatal — "Cannot redeclare function" — which takes down
// the whole shard, not the two files, and reports at whichever file the runner
// reached second. Three collisions arrived in one branch this way, each of
// them a three-letter prefix of a test class name that another test class also
// abbreviates to.

/**
 * @return array<string, list<string>>
 */
function declaredTestHelpers(): array
{
    $byName = [];

    foreach (['Modules', 'tests'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());
            $contents = (string) file_get_contents($file->getPathname());

            // File scope only: an indented `function` is a method or a closure,
            // and neither reaches the global namespace.
            preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $contents, $matches);

            foreach ($matches[1] as $name) {
                $byName[$name][] = $relative;
            }
        }
    }

    return $byName;
}

it('does not let two test files declare the same helper function', function (): void {
    $collisions = [];

    foreach (declaredTestHelpers() as $name => $files) {
        $distinct = array_values(array_unique($files));
        if (count($distinct) > 1) {
            $collisions[] = $name.' — '.implode(', ', $distinct);
        }
    }

    sort($collisions);

    expect($collisions)->toBe(
        [],
        'Two test files declare the same file-scope function. Pest shares one global namespace '
        ."across the whole run, so this is a fatal that aborts the shard rather than the file:\n  "
        .implode("\n  ", $collisions)
    );
});
