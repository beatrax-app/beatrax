<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 */

// The hosted scanner's scope, so a tree this rule clears is a tree the hosted
// analysis clears. S1186 carries the main-sources scope, which is why the test
// roots sit in this list even though sonar.tests does analyse them: the empty
// fakes and spies living there are not findings and never will be.
const SONAR_EMPTY_BODY_EXCLUDED_FRAGMENTS = [
    '/vendor/',
    '/node_modules/',
    '/tests/',
    '/Database/Migrations/',
    '/database/schema/',
    '/Resources/lang/',
];

// Everything that may stand between an attribute and `function` is part of the
// declaration rather than something in front of it. The distance is measured to
// the declaration's FIRST token, which is why the list matters.
const SONAR_EMPTY_BODY_MODIFIERS = [
    T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY,
];

/**
 * @return list<string>
 */
function sonarEmptyBodyFiles(): array
{
    $files = [];

    foreach (['app', 'Modules', 'config', 'routes', 'database'] as $root) {
        $path = base_path($root);

        if (is_dir($path)) {
            $files = array_merge($files, sonarEmptyBodyWalk($path));
        }
    }

    sort($files);

    return $files;
}

/**
 * @return list<string>
 */
function sonarEmptyBodyWalk(string $directory): array
{
    $files = [];

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;

        // mobile-app/ reaches this same tree through symlinks, and following
        // one reports every shared file a second time under a second spelling.
        if (is_link($path)) {
            continue;
        }

        if (is_dir($path)) {
            $files = array_merge($files, sonarEmptyBodyWalk($path));

            continue;
        }

        if (str_ends_with($path, '.php') && ! sonarEmptyBodyExcluded($path)) {
            $files[] = $path;
        }
    }

    return $files;
}

function sonarEmptyBodyExcluded(string $path): bool
{
    foreach (SONAR_EMPTY_BODY_EXCLUDED_FRAGMENTS as $fragment) {
        if (str_contains($path, $fragment)) {
            return true;
        }
    }

    return false;
}

/**
 * A body with no statements is reported unless the enclosing class is abstract,
 * the constructor promotes a property, the last comment between the braces
 * carries three word characters, or the last comment above the declaration does
 * and ends on the line directly before it.
 *
 * @return list<string> one entry per body the analyser would report
 */
function sonarEmptyBodyOffenders(string $source, string $label = ''): array
{
    /** @var list<array{0:int,1:string,2:int}|string> $tokens */
    $tokens = token_get_all($source);
    $count = count($tokens);
    $hits = [];

    /** @var list<string> $scopes */
    $scopes = [];
    $pendingScope = null;
    $sawAbstract = false;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;

        if ($id === T_ABSTRACT) {
            $sawAbstract = true;
        } elseif ($id === T_CLASS) {
            $pendingScope = sonarEmptyBodySignificantId($tokens, $i, -1) === T_NEW
                ? 'type'
                : ($sawAbstract ? 'abstract-class' : 'class');
        } elseif ($id === T_INTERFACE || $id === T_TRAIT || $id === T_ENUM) {
            $pendingScope = 'type';
        } elseif ($text === '{') {
            $scopes[] = $pendingScope ?? 'block';
            $pendingScope = null;
            $sawAbstract = false;
        } elseif ($text === '}') {
            array_pop($scopes);
        } elseif ($text === ';') {
            $pendingScope = null;
            $sawAbstract = false;
        } elseif ($id === T_FUNCTION) {
            $hit = sonarEmptyBodyInspect($tokens, $i, $scopes, $label);

            if ($hit !== null) {
                $hits[] = $hit;
            }
        }
    }

    return $hits;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @param  list<string>  $scopes
 */
