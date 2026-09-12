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
    $receivers = [
        '\\$this->log(?:ger)?',
        '\\$\\w*[Ll]og(?:ger)?',
        'Log',
        'logger\\(\\)',
        // A logger resolved inline is the same sink as an injected one. A queue
        // job's failed() hook cannot declare collaborators -- Laravel calls it
        // as a bare `$command->failed($e)` -- so this is the shape the one
        // place that CANNOT inject a logger is obliged to use.
        '(?:\\$\\w+(?:->\\w+)*|Container::getInstance\\(\\))->make\\(LoggerInterface::class\\)',
    ];

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
    // The property chain rides on the backreference, so the escape reads the
    // same on a local and on `$event->exception` -- which is where a JobFailed
    // listener has to narrow, having neither a catch nor a parameter.
    $pattern = '/\$(\w+)((?:->\w+)*)\s+instanceof\s+([\w\\\\]+)\s*\?\s*\$\1\2\??->getMessage\(\)/';

    return PatternScan::replaceCallback(
        $pattern,
        static fn (array $match): string => loggedExceptionCatchIsBroad($match[3], $aliases) ? $match[0] : '',
        $args,
    );
}

/**
 * A parameter type is not a catch type: the list is full of builtins and DTOs,
 * and the catch reader's "an unresolvable name is broad" clause would call
 * every one of them broad. So this asks the narrower question directly -- can a
 * QueryException be bound to this parameter -- which is the same question and
 * the only one a parameter list can answer.
 *
 * @param  array<string, string>  $aliases
 */
function loggedExceptionParamIsBroad(string $type, array $aliases): bool
{
    $builtins = ['int', 'float', 'string', 'bool', 'array', 'object', 'mixed',
        'callable', 'iterable', 'null', 'void', 'never', 'self', 'static', 'parent', 'false', 'true'];

    foreach (explode('|', $type) as $declared) {
        $name = ltrim(trim($declared), '?\\');

        if ($name === '' || in_array(strtolower($name), $builtins, true)) {
            continue;
        }

        $resolved = $aliases[$name] ?? $name;

        if ((class_exists($resolved) || interface_exists($resolved)) && is_a(QueryException::class, $resolved, true)) {
            return true;
        }
    }

    return false;
}

/**
 * The declared parameters, split at depth zero so a default value carrying a
 * comma or a parenthesis does not cut one in half.
 *
 * @return list<string>
 */
function loggedExceptionParamList(string $params): array
{
    $split = [];
    $depth = 0;
    $current = '';

    foreach (str_split($params) as $character) {
        $depth += match ($character) {
            '(', '[' => 1,
            ')', ']' => -1,
            default => 0,
        };

        if ($character === ',' && $depth === 0) {
            $split[] = $current;
            $current = '';

            continue;
        }

        $current .= $character;
    }

    $split[] = $current;

    return array_values(array_filter(array_map(trim(...), $split), static fn (string $p): bool => $p !== ''));
}

/**
 * A function whose parameter can receive a QueryException, with the body as the
 * region and the parameter as the variable the rule watches. This is the half
 * the catch walk cannot see: a queue job's `failed(?Throwable $e)` is handed one
 * with no catch anywhere, and a catch that calls `$this->logFailure($e)` puts
 * the read one frame below the body being read.
 *
 * @param  array<string, string>  $aliases
 * @return list<array{param: string, body: string, offset: int}>
 */
function loggedExceptionBroadParamFunctions(string $source, array $aliases): array
{
    $functions = [];

    foreach (PatternScan::allWithOffsets('/\bfunction\s+\w*\s*\(/', $source)[0] as $match) {
        $open = (int) $match[1] + strlen((string) $match[0]) - 1;
        $cursor = loggedExceptionBalanced($source, $open, '(', ')');

        if ($cursor === null) {
            continue;
        }

        $params = substr($source, $open + 1, $cursor - $open - 2);

        // An abstract or interface method ends at the semicolon and has no
        // body to read; so does a `use` clause's trailing signature.
        $brace = strpos($source, '{', $cursor);
        $semicolon = strpos($source, ';', $cursor);

        if ($brace === false || ($semicolon !== false && $semicolon < $brace)) {
            continue;
        }

        $end = loggedExceptionBalanced($source, $brace, '{', '}');

        if ($end === null) {
            continue;
        }

        foreach (loggedExceptionParamList($params) as $param) {
            $parts = PatternScan::first('/^(?:\#\[[^\]]*\]\s*)?(?:(?:public|protected|private|readonly)\s+)*([^\s$&.]+)\s+(?:&\s*)?(?:\.\.\.\s*)?\$(\w+)/', $param);

            if ($parts === [] || ! loggedExceptionParamIsBroad($parts[1], $aliases)) {
                continue;
            }

            $functions[] = [
                'param' => $parts[2],
                'body' => substr($source, $brace + 1, $end - $brace - 2),
                'offset' => $brace + 1,
            ];
        }

    }

    return $functions;
}

