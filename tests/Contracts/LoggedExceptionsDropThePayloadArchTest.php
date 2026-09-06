<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-exception-message-logged-from-a-broad-catch
 */

/**
 * Every root that ships, from the one place a scope is declared. The walk
 * opened Modules/ and app/, which left a seeder and a route closure -- both of
 * which catch and log around the same connection -- outside a rule whose
 * subject is the query that wrote the data in.
 *
 * @return list<string> absolute paths to every PHP file that ships
 */
function loggedExceptionShippedFiles(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
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
        $parts = PatternScan::split('/\s+/', trim($declared));
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

/**
 * The catches, the sinks inside them and the offenders among those, read off
 * one pass so the walk's denominators come from the same reader the control
 * below is driven through.
 *
 * @return array{catches: int, sinks: int, offenders: list<string>}
 */
function loggedExceptionOffendersIn(string $source, bool $isCommand): array
{
    $aliases = loggedExceptionAliases($source);
    $catches = 0;
    $sinks = 0;
    $offenders = [];

    foreach (loggedExceptionCatches($source) as $catch) {
        if (! loggedExceptionCatchIsBroad($catch['types'], $aliases)) {
            continue;
        }

        $catches++;

        foreach (loggedExceptionSinks($catch['body'], $isCommand) as $sink) {
            $sinks++;

            if (! str_contains(loggedExceptionWithoutNarrowedReads($sink['args'], $aliases), 'getMessage()')) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $catch['offset'] + $sink['offset']) + 1;
            $offenders[] = $line.' — catch ('.trim($catch['types']).')';
        }
    }

    return ['catches' => $catches, 'sinks' => $sinks, 'offenders' => $offenders];
}

it('logs no exception message a query could have written the data into', function (): void {
    $files = loggedExceptionShippedFiles();

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $catches = 0;
    $sinks = 0;
    $offenders = [];

    foreach ($files as $path) {
        $file = str_replace(RepoTree::root().'/', '', $path);
        $source = (string) file_get_contents($path);

        // Nothing without this call can offend, and the balanced-brace reader
        // below is the expensive half of the walk.
        if (! str_contains($source, 'getMessage()')) {
            continue;
        }

        $read = loggedExceptionOffendersIn($source, str_ends_with($file, 'Command.php'));
        $catches += $read['catches'];
        $sinks += $read['sinks'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = $file.':'.$offender;
        }
    }

    // Both read before the verdict: the catches are found by a balanced-brace
    // walk and the sinks by a second one inside each body, and either stopping
    // early leaves an empty offender list that reads exactly like a clean tree.
    // The floors sit far under today's 187 broad catches and 21 sinks in them.
    expect($catches)->toBeGreaterThan(
        50,
        'the walk found '.$catches.' broad catches, which is too few to be this tree.'
    );

    expect($sinks)->toBeGreaterThan(
        5,
        'the walk found '.$sinks.' log calls inside a broad catch, which is too few to be this tree.'
    );

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

// The tree logs no such message, so this rule reports on what it cannot find
// and the reader is driven against planted sources. The near-misses are the
// three shapes that are deliberately allowed: a catch a QueryException cannot
// reach, a message read only after the type is narrowed, and a catch that logs
// nothing at all.
it('tells a broad catch logging the message from a narrow one, a narrowed read and a silent catch', function (): void {
    $broad = loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error(\$e->getMessage()); }\n",
        false,
    );
    expect($broad['catches'])->toBe(1)
        ->and($broad['sinks'])->toBe(1)
        ->and($broad['offenders'])->toBe(['2 — catch (Throwable $e)']);

    $narrow = loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (InvalidArgumentException \$e) { Log::error(\$e->getMessage()); }\n",
        false,
    );
    expect($narrow['catches'])->toBe(0)
        ->and($narrow['offenders'])->toBe([]);

    $narrowedRead = loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error(\$e instanceof InvalidArgumentException ? \$e->getMessage() : null); }\n",
        false,
    );
    expect($narrowedRead['catches'])->toBe(1)
        ->and($narrowedRead['sinks'])->toBe(1)
        ->and($narrowedRead['offenders'])->toBe([]);

    $silent = loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { report(\$e); }\n",
        false,
    );
    expect($silent['catches'])->toBe(1)
        ->and($silent['sinks'])->toBe(0)
        ->and($silent['offenders'])->toBe([]);

    // A command writes to stdout, which a supervisor captures to the same kind
    // of file. That door only opens for a *Command.php, and this is the flag.
    $command = loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { \$this->error(\$e->getMessage()); }\n",
        true,
    );
    expect($command['sinks'])->toBe(1)
        ->and($command['offenders'])->toBe(['2 — catch (Throwable $e)']);

    expect(loggedExceptionOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { \$this->error(\$e->getMessage()); }\n",
        false,
    )['sinks'])->toBe(0);
});
