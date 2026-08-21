<?php

declare(strict_types=1);

/**
 * A background dispatch inside the transaction that caused it is a promise the
 * database has not made yet. Listeners run synchronously here, so a rollback
 * after the dispatch leaves the search index, the transfer pairing and the sync
 * op log describing rows that no longer exist — and the op log is replayed onto
 * the paired device, which then holds them forever.
 *
 * @return list<string> absolute paths to in-scope backend PHP files
 */
function dispatchAfterCommitFiles(): array
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
 * @param  list<string>  $paths
 * @return list<string> one entry per dispatch found inside a transaction body
 */
function dispatchesInsideATransaction(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($path)),
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'transaction') {
                continue;
            }
            // `$connection->transaction(...)` / `DB::transaction(...)`, never a
            // local variable or a method merely named in a type.
            $before = $tokens[$index - 1] ?? null;
            if (! is_array($before) || ! in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            [$open, $close] = dispatchAfterCommitCallSpan($tokens, $index);
            if ($open === null || $close === null) {
                continue;
            }

            for ($i = $open; $i < $close; $i++) {
                $inner = $tokens[$i];
                if (! is_array($inner) || $inner[0] !== T_STRING || strtolower($inner[1]) !== 'dispatch') {
                    continue;
                }
                if (($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }

                $hits[] = "{$path}:{$inner[2]}";
            }
        }
    }

    return $hits;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array{0:int|null,1:int|null} indices just inside the call's parentheses
 */
function dispatchAfterCommitCallSpan(array $tokens, int $index): array
{
    $depth = 0;
    $open = null;

    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

        if ($text === '(') {
            $depth++;
            if ($depth === 1) {
                $open = $i + 1;
            }
        } elseif ($text === ')') {
            $depth--;
            if ($depth === 0) {
                return [$open, $i];
            }
        } elseif ($depth === 0) {
            return [null, null];
        }
    }

    return [null, null];
}

it('never dispatches from inside the transaction that caused it', function (): void {
    $files = dispatchAfterCommitFiles();
    expect($files)->not->toBeEmpty();

    expect(dispatchesInsideATransaction($files))->toBe(
        [],
        "Collect what happened during the transaction and dispatch it after the\n".
        'commit returns. Offenders:',
    );
});

it('sees a dispatch that a transaction closure hides', function (): void {
    // The guard above is worth exactly as much as its ability to go red, and a
    // brace-matching scanner that silently matched nothing would not.
    $planted = tempnam(sys_get_temp_dir(), 'after-commit').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedTransactionDispatch
        {
            public function run(): void
            {
                $this->db->connection()->transaction(function (): void {
                    $this->rows->insert(['a' => 1]);
                    $this->events->dispatch(new RowWritten(1));
                });

                $this->events->dispatch(new BatchWritten);
            }
        }
        PHP);

    try {
        $found = dispatchesInsideATransaction([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1, 'the dispatch after the closing brace is not a violation');
    expect($found[0])->toEndWith(':8');
});