/**
 * The index one past the closer that matches the opener at $start, or null if
 * the source runs out first -- which is the answer that must not read as "no
 * body", so it is returned rather than guessed at.
 */
function loggedExceptionBalanced(string $source, int $start, string $open, string $close): ?int
{
    $depth = 0;
    $cursor = $start;
    $length = strlen($source);

    while ($cursor < $length) {
        $depth += match ($source[$cursor]) {
            $open => 1,
            $close => -1,
            default => 0,
        };
        $cursor++;

        if ($depth === 0) {
            return $cursor;
        }
    }

    return null;
}

/**
 * The same strip, with the removed text replaced by spaces rather than by
 * nothing. The two rules below report a line number off the whole file, so a
 * strip that shortened the source would move every line after the first
 * narrowed read.
 *
 * @param  array<string, string>  $aliases
 */
function loggedExceptionBlankNarrowedReads(string $source, array $aliases): string
{
    $pattern = '/\$(\w+)((?:->\w+)*)\s+instanceof\s+([\w\\\\]+)\s*\?\s*\$\1\2\??->getMessage\(\)/';

    return PatternScan::replaceCallback(
        $pattern,
        static fn (array $match): string => loggedExceptionCatchIsBroad($match[3], $aliases)
            ? $match[0]
            : str_repeat(' ', strlen($match[0])),
        $source,
    );
}

// A message lifted out of the throwable before it is logged is the same
// message: `$detail = $e->getMessage();` then `['d' => $detail]` puts exactly
// the payload this rule exists to keep out of the log. The event-property arm
// below already says so and watches its local; the catch and parameter arms
// read only the sink's own arguments, so the lift walked past both.
/**
 * @return list<string> the locals $body assigns a getMessage() read to
 */
function loggedExceptionLiftedLocals(string $body): array
{
    return PatternScan::all('/\\$(\\w+)\\s*=\\s*[^;]*\\bgetMessage\\(\\)/', $body)[1] ?? [];
}

/**
 * Whether $args reads the message, directly or through a local $body lifted it
 * into first.
 */
function loggedExceptionArgsCarryTheMessage(string $args, string $body, string $read): bool
{
    if (PatternScan::matches($read, $args)) {
        return true;
    }

    foreach (loggedExceptionLiftedLocals($body) as $local) {
        if (PatternScan::matches('/\\$'.preg_quote($local, '/').'\\b/', $args)) {
            return true;
        }
    }

    return false;
}

