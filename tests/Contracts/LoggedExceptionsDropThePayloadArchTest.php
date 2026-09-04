<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-exception-message-logged-from-a-broad-catch
 */

/** @return list<string> repo-relative PHP files that ship */
function loggedExceptionShippedFiles(): array
{
    $files = [];

    foreach (['Modules', 'app'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
                $files[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The file's `use` statements, so a bare `Throwable` in a catch resolves to
 * the class it actually names.
 *
 * @return array<string, string> short name => fully-qualified name
 */
function loggedExceptionAliases(string $source): array
{
    $aliases = [];

    $matches = PatternScan::sets('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?;/mi', $source);

    foreach ($matches as $match) {
        $fqcn = $match[1];
        $short = $match[2] ?? substr($fqcn, (int) strrpos('\\'.$fqcn, '\\'));
        $aliases[ltrim($short, '\\')] = $fqcn;
    }

    return $aliases;
}

/**
 * @param  array<string, string>  $aliases
 */
function loggedExceptionCatchIsBroad(string $types, array $aliases): bool
{
    foreach (explode('|', $types) as $declared) {
        $parts = preg_split('/\s+/', trim($declared)) ?: [];
        $name = ltrim((string) ($parts[0] ?? ''), '\\');

        if ($name === '') {
            continue;
        }

        $resolved = $aliases[$name] ?? $name;

        // The question the rule actually asks: could a QueryException land
        // here? An unknown name is treated as broad, because a catch nobody
        // can resolve is not one anybody has checked either.
        if (! class_exists($resolved) && ! interface_exists($resolved)) {
            return true;
        }

        if (is_a(QueryException::class, $resolved, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array{types: string, body: string, offset: int}>
 */
function loggedExceptionCatches(string $source): array
{
    $catches = [];

    $matches = PatternScan::setsWithOffsets('/catch\s*\(([^)]*?)\)\s*\{/', $source);

    foreach ($matches as $match) {
        $start = (int) $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $cursor = $start;

        while ($depth > 0 && $cursor < strlen($source)) {
            $depth += match ($source[$cursor]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };
            $cursor++;
        }

        $catches[] = [
            'types' => (string) $match[1][0],
            'body' => substr($source, $start, $cursor - $start - 1),
            'offset' => $start,
        ];
    }

    return $catches;
}

/**
 * Calls that put their arguments somewhere durable. A daemon's stdout counts:
 * relay:serve and sync:serve run under a supervisor that captures it to the
 * same kind of file the logger writes.
 *
 * @return list<array{args: string, offset: int}>
 */
function loggedExceptionSinks(string $body, bool $isCommand): array
{
    $levels = 'emergency|alert|critical|error|warning|notice|info|debug';
    $receivers = ['\\$this->log(?:ger)?', '\\$\\w*[Ll]og(?:ger)?', 'Log', 'logger\\(\\)'];

    if ($isCommand) {
        $receivers[] = '\\$this';
    }

    $pattern = '/(?:'.implode('|', $receivers).')\s*(?:->|::)\s*(?:'.$levels.'|line|warn)\s*\(/';
    $sinks = [];

    $matches = PatternScan::allWithOffsets($pattern, $body);

    foreach ($matches[0] as $match) {
        $start = (int) $match[1] + strlen((string) $match[0]);
        $depth = 1;
        $cursor = $start;

        while ($depth > 0 && $cursor < strlen($body)) {
            $depth += match ($body[$cursor]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $cursor++;
        }

        $sinks[] = ['args' => substr($body, $start, $cursor - $start - 1), 'offset' => (int) $match[1]];
    }

    return $sinks;
}

/**
 * Narrowing where the message is read is the same guarantee as narrowing the
 * catch, so `$e instanceof ParseException ? $e->getMessage() : null` satisfies
 * the rule without the catch changing shape.
 *
 * @param  array<string, string>  $aliases
 */
function loggedExceptionWithoutNarrowedReads(string $args, array $aliases): string
{
    $pattern = '/\$(\w+)\s+instanceof\s+([\w\\\\]+)\s*\?\s*\$\1->getMessage\(\)/';

    return PatternScan::replaceCallback(
        $pattern,
        static fn (array $match): string => loggedExceptionCatchIsBroad($match[2], $aliases) ? $match[0] : '',
        $args,
    );
}

it('logs no exception message a query could have written the data into', function (): void {
    $offenders = [];

    foreach (loggedExceptionShippedFiles() as $file) {
        $source = (string) file_get_contents(base_path($file));

        if (! str_contains($source, 'getMessage()')) {
            continue;
        }

        $aliases = loggedExceptionAliases($source);
        $isCommand = str_ends_with($file, 'Command.php');

        foreach (loggedExceptionCatches($source) as $catch) {
            if (! loggedExceptionCatchIsBroad($catch['types'], $aliases)) {
                continue;
            }

            foreach (loggedExceptionSinks($catch['body'], $isCommand) as $sink) {
                if (! str_contains(loggedExceptionWithoutNarrowedReads($sink['args'], $aliases), 'getMessage()')) {
                    continue;
                }

                $line = substr_count($source, "\n", 0, $catch['offset'] + $sink['offset']) + 1;
                $offenders[] = $file.':'.$line.' — catch ('.trim($catch['types']).')';
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "This catch can receive a QueryException, whose message is the SQL and\n".
        "its bindings — the counterparty, the amount, the pairing frame — and the\n".
        "log it is written to is world-readable. Log SafeExceptionContext::\n".
        "describe(\$e) instead: the class and the SQLSTATE, which is what tells a\n".
        "lock timeout from a constraint violation. Where the message really is\n".
        "the diagnosis, narrow the catch to the type that carries it — or, when\n".
        "one catch legitimately receives both, narrow where the message is READ:\n".
        "\$e instanceof MessageNamesNoUserData ? \$e->getMessage() : null.\n".
        "Marking is a claim about a MESSAGE, so it is made per exception class\n".
        "and checked against every throw of it — never per call site. One throw\n".
        "that interpolates a row disqualifies the class.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});
