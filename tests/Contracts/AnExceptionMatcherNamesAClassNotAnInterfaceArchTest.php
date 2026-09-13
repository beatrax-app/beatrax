<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// Pest's toThrow() branches on class_exists(), and an INTERFACE is not a class.
// Handed one it falls through to assertStringContainsString($name, $e->getMessage()),
// so `toThrow(Throwable::class)` asks whether the message contains the word
// "Throwable" — and `not->toThrow(Throwable::class)` is green either way.

// Twelve guards in this tree were written that way, nine of them over key
// custody and crypto, where "it did not blow up" was the whole claim. Proven by
// execution: a forget() rewritten to throw left its guard passing.

/** @return list<string> absolute paths to every test file this suite ships */
function exceptionMatcherTestFiles(): array
{
    /** @var list<string> $files */
    $files = [];

    foreach (['Modules', 'tests'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var Iterator<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($found as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, 'Test.php')) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The top-level class aliases a file declares, so `Foo::class` can be resolved
 * to the symbol it really names rather than matched as a word.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array<string, string> alias => fully qualified name
 */
function exceptionMatcherAliases(array $tokens): array
{
    $aliases = [];
    $depth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;

            continue;
        }

        if ($token === '}') {
            $depth--;

            continue;
        }

        if ($depth !== 0 || ! is_array($token) || $token[0] !== T_USE) {
            continue;
        }

        // `use function` and `use const` name something that is not a class,
        // and a closure's `use (...)` names no symbol at all.
        $next = $tokens[$i + 1] ?? null;
        if (is_array($next) && $next[0] === T_WHITESPACE) {
            $next = $tokens[$i + 2] ?? null;
        }
        if (is_array($next) && in_array($next[0], [T_FUNCTION, T_CONST], true)) {
            continue;
        }

        $statement = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $ahead = $tokens[$j];

            if ($ahead === ';' || $ahead === '(') {
                break;
            }

            if (is_array($ahead)) {
                $statement .= $ahead[1];
            }
        }

        $statement = trim($statement);

        if ($statement === '' || str_contains($statement, '{')) {
            continue;
        }

        $parts = PatternScan::split('/\s+as\s+/i', $statement);
        $target = trim((string) ($parts[0] ?? ''), '\\');

        if ($target === '') {
            continue;
        }

        $segments = explode('\\', $target);
        $alias = isset($parts[1]) ? trim($parts[1]) : (string) end($segments);
        $aliases[$alias] = $target;
    }

    return $aliases;
}

/**
 * Every `toThrow(...)` whose first argument is a `::class` constant, with that
 * name resolved through the file's own imports.
 *
 * @return list<array{name: string, line: int}>
 */
function exceptionMatcherTargetsIn(string $source): array
{
    $tokens = token_get_all($source);
    $aliases = exceptionMatcherAliases($tokens);
    $count = count($tokens);
    $targets = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'toThrow') {
            continue;
        }

        $at = $i + 1;
        while (is_array($tokens[$at] ?? null) && $tokens[$at][0] === T_WHITESPACE) {
            $at++;
        }

        if (($tokens[$at] ?? null) !== '(') {
            continue;
        }

        $name = exceptionMatcherClassConstant($tokens, $at + 1);

        if ($name === null) {
            continue;
        }

        $targets[] = [
            'name' => exceptionMatcherResolve($name, $aliases),
            'line' => $token[2],
        ];
    }

    return $targets;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function exceptionMatcherClassConstant(array $tokens, int $from): ?string
{
    while (is_array($tokens[$from] ?? null) && $tokens[$from][0] === T_WHITESPACE) {
        $from++;
    }

    $head = $tokens[$from] ?? null;
    $nameTokens = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    if (! is_array($head) || ! in_array($head[0], $nameTokens, true)) {
        return null;
    }

    $at = $from + 1;
    while (is_array($tokens[$at] ?? null) && $tokens[$at][0] === T_WHITESPACE) {
        $at++;
    }

    $operator = $tokens[$at] ?? null;
    $constant = $tokens[$at + 1] ?? null;

    if (! is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
        return null;
    }

    if (! is_array($constant) || strtolower($constant[1]) !== 'class') {
        return null;
    }

    return $head[1];
}

/**
 * @param  array<string, string>  $aliases
 */