/**
 * The catches, the sinks inside them and the offenders among those, read off
 * one pass so the walk's denominators come from the same reader the control
 * below is driven through.
 *
 * @return array{catches: int, params: int, sinks: int, offenders: list<string>}
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

            if (! loggedExceptionArgsCarryTheMessage(
                loggedExceptionWithoutNarrowedReads($sink['args'], $aliases),
                loggedExceptionWithoutNarrowedReads($catch['body'], $aliases),
                '/getMessage\\(\\)/',
            )) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $catch['offset'] + $sink['offset']) + 1;
            $offenders[] = $line.' — catch ('.trim($catch['types']).')';
        }
    }

    $functions = loggedExceptionBroadParamFunctions($source, $aliases);

    foreach ($functions as $function) {
        foreach (loggedExceptionSinks($function['body'], $isCommand) as $sink) {
            $sinks++;

            $args = loggedExceptionWithoutNarrowedReads($sink['args'], $aliases);

            // Nullsafe counts: `failed(?Throwable $e)` reads `$e?->getMessage()`
            // and the message that returns is the same message.
            if (! loggedExceptionArgsCarryTheMessage(
                $args,
                loggedExceptionWithoutNarrowedReads($function['body'], $aliases),
                '/\\$'.$function['param'].'\\??->getMessage\\(\\)/',
            )) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $function['offset'] + $sink['offset']) + 1;
            $offenders[] = $line.' — $'.$function['param'].' arrived as a parameter, with no catch to narrow';
        }
    }

    // A JobFailed listener is handed the throwable on a property, so there is
    // neither a catch nor a parameter to narrow. Reading the message off one is
    // the offence by itself: the only reason to read it is to put it somewhere,
    // and the tree's two sites both do -- one into a log line, one into a
    // `last_error` column a screen polls. Lifting it into a local first is the
    // same read, so the local is watched under the same rule.
    $blanked = loggedExceptionBlankNarrowedReads($source, $aliases);
    $reads = ['/->exception\\??->getMessage\\(\\)/'];

    foreach (PatternScan::all('/\\$(\\w+)\\s*=\\s*\\$\\w+->exception\\s*;/', $source)[1] ?? [] as $local) {
        $reads[] = '/\\$'.$local.'\\??->getMessage\\(\\)/';
    }

    foreach ($reads as $read) {
        foreach (PatternScan::allWithOffsets($read, $blanked)[0] as $match) {
            $offenders[] = (substr_count($blanked, "\n", 0, (int) $match[1]) + 1).' — read off an event property';
        }
    }

    return ['catches' => $catches, 'params' => count($functions), 'sinks' => $sinks, 'offenders' => $offenders];
}

it('logs no exception message a query could have written the data into', function (): void {
    $files = loggedExceptionShippedFiles();

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $catches = 0;
    $params = 0;
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
        $params += $read['params'];
        $sinks += $read['sinks'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = $file.':'.$offender;
        }
    }

    // All three read before the verdict: the catches and the throwable-taking
    // functions are found by balanced-brace walks and the sinks by a third one
    // inside each body, and any of them stopping early leaves an empty offender
    // list that reads exactly like a clean tree. Measured on this commit: 76
    // broad catches, 35 functions taking a throwable, 20 sinks between them.
    expect($catches)->toBeGreaterThan(
        50,
        'the walk found '.$catches.' broad catches, which is too few to be this tree.'
    );

    expect($params)->toBeGreaterThan(
        15,
        'the walk found '.$params.' functions taking a throwable, which is too few to be this tree.'
    );

    expect($sinks)->toBeGreaterThan(
        5,
        'the walk found '.$sinks.' log calls inside a broad catch, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "This read can receive a QueryException, whose message is the SQL and\n".
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
        "Two of the three shapes have no catch to narrow at all: a queue job's\n".
        "failed(?Throwable \$e) is handed whatever was thrown, and a JobFailed\n".
        "listener reads it off \$event->exception. For those, narrow where the\n".
        "message is READ — the escape above works on a property chain too, and\n".
        "lifting it into a local first changes nothing about who can see it.\n".
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

// The three ways a throwable reaches a log line with no catch above it. Each is
// driven against a planted source and against its near-miss, because a walk
// that found neither would report a clean tree in exactly the same words.
it('tells a throwable that arrived as a parameter, off an event, and through a local', function (): void {
    $parameter = loggedExceptionOffendersIn(
        "<?php\nfunction failed(?Throwable \$e): void { Log::error(\$e?->getMessage()); }\n",
        false,
    );
    expect($parameter['params'])->toBe(1)
        ->and($parameter['sinks'])->toBe(1)
        ->and($parameter['offenders'])
        ->toBe(['2 — $e arrived as a parameter, with no catch to narrow']);

    // A parameter the rule must not claim: the list is mostly builtins and
    // DTOs, and the catch reader treats an unresolvable name as broad.
    $narrowParameter = loggedExceptionOffendersIn(
        "<?php\nfunction failed(string \$e): void { Log::error(\$e->getMessage()); }\n",
        false,
    );
    expect($narrowParameter['params'])->toBe(0)
        ->and($narrowParameter['offenders'])->toBe([]);

    // A concrete class a QueryException still fits inside.
    expect(loggedExceptionOffendersIn(
        "<?php\nfunction failed(RuntimeException \$e): void { Log::error(\$e->getMessage()); }\n",
        false,
    )['params'])->toBe(1);

    // A logger resolved inline is the sink a failed() hook is obliged to use.
    expect(loggedExceptionOffendersIn(
        "<?php\nfunction failed(Throwable \$e): void { \$c->make(LoggerInterface::class)->warning('x', [\$e->getMessage()]); }\n",
        false,
    )['offenders'])->toBe(['2 — $e arrived as a parameter, with no catch to narrow']);

    $property = loggedExceptionOffendersIn(
        "<?php\nfunction failed(JobFailed \$event): void { Log::error(\$event->exception->getMessage()); }\n",
        false,
    );
    expect($property['params'])->toBe(0)
        ->and($property['offenders'])->toBe(['2 — read off an event property']);

    // Lifting it into a local is the same read, and narrowing it there is the
    // one way out -- the same escape the catch walk already allows.
    $local = loggedExceptionOffendersIn(
        "<?php\nfunction failed(JobFailed \$event): void { \$thrown = \$event->exception; Log::error(\$thrown->getMessage()); }\n",
        false,
    );
    expect($local['offenders'])->toBe(['2 — read off an event property']);

    $narrowedLocal = loggedExceptionOffendersIn(
        "<?php\nfunction failed(JobFailed \$event): void { \$thrown = \$event->exception; Log::error(\$thrown instanceof InvalidArgumentException ? \$thrown->getMessage() : null); }\n",
        false,
    );
    expect($narrowedLocal['offenders'])->toBe([]);

    // Blanking rather than deleting: the narrowed read above sits on the line
    // the offender below is numbered from, and a strip that shortened the
    // source would report the wrong one.
    $lineNumber = loggedExceptionOffendersIn(
        "<?php\nfunction a(JobFailed \$e): void { Log::error(\$e->exception instanceof InvalidArgumentException ? \$e->exception->getMessage() : null); }\n"
            ."function b(JobFailed \$e): void { Log::error(\$e->exception->getMessage()); }\n",
        false,
    );
    expect($lineNumber['offenders'])->toBe(['3 — read off an event property']);
});

/**
 * The fourth way a message reaches the log, and the one the walk above cannot
 * see: the throwable itself, handed to the sink as an argument. Monolog's
 * LineFormatter renders it -- class, message, `getTraceAsString()`, and every
 * `previous` beneath it -- so `['exception' => $e]` publishes strictly more
 * than `$e->getMessage()` while containing neither of those words.
 *
 * @return int the number of handovers of $var in $args
 */