function sonarEmptyBodyInspect(array $tokens, int $functionIndex, array $scopes, string $label): ?string
{
    // `use function A\b;` puts T_STRING after T_FUNCTION exactly as a
    // declaration does, and a closure puts nothing there at all.
    if (sonarEmptyBodySignificantId($tokens, $functionIndex, -1) === T_USE) {
        return null;
    }

    $nameIndex = sonarEmptyBodySignificantIndex($tokens, $functionIndex, 1);
    $nameToken = $nameIndex === null ? null : $tokens[$nameIndex];

    if (! is_array($nameToken) || $nameToken[0] !== T_STRING) {
        return null;
    }

    $body = sonarEmptyBodyRange($tokens, $nameIndex ?? $functionIndex);

    // An interface method and an abstract one end on `;` rather than a block,
    // and neither is a body that could have been left empty.
    if ($body === null) {
        return null;
    }

    [$open, $close] = $body;
    $lastComment = null;

    for ($k = $open + 1; $k < $close; $k++) {
        $inner = $tokens[$k];

        if (! is_array($inner)) {
            return null;
        }
        if ($inner[0] === T_WHITESPACE) {
            continue;
        }
        if ($inner[0] === T_COMMENT || $inner[0] === T_DOC_COMMENT) {
            $lastComment = $inner[1];

            continue;
        }

        return null;
    }

    // Only the LAST comment before the closing brace is read, so a block whose
    // final line is a bare URL or an em dash counts as no comment at all.
    if ($lastComment !== null && sonarEmptyBodyIsValuable($lastComment)) {
        return null;
    }

    $enclosing = $scopes === [] ? 'block' : $scopes[count($scopes) - 1];
    $isMethod = in_array($enclosing, ['class', 'abstract-class', 'type'], true);

    if ($isMethod && $enclosing === 'abstract-class') {
        return null;
    }
    if ($isMethod && strcasecmp($nameToken[1], '__construct') === 0 && sonarEmptyBodyPromotes($tokens, $nameIndex ?? $functionIndex)) {
        return null;
    }

    $first = sonarEmptyBodyFirstIndex($tokens, $functionIndex);

    if (sonarEmptyBodyHasCommentAbove($tokens, $first)) {
        return null;
    }

    $line = is_array($tokens[$first]) ? $tokens[$first][2] : $nameToken[2];

    return ($label === '' ? '' : $label.':').$line.' '.$nameToken[1].'()';
}

function sonarEmptyBodyIsValuable(string $comment): bool
{
    return preg_match('/\w{3}/', $comment) === 1;
}

/**
 * The declaration's first token: attributes and modifiers belong to it, and a
 * comment is measured against that line rather than against `function`.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodyFirstIndex(array $tokens, int $functionIndex): int
{
    $first = $functionIndex;

    for ($i = $functionIndex - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        if (is_array($token) && in_array($token[0], SONAR_EMPTY_BODY_MODIFIERS, true)) {
            $first = $i;

            continue;
        }

        if (! is_array($token) && $token === ']') {
            $opening = sonarEmptyBodyAttributeStart($tokens, $i);

            if ($opening === null) {
                return $first;
            }

            $first = $opening;
            $i = $opening;

            continue;
        }

        return $first;
    }

    return $first;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodyAttributeStart(array $tokens, int $closeIndex): ?int
{
    $depth = 0;

    for ($i = $closeIndex; $i >= 0; $i--) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if ($text === ']') {
            $depth++;
        } elseif (is_array($token) && $token[0] === T_ATTRIBUTE) {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        } elseif ($text === '[') {
            $depth--;
        }
    }

    return null;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodyHasCommentAbove(array $tokens, int $firstIndex): bool
{
    for ($i = $firstIndex - 1; $i >= 0; $i--) {
        $token = $tokens[$i];

        if (! is_array($token)) {
            return false;
        }
        if ($token[0] === T_WHITESPACE) {
            continue;
        }
        if ($token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
            return false;
        }

        $firstLine = is_array($tokens[$firstIndex]) ? $tokens[$firstIndex][2] : 0;

        // A `//` token carries its terminating newline, which would put its end
        // one line further down than the line the reader sees it on.
        $endLine = $token[2] + substr_count(rtrim($token[1], "\r\n"), "\n");

        return $endLine === $firstLine - 1 && sonarEmptyBodyIsValuable($token[1]);
    }

    return false;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodyPromotes(array $tokens, int $nameIndex): bool
{
    $count = count($tokens);
    $depth = 0;

    for ($i = $nameIndex; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;

            if ($depth === 0) {
                return false;
            }
        } elseif ($depth === 1 && is_array($token) && in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array{0:int,1:int}|null
 */
function sonarEmptyBodyRange(array $tokens, int $from): ?array
{
    $count = count($tokens);
    $depth = 0;

    for ($i = $from; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;
        } elseif ($depth === 0 && $text === ';') {
            return null;
        } elseif ($depth === 0 && $text === '{') {
            return sonarEmptyBodyBlock($tokens, $i);
        }
    }

    return null;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array{0:int,1:int}
 */
