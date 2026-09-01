<?php

declare(strict_types=1);

use Modules\Core\Public\Support\SafeExceptionContext;
use Tests\Contracts\Support\BackendSourceFiles;

// `SafeExceptionContext::describe()` returns `reason` and `sqlstate`, and a log
// context that spreads it LAST wins over anything the caller set under the same
// name. `OpLogEntryApplier` set 'reason' to the classified quarantine verdict
// and then spread describe() over it, so every refused CreateRow logged as
// QueryException / 23000 — and SQLite answers NOT NULL, FOREIGN KEY and UNIQUE
// alike with 23000, which is precisely the distinction the classification
// exists to draw. The line meant to say why a row was lost said nothing.

/** @return list<string> the keys describe() puts into a context array */
function describedContextKeys(): array
{
    return array_keys(SafeExceptionContext::describe(new RuntimeException('probe')));
}

/**
 * The array literal a spread sits in, read backwards from the spread to the
 * `[` that opened it. Good enough for a log context, which is always written
 * as one literal at the call.
 */
function contextLiteralAround(string $source, int $spreadOffset): string
{
    $open = strrpos(substr($source, 0, $spreadOffset), '[');

    return $open === false ? '' : substr($source, $open, $spreadOffset - $open);
}

it('never sets a key it then spreads over', function (): void {
    $keys = describedContextKeys();
    expect($keys)->not->toBeEmpty();

    $clobbered = [];

    foreach (BackendSourceFiles::all() as $path) {
        $source = (string) file_get_contents($path);
        $offset = 0;

        while (($at = strpos($source, '...SafeExceptionContext::describe(', $offset)) !== false) {
            $literal = contextLiteralAround($source, $at);

            foreach ($keys as $key) {
                if (str_contains($literal, "'{$key}' =>")) {
                    $line = substr_count(substr($source, 0, $at), "\n") + 1;
                    $clobbered[] = str_replace(base_path().'/', '', $path).":{$line} sets '{$key}'";
                }
            }

            $offset = $at + 1;
        }
    }

    expect($clobbered)->toBe([], implode(', ', $clobbered));
});