function loggedExceptionRawThrowableReads(string $args, string $var): int
{
    // Blanked rather than skipped: a throwable inside a nested call is that
    // call's argument, and what reaches the sink is the call's RETURN --
    // SafeExceptionContext::describe($e) and QueryFailure::isUniqueViolation($e) alike.
    // Only what survives at depth zero was handed over as the object.
    $topLevel = loggedExceptionArgsAtTopLevel($args);

    // A property read, a static fetch, a type test and a comparison all leave
    // the throwable where it is. Anything else publishes the object.
    $matches = PatternScan::all(
        '/\$'.preg_quote($var, '/').'\b(?!\s*(?:\?->|->|::|instanceof\b|===|!==|==|!=|\?\?))/',
        $topLevel,
    );

    return count($matches[0] ?? []);
}

/**
 * $args with every parenthesised region blanked to spaces, and every quoted
 * literal with it -- a `(` inside a message would otherwise move the depth.
 * Offsets are preserved so nothing downstream has to re-measure.
 */
function loggedExceptionArgsAtTopLevel(string $args): string
{
    $out = '';
    $depth = 0;
    $length = strlen($args);
    $cursor = 0;

    while ($cursor < $length) {
        $character = $args[$cursor];

        if ($character === "'" || $character === '"') {
            $closed = loggedExceptionSkipLiteral($args, $cursor, $character);
            $out .= str_repeat(' ', $closed - $cursor);
            $cursor = $closed;

            continue;
        }

        $depth += match ($character) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };

        $out .= $depth > 0 || $character === ')' ? ' ' : $character;
        $cursor++;
    }

    return $out;
}

/** The index one past the closing quote that matches the one at $start. */
function loggedExceptionSkipLiteral(string $args, int $start, string $quote): int
{
    $cursor = $start + 1;
    $length = strlen($args);

    while ($cursor < $length) {
        if ($args[$cursor] === '\\') {
            $cursor += 2;

            continue;
        }

        if ($args[$cursor] === $quote) {
            return $cursor + 1;
        }

        $cursor++;
    }

    return $length;
}