function sonarEmptyBodyBlock(array $tokens, int $open): array
{
    $count = count($tokens);
    $depth = 0;

    for ($i = $open; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = is_array($token) ? $token[1] : $token;

        if ($text === '{') {
            $depth++;
        } elseif ($text === '}') {
            $depth--;

            if ($depth === 0) {
                return [$open, $i];
            }
        }
    }

    return [$open, $count - 1];
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodySignificantIndex(array $tokens, int $from, int $step): ?int
{
    $count = count($tokens);

    for ($i = $from + $step; $i >= 0 && $i < $count; $i += $step) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function sonarEmptyBodySignificantId(array $tokens, int $from, int $step): int|string|null
{
    $index = sonarEmptyBodySignificantIndex($tokens, $from, $step);

    if ($index === null) {
        return null;
    }

    return is_array($tokens[$index]) ? $tokens[$index][0] : $tokens[$index];
}

it('leaves no empty body the hosted analyser would report', function (): void {
    $files = sonarEmptyBodyFiles();
    expect($files)->not->toBe([]);

    // Widening this to the test roots reads as thoroughness and is not. The
    // rule reports main sources only, so every fake and spy it turned up there
    // would be a failure the hosted analysis is never going to raise.
    expect(array_values(array_filter($files, static fn (string $path): bool => str_contains($path, '/tests/'))))->toBe([]);

    $hits = [];
    foreach ($files as $path) {
        foreach (sonarEmptyBodyOffenders((string) file_get_contents($path), $path) as $hit) {
            $hits[] = $hit;
        }
    }

    expect($hits)->toBe([], "An empty body must say why it is empty, in one of the two places the analyser actually reads: the last comment between the braces, or the last comment directly above the declaration with no blank line between them. Anywhere else is invisible to it and the build fails in CI instead of here. The comment must contain three consecutive word characters, so a final line that is only punctuation, an arrow or a bare URL reads as no comment at all. Offenders:\n  ".implode("\n  ", $hits));
});

it('accepts an explanation in either place the analyser reads', function (): void {
    $inside = '<?php class A { public function f(): void { // the peer already holds this
    } }';
    $above = '<?php class A {
    // the peer already holds this
    public function f(): void {}
}';

    expect(sonarEmptyBodyOffenders($inside))->toBe([]);
    expect(sonarEmptyBodyOffenders($above))->toBe([]);
});

it('rejects a comment held off the declaration by a blank line', function (): void {
    $source = '<?php class A {
    // the peer already holds this

    public function f(): void {}
}';

    expect(sonarEmptyBodyOffenders($source))->toBe(['4 f()']);
});

it('reads only the last line of a comment block inside the braces', function (): void {
    $source = '<?php class A { public function f(): void {
        // the peer already holds this, so there is nothing here to write
        // --
    } }';

    expect(sonarEmptyBodyOffenders($source))->toBe(['1 f()']);
});

it('exempts constructor promotion, an abstract class and a closure', function (): void {
    $promoted = '<?php class A { public function __construct(private int $id) {} }';
    $bare = '<?php class A { public function __construct(int $id) {} }';
    $abstract = '<?php abstract class A { public function f(): void {} }';
    $closure = '<?php $f = function (): void {}; $g = fn (): int => 1;';

    expect(sonarEmptyBodyOffenders($promoted))->toBe([]);
    expect(sonarEmptyBodyOffenders($bare))->toBe(['1 __construct()']);
    expect(sonarEmptyBodyOffenders($abstract))->toBe([]);
    expect(sonarEmptyBodyOffenders($closure))->toBe([]);
});

it('skips a body that never existed and measures an attributed declaration', function (): void {
    $interface = '<?php interface A { public function f(): void; }';
    $attributed = '<?php class A {
    // the listener existing is what makes the component render again
    #[On("saved")]
    public function f(): void {}
}';
    $attributedGap = '<?php class A {
    // the listener existing is what makes the component render again

    #[On("saved")]
    public function f(): void {}
}';

    expect(sonarEmptyBodyOffenders($interface))->toBe([]);
    expect(sonarEmptyBodyOffenders($attributed))->toBe([]);
    expect(sonarEmptyBodyOffenders($attributedGap))->toBe(['4 f()']);
});
