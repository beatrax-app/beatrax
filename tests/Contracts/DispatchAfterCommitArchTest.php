<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;

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
            token_get_all(BladePhpSource::forPath($path, (string) file_get_contents($path))),
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

// The rule guards side effects a rollback cannot reach. Each entry names a file
// whose dispatch is not one of those, and why. The `proves` pattern re-checks
// the reason on every run: when the file stops matching, the exemption has
// outlived what earned it and this fails rather than waving it on.
const DISPATCH_AFTER_COMMIT_PINNED = [
    'Modules/Core/Public/Services/UserCountry.php' => [
        'reason' => 'the country-changed dispatch has one listener, it seeds one table through the same connection, and both are inside the transaction — so a rollback undoes the seed as cleanly as the write, which is the whole reason the two were put together',
        'proves' => '/\$this->db->connection\(\)->transaction\(/',
    ],
];

it('never dispatches from inside the transaction that caused it', function (): void {
    $files = dispatchAfterCommitFiles();

    // The floor sits far under the 6,400 backend files this walk opens. A run
    // that reached none of them reports a clean tree over code nobody read.
    expect(count($files))->toBeGreaterThan(
        1000,
        'The backend walk opened almost nothing, so no transaction body was read at all.'
    );

    $found = dispatchesInsideATransaction($files);

    $offenders = array_values(array_filter(
        $found,
        static function (string $offender): bool {
            foreach (array_keys(DISPATCH_AFTER_COMMIT_PINNED) as $pinned) {
                if (str_contains($offender, $pinned)) {
                    return false;
                }
            }

            return true;
        },
    ));

    expect($offenders)->toBe(
        [],
        implode("\n  ", [
            'These dispatch from inside the transaction that caused them:',
            ...$offenders,
            '',
            'Listeners run synchronously here, so a rollback after the dispatch leaves the',
            'search index, the transfer pairing and the sync op log describing rows that no',
            'longer exist — and the op log is replayed onto the paired device, which then',
            'holds them forever. Collect what happened during the transaction and dispatch',
            'it after the commit returns; if the side effect is undone by the same rollback,',
            'pin the file above with a reason and a `proves` pattern that re-checks it.',
        ]),
    );
});

it('still holds each pinned exemption to the reason it was granted for', function (): void {
    foreach (DISPATCH_AFTER_COMMIT_PINNED as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});

// A pin the walk no longer reaches is a claim about the tree that stopped being
// true, and it would otherwise sit here forever.
it('keeps no pin the walk no longer reaches', function (): void {
    $found = dispatchesInsideATransaction(dispatchAfterCommitFiles());

    $reached = array_values(array_filter(
        array_keys(DISPATCH_AFTER_COMMIT_PINNED),
        static function (string $pinned) use ($found): bool {
            foreach ($found as $offender) {
                if (str_contains($offender, $pinned)) {
                    return true;
                }
            }

            return false;
        },
    ));

    expect($reached)->toBe(array_keys(DISPATCH_AFTER_COMMIT_PINNED), implode("\n  ", [
        'A pinned file that no longer dispatches from inside a transaction has outlived',
        'its exemption. Delete the pin rather than leave a claim standing that the next',
        'reader will trust.',
    ]));
});

it('sees a dispatch that a transaction closure hides', function (): void {
    // The guard above is worth exactly as much as its ability to go red, and a
    // brace-matching scanner that silently matched nothing would not.
    $seed = (string) tempnam(sys_get_temp_dir(), 'after-commit');
    $planted = $seed.'.php';
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
        @unlink($seed);
    }

    expect($found)->toHaveCount(1, 'the dispatch after the closing brace is not a violation');
    expect($found[0])->toEndWith(':8');
});
