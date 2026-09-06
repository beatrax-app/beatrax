<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// getTraceAsString() renders the first fifteen characters of every string
// argument while zend.exception_ignore_args is Off, which is the interpreter's
// default. On the parse frames these catch blocks log, that argument is a row
// of the reader's bank statement. SafeTrace::cap() builds from the frames.

/**
 * Every first-party PHP file that could reach a logger. `routes`, `config`,
 * `bootstrap`, `database` and `scripts` are here beside Modules and app because
 * a catch block anywhere writes to the same 0644 daily log; a rule reading two
 * roots and claiming the tree is the shape this file is guarding against.
 *
 * @return list<string>
 */
function rawTraceSources(): array
{
    $files = [];

    foreach (['Modules', 'app', 'routes', 'config', 'bootstrap', 'database', 'scripts'] as $root) {
        $absolute = base_path($root);

        if (is_dir($absolute)) {
            $files = array_merge($files, rawTraceWalk($absolute));
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

// The reader is a token walk, and a token walk that stops answers "no calls" in
// the same words a clean tree does. Both halves are planted here: a call the
// walk must see, and the two shapes a text scan would report and it must not.
it('reads a getTraceAsString call and not the prose or the string that names one', function (): void {
    $calls = rawTraceCallLines("<?php\n\nfinal class PlantedTraceLog\n{\n    public function log(Throwable \$e): string\n    {\n        return \$e->getTraceAsString();\n    }\n}\n");

    expect($calls)->toBe([7], 'the walk must see a real getTraceAsString() call, on the line it sits on');

    expect(rawTraceCallLines("<?php\n// Assembled frame by frame rather than from getTraceAsString(), which\n\$safe = SafeTrace::cap(\$e);\n"))
        ->toBe([], 'a method named in a comment is not a call site');

    expect(rawTraceCallLines("<?php\n\$name = 'getTraceAsString';\n"))
        ->toBe([], 'a method named inside a string literal is not a call site');
});

it('renders no stack trace through getTraceAsString', function (): void {
    $hits = [];
    $files = rawTraceSources();

    // Every catch block in this tree stands behind this count. A walk that read
    // a handful of files found nothing because it stopped, not because it is clean.
    expect(count($files))->toBeGreaterThan(
        2_000,
        'The walk read almost nothing, so the empty offender list below is a tree nobody opened.',
    );

    foreach ($files as $path) {
        foreach (rawTraceCallLines(BladePhpSource::forPath($path, (string) file_get_contents($path))) as $line) {
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
