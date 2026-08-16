<?php

declare(strict_types=1);

/*
 * An Alpine attribute value must not open with a `//` line comment.
 *
 * Alpine compiles the attribute as an EXPRESSION. A leading `//` pushes the
 * real code onto the next line, so a statement there — `if`, `let`, `for` —
 * raises "Alpine Expression Error: Unexpected token 'if'" and the directive
 * never runs.
 *
 * It fails quietly in the place it hurts: the biometric-capability probe on
 * the app-lock screen threw on every render, so the capability was never
 * reported and the biometric option stayed hidden on hardware that supports
 * it. Nothing failed server-side and no test noticed.
 *
 * Only the LEADING position is rejected. Comments inside a nested function
 * body (`x-data="{ init() { // ... } }"`) compile fine and are common in
 * these views, so flagging every `//` would be wrong.
 */

/** @return list<string> */
function alpineBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('never opens an Alpine expression with a line comment', function (): void {
    $offenders = [];

    foreach (alpineBladeFiles() as $path) {
        $contents = (string) file_get_contents($path);

        $matched = preg_match_all(
            '/\b(x-(?:init|effect|show|model|text|html|if|on:[\w.\-]+|bind:[\w.\-]+)|@[\w.\-]+)\s*=\s*"(\s*)\/\//',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        if ($matched === false || $matched === 0) {
            continue;
        }

        foreach ($matches as $match) {
            $offenders[] = str_replace(base_path().'/', '', $path).' → '.$match[1];
        }
    }

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "An Alpine expression cannot start with a `//` comment — the directive throws and\n".
        "silently never runs. Move the comment into a {{-- Blade comment --}} above the\n".
        "element:\n  ".implode("\n  ", array_unique($offenders)),
    );
});
