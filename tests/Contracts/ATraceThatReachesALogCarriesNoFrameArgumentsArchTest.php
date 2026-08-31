<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// getTraceAsString() renders the first fifteen characters of every string
// argument while zend.exception_ignore_args is Off, which is the interpreter's
// default. On the parse frames these catch blocks log, that argument is a row
// of the reader's bank statement. SafeTrace::cap() builds from the frames.

/**
 * @return list<string>
 */
function rawTraceSources(): array
{
    $files = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        if (is_dir($root)) {
            $files = array_merge($files, rawTraceWalk($root));
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function rawTraceWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        // mobile-app/ reaches this same tree through symlinks, and following one
        // reports every shared file a second time under a second spelling.
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            // A test that asserts on the rendering has to be able to name it.
            if ($entry === 'tests') {
                continue;
            }

            $files = array_merge($files, rawTraceWalk($path));

            continue;
        }

        if (str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

/**
 * Read as tokens rather than matched as text: the method is named in prose in
 * the class that exists to replace it, and a scan reading that prose would
 * report a call nobody wrote.
 *
 * @return list<int> the 1-indexed lines calling getTraceAsString
 */
function rawTraceCallLines(string $source): array
{
    $lines = [];

    foreach (@token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'getTraceAsString') {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

it('renders no stack trace through getTraceAsString', function (): void {
    $hits = [];

    foreach (rawTraceSources() as $path) {
        foreach (rawTraceCallLines((string) file_get_contents($path)) as $line) {
            $hits[] = str_replace(base_path().'/', '', $path).':'.$line;
        }
    }

    expect($hits)->toBe([], implode("\n", [
        'These render a trace with its frame arguments still in it:',
        ...$hits,
        '',
        'Log Modules\Core\Public\Support\SafeTrace::cap($e, $app->basePath()) instead.',
        'It builds the same lines out of getTrace(), which the renderer never sees,',
        'so no argument of any frame reaches the 0644 daily log.',
    ]));
});