/**
 * The variable a catch binds, or null for `catch (Throwable)` with no name.
 */
function loggedExceptionCatchVariable(string $types): ?string
{
    $match = PatternScan::first('/\$(\w+)\s*$/', trim($types));

    return $match === [] ? null : (string) $match[1];
}

/**
 * The locals holding a throwable this file produced itself. A method declaring
 * `?Throwable` hands one back with no catch and no parameter above it, which
 * is how the patch runner's failure reached a log line the two region walks
 * below could not see.
 *
 * @param  array<string, string>  $aliases
 * @return list<string> the variable names assigned from such a call
 */
function loggedExceptionThrowableLocals(string $source, array $aliases): array
{
    $returners = [];

    foreach (PatternScan::sets('/\bfunction\s+(\w+)\s*\([^()]*\)\s*:\s*([^\s{;]+)/', $source) as $match) {
        if (loggedExceptionParamIsBroad((string) $match[2], $aliases)) {
            $returners[] = preg_quote((string) $match[1], '/');
        }
    }

    if ($returners === []) {
        return [];
    }

    $assignments = PatternScan::sets(
        '/\$(\w+)\s*=\s*(?:\$this->|self::|static::)(?:'.implode('|', $returners).')\s*\(/',
        $source,
    );

    return array_values(array_unique(array_map(static fn (array $m): string => (string) $m[1], $assignments)));
}

/**
 * @return array{sinks: int, offenders: list<string>}
 */
function loggedExceptionObjectOffendersIn(string $source, bool $isCommand): array
{
    $aliases = loggedExceptionAliases($source);
    $sinks = 0;
    $offenders = [];

    foreach (loggedExceptionCatches($source) as $catch) {
        $variable = loggedExceptionCatchVariable($catch['types']);

        if ($variable === null || ! loggedExceptionCatchIsBroad($catch['types'], $aliases)) {
            continue;
        }

        foreach (loggedExceptionSinks($catch['body'], $isCommand) as $sink) {
            $sinks++;

            if (loggedExceptionRawThrowableReads($sink['args'], $variable) === 0) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $catch['offset'] + $sink['offset']) + 1;
            $offenders[] = $line.' — $'.$variable.' handed to the sink whole';
        }
    }

    foreach (loggedExceptionBroadParamFunctions($source, $aliases) as $function) {
        foreach (loggedExceptionSinks($function['body'], $isCommand) as $sink) {
            $sinks++;

            if (loggedExceptionRawThrowableReads($sink['args'], $function['param']) === 0) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $function['offset'] + $sink['offset']) + 1;
            $offenders[] = $line.' — $'.$function['param'].' handed to the sink whole';
        }
    }

    foreach (loggedExceptionThrowableLocals($source, $aliases) as $local) {
        foreach (loggedExceptionSinks($source, $isCommand) as $sink) {
            if (loggedExceptionRawThrowableReads($sink['args'], $local) === 0) {
                continue;
            }

            $line = substr_count($source, "\n", 0, $sink['offset']) + 1;
            $offenders[] = $line.' — $'.$local.' came back from a call, with no catch to narrow';
        }
    }

    return ['sinks' => $sinks, 'offenders' => $offenders];
}

