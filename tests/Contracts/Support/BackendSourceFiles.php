<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// The set of files an architecture guard means when it says "the codebase":
// every backend PHP file, minus the tests that assert about them and the
// migrations, which describe schema rather than behaviour.
final class BackendSourceFiles
{
    /** @return list<string> */
    public static function all(): array
    {
        $files = [];

        foreach ([base_path('Modules'), base_path('app')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                $path = $file->getPathname();

                if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                    continue;
                }
                if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The source text of the call's argument list, or '' when the name at
     * $index is not a call.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    public static function callArguments(array $tokens, int $index): string
    {
        $depth = 0;
        $args = '';

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if ($depth === 0) {
                if ($text === '(') {
                    $depth = 1;

                    continue;
                }
                if (trim($text) !== '') {
                    return '';
                }

                continue;
            }

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            $args .= $text;
        }

        return $args;
    }

    /**
     * @return list<array{0:int,1:string,2:int}|string> the file's tokens with
     *                                                  comments removed, so a guard never reads its own prose as code
     */
    public static function codeTokens(string $path): array
    {
        return array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
        ));
    }
}
