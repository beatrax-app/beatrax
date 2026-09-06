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

/**
 * @return array{sites: int, offenders: list<string>} the scope drops read, and
 *                                                    the ones whose enclosing function never names the owner
 */
function userScopeUnownedDrops(string $file, string $source): array
{
    if (! str_contains($source, 'withoutGlobalScope')) {
        return ['sites' => 0, 'offenders' => []];
    }

    $bodies = userScopeFunctionBodies($source);
    $sites = 0;
    $offenders = [];

    foreach (explode("\n", $source) as $number => $line) {
        $lineNumber = $number + 1;
        $code = trim($line);

        // A comment naming the rule is prose about it, not a call site of it.
        if (! str_contains($line, 'withoutGlobalScope') || str_starts_with($code, '//') || str_starts_with($code, '*')) {
            continue;
        }

        $sites++;
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

    return ['sites' => $sites, 'offenders' => $offenders];
}

it('re-asserts the owner wherever it drops the user scope', function (): void {
    $files = userScopeShippedFiles();

    // 6,688 shipped files today, 24 of them dropping the scope. Both floored
    // far under, and both read before the verdict: a walk that lost a root and
    // a `withoutGlobalScope` renamed out from under this scan produce the same
    // empty offender list a tree that names its owner everywhere produces.
    expect(count($files))->toBeGreaterThan(2000, 'the shipped-file walk read almost nothing — the roots are wrong, not the tree.');

    $sites = 0;
    $offenders = [];

    foreach ($files as $file) {
        $verdict = userScopeUnownedDrops($file, BladePhpSource::forPath($file, (string) file_get_contents(base_path($file))));

        $sites += $verdict['sites'];
        $offenders = [...$offenders, ...$verdict['offenders']];
    }

    expect($sites)->toBeGreaterThan(8, 'no scope drop was found anywhere in the shipped tree, so this rule just judged nothing.');

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

it('sees a drop whose method never names the owner, and a closure judged by the method holding it', function (): void {
    $planted = <<<'PHP'
        <?php
        final class PlantedScopeDrops
        {
            public function unowned(int $id): ?Transaction
            {
                return Transaction::withoutGlobalScope(UserScope::class)->find($id);
            }

            public function owned(int $id, int $userId): ?Transaction
            {
                return Transaction::withoutGlobalScope(UserScope::class)
                    ->where('user_id', $userId)
                    ->find($id);
            }

            public function ownedThroughItsCaller(int $userId): void
            {
                $this->db->transaction(function () use ($userId): void {
                    Transaction::withoutGlobalScope(UserScope::class)->where('user_id', $userId)->delete();
                });
            }

            // withoutGlobalScope in a comment is prose about the rule.
            public function documented(): void {}
        }
        PHP;

    $verdict = userScopeUnownedDrops('planted.php', $planted);

    expect($verdict['sites'])->toBe(3, 'The reader must count the three real drops and not the one named in a comment.');

    expect($verdict['offenders'])->toHaveCount(
        1,
        'The reader must flag only the method that never names user_id: a where() in the same '
        .'method and a closure whose enclosing method names the owner are both answered.',
    );

    expect(str_contains($verdict['offenders'][0], 'planted.php:6'))->toBeTrue(
        'The reader flagged a drop, but not the one on the line that has no owner beside it: '.$verdict['offenders'][0],
    );
});