it('hands no throwable to a log call as a value', function (): void {
    $files = loggedExceptionShippedFiles();

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $sinks = 0;
    $offenders = [];

    foreach ($files as $path) {
        $file = str_replace(RepoTree::root().'/', '', $path);
        $source = (string) file_get_contents($path);

        if (! str_contains($source, 'catch') && ! str_contains($source, 'function')) {
            continue;
        }

        $read = loggedExceptionObjectOffendersIn($source, str_ends_with($file, 'Command.php'));
        $sinks += $read['sinks'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = $file.':'.$offender;
        }
    }

    // Read before the verdict: this walk reuses the same balanced-brace
    // readers, and one of them stopping early leaves an empty offender list
    // that reads exactly like a clean tree.
    expect($sinks)->toBeGreaterThan(
        5,
        'the walk found '.$sinks.' log calls in a region a throwable reaches, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "A throwable put into a log call is rendered by Monolog's LineFormatter,\n".
        "which the shipped channels build with includeStacktraces on. It writes\n".
        "the class, getMessage(), getTraceAsString() and the whole previous\n".
        "chain — so ['exception' => \$e] publishes the SQL and its bindings that\n".
        "the rule above exists to withhold, plus the fifteen characters of every\n".
        "string argument that SafeTrace was written to drop.\n".
        "Spread SafeExceptionContext::describe(\$e) instead, and SafeTrace::cap()\n".
        "where the frames are worth keeping. \$e::class, \$e->getCode() and\n".
        "\$e instanceof X are reads, not handovers, and stay allowed.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

// Driven against planted sources, because the tree hands over no throwable and
// a reader that found nothing would report that in the same words.
it('tells a throwable handed over whole from one that is only read', function (): void {
    $handed = loggedExceptionObjectOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error('x', ['exception' => \$e]); }\n",
        false,
    );
    expect($handed['sinks'])->toBe(1)
        ->and($handed['offenders'])->toBe(['2 — $e handed to the sink whole']);

    // The throwable as the message itself, which is the same handover.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error(\$e); }\n",
        false,
    )['offenders'])->toBe(['2 — $e handed to the sink whole']);

    // The parameter shape, which has no catch above it at all.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\nfunction failed(?Throwable \$e): void { Log::error('x', ['exception' => \$e]); }\n",
        false,
    )['offenders'])->toBe(['2 — $e handed to the sink whole']);

    // The four shapes that read the throwable rather than publishing it.
    $read = "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error('x', ["
        ."'class' => \$e::class, 'code' => \$e->getCode(), "
        .'...SafeExceptionContext::describe($e), '
        ."'trace' => SafeTrace::cap(\$e, \$app->basePath()), "
        ."'m' => \$e instanceof LogicException ? \$e->getMessage() : null]); }\n";
    $allowed = loggedExceptionObjectOffendersIn($read, false);
    expect($allowed['sinks'])->toBe(1)
        ->and($allowed['offenders'])->toBe([]);

    // A catch a QueryException cannot reach is outside the rule entirely.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\ntry { \$this->run(); } catch (SodiumException \$e) { Log::error('x', ['exception' => \$e]); }\n",
        false,
    )['offenders'])->toBe([]);

    // A catch with no variable binds nothing there is to hand over.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable) { Log::error('x'); }\n",
        false,
    )['offenders'])->toBe([]);

    // The depth reader skips quoted literals: a parenthesis inside a message
    // would otherwise leave the rest of the arguments reading as nested.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\ntry { \$this->run(); } catch (Throwable \$e) { Log::error('it failed (badly)', ['exception' => \$e]); }\n",
        false,
    )['offenders'])->toBe(['2 — $e handed to the sink whole']);

    // A null check is a comparison, not a handover, and the describe() beside
    // it is the sanctioned read. This shape is in the tree.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\nfunction failed(?Throwable \$e): void { Log::error('x', [...(\$e === null ? [] : SafeExceptionContext::describe(\$e))]); }\n",
        false,
    )['offenders'])->toBe([]);
});

// The third region, which has neither a catch nor a parameter: a method of
// this same class declaring it returns one.
it('tells a throwable that came back from a call from a value that is not one', function (): void {
    expect(loggedExceptionObjectOffendersIn(
        "<?php\nfunction run(): void { \$failure = \$this->attempt(); Log::error('x', ['exception' => \$failure]); }\nfunction attempt(): ?Throwable { return null; }\n",
        false,
    )['offenders'])->toBe(['2 — $failure came back from a call, with no catch to narrow']);

    expect(loggedExceptionObjectOffendersIn(
        "<?php\nfunction run(): void { \$failure = \$this->attempt(); Log::error('x', ...SafeExceptionContext::describe(\$failure)); }\nfunction attempt(): ?Throwable { return null; }\n",
        false,
    )['offenders'])->toBe([]);

    // The near-miss the rule must not claim: a method handing back a string
    // named `$failure` is a message, and the string rules above cover it.
    expect(loggedExceptionObjectOffendersIn(
        "<?php\nfunction run(): void { \$failure = \$this->attempt(); Log::error('x', ['exception' => \$failure]); }\nfunction attempt(): ?string { return null; }\n",
        false,
    )['offenders'])->toBe([]);
});
