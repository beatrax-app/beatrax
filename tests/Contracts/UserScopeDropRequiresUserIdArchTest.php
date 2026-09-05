<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-dropped-user-scope-with-no-owner-named
 */

/** @return list<string> repo-relative PHP files that ship */
function userScopeShippedFiles(): array
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
 * Every function body in the file, innermost last, so a call inside a closure
 * is judged against the closure and then against the method holding it.
 *
 * @return list<array{body: string, from: int, to: int}>
 */
function userScopeFunctionBodies(string $source): array
{
    $tokens = token_get_all($source);
    $bodies = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $depth = 0;
        $body = '';
        $line = $token[2];
        $from = $line;

        for ($cursor = $index; $cursor < count($tokens); $cursor++) {
            $current = $tokens[$cursor];
            $text = is_array($current) ? $current[1] : $current;

            if ($depth > 0) {
                $body .= $text;
            }

            if ($text === '{') {
                $depth++;
                if ($depth === 1) {
                    $from = $line;
                }
            } elseif ($text === '}') {
                $depth--;
                if ($depth === 0) {
                    $bodies[] = ['body' => $body, 'from' => $from, 'to' => $line];
                    break;
                }
            } elseif ($text === ';' && $depth === 0) {
                // An abstract or interface method has no body to judge.
                break;
            }

            // Brace and semicolon tokens carry no line of their own, so the
            // walk keeps its own count rather than reading one off them.
            $line += substr_count($text, "\n");
        }
    }

    return $bodies;
}

it('re-asserts the owner wherever it drops the user scope', function (): void {
    $offenders = [];

    foreach (userScopeShippedFiles() as $file) {
        $source = BladePhpSource::forPath($file, (string) file_get_contents(base_path($file)));

        if (! str_contains($source, 'withoutGlobalScope')) {
            continue;
        }

        $bodies = userScopeFunctionBodies($source);

        foreach (explode("\n", $source) as $number => $line) {
            $lineNumber = $number + 1;
            $code = trim($line);

            if (! str_contains($line, 'withoutGlobalScope') || str_starts_with($code, '//') || str_starts_with($code, '*')) {
                continue;
            }

            $guarded = false;

            foreach ($bodies as $scope) {
                if ($lineNumber < $scope['from'] || $lineNumber > $scope['to']) {
                    continue;
                }

                if (str_contains($scope['body'], 'user_id')) {
                    $guarded = true;
                    break;
                }
            }

            if (! $guarded) {
                $offenders[] = $file.':'.$lineNumber.' — '.$code;
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "Dropping UserScope makes every user's rows reachable, so the query has\n".
        "to say whose it wants. Name user_id in the same method — as a where(),\n".
        "as a key in the firstOrNew/updateOrCreate attributes, or in an ownership\n".
        "check the read cannot run without. A model looked up by an id the\n".
        "browser supplied and no owner named is an IDOR. Offenders:\n  ".
        implode("\n  ", $offenders),
    );
});
