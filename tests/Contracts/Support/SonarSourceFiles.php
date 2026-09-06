<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * @link ../../../.docs/conventions/analyser-rules-enforced-locally.md#the-scope-every-guard-reads
 */
final class SonarSourceFiles
{
    /**
     * `sonar.sources` in sonar-project.properties, and nothing else. A guard
     * reading a wider tree than the hosted analysis fails on files the
     * dashboard will never mention.
     *
     * @var list<string>
     */
    private const ROOTS = ['app', 'Modules', 'config', 'routes', 'database'];

    /**
     * The half of `sonar.exclusions` a `.php` walk over ROOTS can reach, plus
     * the test roots. The mobile-app and public/build entries are left out
     * because neither is a root here; `/vendor/`, `/node_modules/` and
     * `/database/schema/` match no PHP under these five roots today and are
     * carried anyway, because this list is a transcription of another file and
     * a transcription with holes in it is one nobody can check against the
     * original.
     *
     * The test roots are this repository's own addition. The code-smell rules
     * these guards stand in for have never raised a single finding in a test
     * file across this project's whole issue history, so the fakes and spies
     * living there would be failures the dashboard is never going to agree
     * with. The shared root's own `database/migrations/` is deliberately NOT
     * skipped: Sonar's glob is case-sensitive, so the exclusion written for
     * the modules' capitalised spelling never matched it, and the dashboard
     * reports them.
     *
     * @var list<string>
     */
    private const EXCLUDED_FRAGMENTS = [
        '/vendor/',
        '/node_modules/',
        '/tests/',
        '/Database/Migrations/',
        '/database/schema/',
        '/Resources/lang/',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        $files = [];

        foreach (self::ROOTS as $root) {
            $path = base_path($root);

            if (is_dir($path)) {
                $files = array_merge($files, self::walk($path));
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function walk(string $directory): array
    {
        $files = [];

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            // mobile-app/ reaches this same tree through symlinks, and
            // following one reports every shared file a second time under a
            // second spelling.
            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                $files = array_merge($files, self::walk($path));

                continue;
            }

            if (str_ends_with($path, '.php') && ! self::excluded($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private static function excluded(string $path): bool
    {
        foreach (self::EXCLUDED_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The tokens of a file with whitespace and comments dropped, each one a
     * triple of `[token id or null, text, line]`. A single-character token
     * carries the line of the token before it, which is the line it sits on.
     *
     * Trivia is dropped here rather than skipped at each call site because
     * every reader below decides on what sits directly beside a token — the
     * name after `function`, the `::` before `class`, the `:` before a `?`.
     * One docblock left in the stream separates those pairs and the reader
     * quietly stops recognising the construct.
     *
     * @return list<array{0:int|null,1:string,2:int}>
     */
    public static function tokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $line = $token[2];
                $tokens[] = [$token[0], $token[1], $token[2]];

                continue;
            }

            $tokens[] = [null, $token, $line];
        }

        return $tokens;
    }

    /**
     * Every bracket paired with its partner, in both directions.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return array<int,int>
     */
    public static function brackets(array $tokens): array
    {
        $pairs = [];
        $open = [];

        foreach ($tokens as $index => $token) {
            if ($token[0] === null && in_array($token[1], ['(', '[', '{'], true)) {
                $open[] = $index;
            } elseif (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                $open[] = $index;
            } elseif ($token[0] === null && in_array($token[1], [')', ']', '}'], true)) {
                $start = array_pop($open);

                if ($start !== null) {
                    $pairs[$start] = $index;
                    $pairs[$index] = $start;
                }
            }
        }

        return $pairs;
    }
}
