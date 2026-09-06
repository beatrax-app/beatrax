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

// The backend roots hold 6,688 files spreading describe() at 67 sites, and both
// floors sit far under those: a walk that opened nothing finds no spread, and a
// reader that stopped recognising one reports every caller clean.
const SPREAD_CONTEXT_FILE_FLOOR = 1_000;

const SPREAD_CONTEXT_SITE_FLOOR = 20;

// Named after the spread it reads: an aliased import, or a describe() held in a
// variable first, is a shape this scan cannot see and the wording does not claim.
it('never sets a key it then spreads SafeExceptionContext::describe() over', function (): void {
    $keys = describedContextKeys();

    expect($keys)->not->toBeEmpty(
        'describe() puts no key into a context array at all, so the scan below has nothing to look for and '
        .'reports every call site clean.'
    );

    $clobbered = [];
    $sites = 0;
    $files = BackendSourceFiles::all();

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $offset = 0;

        while (($at = strpos($source, '...SafeExceptionContext::describe(', $offset)) !== false) {
            $sites++;
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

    expect(count($files))->toBeGreaterThan(
        SPREAD_CONTEXT_FILE_FLOOR,
        'The walk opened '.count($files).' backend files, so a clean answer here is a walk that read almost nothing.'
    );

    expect($sites)->toBeGreaterThan(
        SPREAD_CONTEXT_SITE_FLOOR,
        'The reader found '.$sites.' spreads of describe() in '.count($files)
        .' files, which is what a scan that stopped matching looks like: no spread found is no spread to judge.'
    );

    expect($clobbered)->toBe([], implode("\n  ", [
        'These set a context key by hand and then spread describe() over it, so the value the caller chose is '
            .'replaced by a generic one and the line meant to say why says nothing. PHP takes the LAST spelling '
            .'of a key, so move the spread above the keys it must not win over, or rename the key. Offenders:',
        ...$clobbered,
    ]));
});

// A guard that cannot go red says nothing, and the verdict above is read off one
// reader of one array literal. It is checked against the shapes it was written
// for rather than against the tree.
it('reads the literal a spread sits in and not the one before it', function (string $body, bool $clobbers): void {
    $at = strpos($body, '...SafeExceptionContext::describe(');

    expect($at)->not->toBeFalse('the fixture has to hold the spread this reader is written about');

    expect(str_contains(contextLiteralAround($body, (int) $at), "'reason' =>"))->toBe(
        $clobbers,
        'The reader answered '.var_export(! $clobbers, true).' for a literal it has to read as '
        .($clobbers ? 'setting the key it then spreads over' : 'leaving it alone').': '.$body
    );
})->with([
    'a key the spread wins over' => ["Log::warning('x', ['reason' => \$verdict, ...SafeExceptionContext::describe(\$e)]);", true],
    'a spread with nothing under it' => ["Log::warning('x', [...SafeExceptionContext::describe(\$e)]);", false],
    'a key set in an earlier literal' => ["\$a = ['reason' => 1]; Log::warning('x', [...SafeExceptionContext::describe(\$e)]);", false],
    'a different key beside the spread' => ["Log::warning('x', ['table' => \$t, ...SafeExceptionContext::describe(\$e)]);", false],
]);