function exceptionMatcherResolve(string $name, array $aliases): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $segments = explode('\\', $name);
    $head = (string) array_shift($segments);

    if (! isset($aliases[$head])) {
        return $name;
    }

    return $segments === [] ? $aliases[$head] : $aliases[$head].'\\'.implode('\\', $segments);
}

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */
it('hands toThrow() a class rather than an interface it will read as a message', function (): void {
    $files = exceptionMatcherTestFiles();

    // Read before the verdict: an empty walk makes the offender list below
    // empty too. The floor sits far under today's 3,057 test files.
    expect(count($files))->toBeGreaterThan(
        500,
        'the walk found '.count($files).' test files, which is too few to be this suite.'
    );

    $seen = 0;
    $offenders = [];

    // This file is the one place the banned shape is written on purpose: the
    // case at the end of it executes the matcher to show what it really does,
    // and a rule that read its own demonstration would report itself.
    $self = realpath(__FILE__);

    foreach ($files as $path) {
        if (realpath($path) === $self) {
            continue;
        }

        foreach (exceptionMatcherTargetsIn((string) file_get_contents($path)) as $target) {
            $seen++;

            // Affirmative on both halves: a name that loads as an interface and
            // not as a class. A name that loads as neither lives in the other
            // Composer root, and is not something this root can answer about.
            if (interface_exists($target['name']) && ! class_exists($target['name'])) {
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$target['line'].' → '.$target['name'];
            }
        }
    }

    // The same reason the file floor is read: a matcher reader that stopped
    // finding call sites would report this rule green over nothing.
    expect($seen)->toBeGreaterThan(
        300,
        'the reader found '.$seen.' toThrow() targets, which is too few to be this suite.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'toThrow() branches on class_exists(), so an interface falls through to a substring match',
        'on the exception MESSAGE. In the negative form the opposite expectation then catches that',
        'failure and returns green whether or not anything was thrown at all:',
        ...$offenders,
        '',
        'Name the concrete exception, or drop the matcher: invoking the call plainly fails the test',
        'with the real exception, which is better diagnostics than any matcher, and the postcondition',
        'the subject should leave behind is what the test has to assert.',
    ]));
});

// The tree holds none of these by construction, so the reader is driven against
// planted sources. The near-misses are the shapes toThrow() also takes: a
// closure, an instance, and a concrete class — none of which is the defect.
it('tells an interface argument from the other shapes toThrow() takes', function (): void {
    $interface = exceptionMatcherTargetsIn("<?php\n\nexpect(\$f)->not->toThrow(Throwable::class);\n");
    $qualified = exceptionMatcherTargetsIn("<?php\n\nexpect(\$f)->toThrow(\\Stringable::class);\n");
    $imported = exceptionMatcherTargetsIn("<?php\n\nuse Modules\\Auth\\Public\\Contracts\\KeyCustodian;\n\nexpect(\$f)->toThrow(KeyCustodian::class);\n");
    $aliased = exceptionMatcherTargetsIn("<?php\n\nuse Modules\\Auth\\Public\\Contracts\\KeyCustodian as Custody;\n\nexpect(\$f)->toThrow(Custody::class);\n");

    expect(array_column($interface, 'name'))->toBe(['Throwable'])
        ->and(array_column($qualified, 'name'))->toBe(['Stringable'])
        ->and(array_column($imported, 'name'))->toBe(['Modules\Auth\Public\Contracts\KeyCustodian'])
        ->and(array_column($aliased, 'name'))->toBe(['Modules\Auth\Public\Contracts\KeyCustodian'])
        ->and(interface_exists('Modules\Auth\Public\Contracts\KeyCustodian'))->toBeTrue()
        ->and(exceptionMatcherTargetsIn("<?php\n\nexpect(\$f)->toThrow(RuntimeException::class);\n"))
        ->toBe([['name' => 'RuntimeException', 'line' => 3]])
        ->and(exceptionMatcherTargetsIn("<?php\n\nexpect(\$f)->toThrow(fn (RuntimeException \$e) => null);\n"))->toBe([])
        ->and(exceptionMatcherTargetsIn("<?php\n\nexpect(\$f)->toThrow(new RuntimeException('boom'));\n"))->toBe([]);
});

// The claim the rule is built on, stated as an executable fact rather than as
// prose about a vendor file: the matcher really does read an interface as a
// message, and the opposite of it really does pass over a throw.
it('reads an interface as a message substring, which is why the rule exists', function (): void {
    $thrower = static fn (): never => throw new RuntimeException('a message that says nothing about interfaces');

    expect($thrower)->toThrow(RuntimeException::class);

    // Green over a subject that threw, which is what made the twelve guards
    // this branch rewrote unable to fail.
    expect($thrower)->not->toThrow(Throwable::class);

    // And the positive form passes only because the MESSAGE carries the word.
    expect(static fn (): never => throw new RuntimeException('Throwable'))->toThrow(Throwable::class);
});
