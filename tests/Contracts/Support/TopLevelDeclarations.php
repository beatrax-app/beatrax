<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\BladePhpSource;
use PhpToken;
use RuntimeException;

// What a file declares at its own top level, read off the token stream. Around
// thirty arch tests in this tree plant a violation by writing a class into a
// heredoc and scanning the string, and to a pattern that body is identical to
// a real declaration. The lexer is the one reader that cannot be fooled by it:
// a heredoc arrives as a single string token and never as a `class` keyword.
//
// The same walk answers both questions this repository needs about a
// declaration -- where it sits, and whether Composer's psr-4 rules can reach
// it -- because they are the same tokens read once.
final class TopLevelDeclarations
{
    public const string TEST_FILE = 'Test.php';

    /** @var array<string, array{namespace: string, types: list<array{kind: string, name: string, line: int}>}> */
    private static array $read = [];

    /**
     * @return array{namespace: string, types: list<array{kind: string, name: string, line: int}>}
     */
    public static function in(string $source): array
    {
        $tokens = PhpToken::tokenize($source);
        $total = count($tokens);
        $depth = 0;
        $namespace = '';
        $types = [];

        for ($index = 0; $index < $total; $index++) {
            $token = $tokens[$index];

            if ($token->text === '{' || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }

            if ($token->text === '}') {
                $depth--;

                continue;
            }

            if ($depth !== 0) {
                continue;
            }

            if ($token->id === T_NAMESPACE) {
                $namespace = self::nameAfter($tokens, $index);

                continue;
            }

            $kind = match ($token->id) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                T_ENUM => 'enum',
                default => null,
            };

            if ($kind === null) {
                continue;
            }

            // `Foo::class` and `new class {…}` put the keyword in a position
            // that declares nothing nameable, and an anonymous class is the
            // shape a test double is allowed to take. An attributed
            // declaration is not one of them: `#[Attr]` closes on `]` before
            // the keyword, so `#[Attr] final class Foo` reaches the name test
            // below and is read like any other.
            $previous = self::significantBefore($tokens, $index);

            if ($previous !== null && in_array($previous->id, [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            // What settles `new #[Attr] class {…}`, whose `]` hides the `new`:
            // a declaration nothing can name is not a declaration.
            $next = self::significantAfter($tokens, $index);

            if ($next === null || $next->id !== T_STRING) {
                continue;
            }

            $types[] = ['kind' => $kind, 'name' => $next->text, 'line' => $token->line];
        }

        return ['namespace' => $namespace, 'types' => $types];
    }

    /**
     * @return array{namespace: string, types: list<array{kind: string, name: string, line: int}>}
     */
    public static function inFile(string $relative): array
    {
        // Through BladePhpSource because `.blade.php` ends in `.php`, so the
        // every-PHP-file walk below holds every template in the tree. Handed
        // straight to the tokeniser a template is one T_INLINE_HTML, so it
        // declares nothing and reads clean without having been read.
        $path = RepoTree::root().'/'.$relative;

        return self::$read[$relative] ??= self::in(
            BladePhpSource::forPath($path, (string) file_get_contents($path)),
        );
    }

    /**
     * @return list<string> repo-relative paths, every file the suite runner collects as a test
     */
    public static function testFiles(): array
    {
        return array_values(array_filter(
            RepoTree::relativeFiles(RepoTree::EVERY_PHP_FILE),
            static fn (string $path): bool => str_ends_with($path, self::TEST_FILE),
        ));
    }

    /**
     * The psr-4 rules Composer builds its classmap from, longest prefix first,
     * read from composer.json rather than named here: a rule this list has and
     * Composer does not is a guard passing over code the autoloader skips.
     *
     * @return list<array{prefix: string, directory: string}>
     */
    public static function autoloadRoots(): array
    {
        $manifest = json_decode((string) file_get_contents(RepoTree::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($manifest)) {
            throw new RuntimeException('composer.json did not decode to an array, so no psr-4 rule could be read from it.');
        }

        $roots = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $rules = $manifest[$section]['psr-4'] ?? [];

            if (! is_array($rules)) {
                continue;
            }

            foreach ($rules as $prefix => $directory) {
                $roots[] = [
                    'prefix' => rtrim((string) $prefix, '\\').'\\',
                    'directory' => rtrim(is_array($directory) ? (string) $directory[0] : (string) $directory, '/'),
                ];
            }
        }

        usort($roots, static fn (array $a, array $b): int => strlen($b['prefix']) <=> strlen($a['prefix']));

        if ($roots === []) {
            throw new RuntimeException('composer.json declares no psr-4 rule at all, so every declaration below would read as autoloadable.');
        }

        return $roots;
    }

    /**
     * Every top-level type Composer's classmap skips: it sits under a psr-4
     * directory, so Composer reads the file, and no rule maps its name back to
     * that path. Files under no rule at all are somebody else's to autoload --
     * the second Composer root's plugin package and the analyser stubs -- and
     * Composer says nothing about them either.
     *
     * Keyed by site rather than by qualified name so a pin can name one
     * without writing a neighbour's private namespace into a source literal,
     * which the boundary reader pins separately and rightly.
     *
     * @return array<string, string> "<path> declares <name>" => the qualified name it resolved to
     */
    public static function skippedByComposer(): array
    {
        $roots = self::autoloadRoots();
        $skipped = [];

        foreach (RepoTree::relativeFiles(RepoTree::EVERY_PHP_FILE) as $relative) {
            $under = array_values(array_filter(
                $roots,
                static fn (array $root): bool => str_starts_with($relative, $root['directory'].'/'),
            ));

            if ($under === []) {
                continue;
            }

            $read = self::inFile($relative);

            foreach ($read['types'] as $type) {
                $qualified = self::qualify($read['namespace'], $type['name']);

                if (! self::resolves($qualified, $relative, $roots)) {
                    $skipped[self::site($relative, $type['name'])] = $qualified;
                }
            }
        }

        ksort($skipped);

        return $skipped;
    }

    public static function site(string $relative, string $name): string
    {
        return $relative.' declares '.$name;
    }

    public static function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * @param  list<array{prefix: string, directory: string}>  $roots
     */
    private static function resolves(string $fqn, string $relative, array $roots): bool
    {
        foreach ($roots as $root) {
            if (! str_starts_with($fqn, $root['prefix'])) {
                continue;
            }

            $tail = str_replace('\\', '/', substr($fqn, strlen($root['prefix'])));

            if ($relative === $root['directory'].'/'.$tail.'.php') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function nameAfter(array $tokens, int $index): string
    {
        $name = '';

        for ($cursor = $index + 1; $cursor < count($tokens); $cursor++) {
            if ($tokens[$cursor]->isIgnorable()) {
                continue;
            }

            if ($tokens[$cursor]->text === ';' || $tokens[$cursor]->text === '{') {
                break;
            }

            $name .= $tokens[$cursor]->text;
        }

        return trim($name);
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function significantBefore(array $tokens, int $index): ?PhpToken
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            if (! $tokens[$cursor]->isIgnorable()) {
                return $tokens[$cursor];
            }
        }

        return null;
    }

    /**
     * @param  list<PhpToken>  $tokens
     */
    private static function significantAfter(array $tokens, int $index): ?PhpToken
    {
        for ($cursor = $index + 1, $total = count($tokens); $cursor < $total; $cursor++) {
            if (! $tokens[$cursor]->isIgnorable()) {
                return $tokens[$cursor];
            }
        }

        return null;
    }
}
