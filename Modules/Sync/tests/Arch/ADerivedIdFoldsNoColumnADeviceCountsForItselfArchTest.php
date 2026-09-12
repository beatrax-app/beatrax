<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// A derived id has one precondition: every value folded into it is one EVERY
// device computes alike. An autoincrement is not — two devices used apart both
// take the next number and hand it to unrelated rows — so an id folded from a
// foreign key naming one is a number that means a different row on the peer.

// Five call sites got that wrong at once, each carrying a comment saying the
// columns were immutable, which is a different property. Two inherited the
// fault through a parent, so the rule is blunt on purpose: every foreign key is
// refused, including one whose parent derives its own id today.

// The one parent whose ids DO agree: a pairing is per-user, every op carries
// user_id, and the backfill scopes every table on it. Two devices that
// disagreed about which user this is would exchange nothing at all.
/** @return list<string> */
function parentsTwoDevicesAgreeAbout(): array
{
    return ['users'];
}

// Walked rather than found through Finder, and named apart from every other
// walker: composer-require-checker reads Modules/*/tests as production code,
// and two globals of one name in one process is a fatal.
/** @return list<string> */
function derivedIdentitySourceFiles(): array
{
    $paths = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // Migrations are excluded because one already ran: 2026_08_20 back-derived
        // the anomaly ids this fix replaces, and the numbers it wrote are opaque
        // and still in place. A historical pass is not a live call site.
        if (preg_match('#/tests/|/Database/Migrations/#', $path) === 1) {
            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

// Table, the columns its identity tuple names, and where it is written. Only
// the literal part of the array is read: a tuple assembled from a variable is
// invisible here, which is why SystemAlertWriter's caller-supplied tail is
// covered by its own signature instead.
/** @return list<array{file: string, table: string, columns: list<string>}> */
function derivedIdentityTuples(): array
{
    $tuples = [];

    foreach (derivedIdentitySourceFiles() as $path) {
        $source = (string) file_get_contents($path);

        // The labels are optional because the call is legal either way, and
        // this repo writes `table:` by hand all over the capture listeners. A
        // named-argument call used to read as no call site at all: the same
        // foreign-key fold went from reported to invisible purely by switching
        // syntax, while the other eight sites kept the set non-empty.
        $found = PatternScan::all("/DerivedRowId::for\(\s*(?:table:\s*)?'([a-z_]+)'\s*,\s*(?:identity:\s*)?\[(.*?)\]/s", $source);

        foreach ($found[1] as $index => $table) {
            $keys = PatternScan::all("/'([a-z_]+)'\s*=>/", (string) ($found[2][$index] ?? ''));

            $tuples[] = [
                'file' => str_replace(base_path().'/', '', $path),
                'table' => $table,
                'columns' => array_values($keys[1]),
            ];
        }
    }

    return $tuples;
}

// Counted off the call itself rather than off the shape the reader parses, so
// the two can disagree and the rule above can say so.
//
// Tokenised, because a mention is not a call: SensitiveFieldRegistry names
// `DerivedRowId::for()` inside a sentence explaining why a column may not be
// sealed, and counting that as a tenth call site made this floor demand a
// tuple nobody writes.
function derivedIdentityCallSiteCount(): int
{
    $calls = 0;

    foreach (derivedIdentitySourceFiles() as $path) {
        $tokens = token_get_all((string) file_get_contents($path));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'DerivedRowId') {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $name = $tokens[$i + 2] ?? null;

            if (is_array($next) && $next[0] === T_DOUBLE_COLON && is_array($name) && $name[1] === 'for') {
                $calls++;
            }
        }
    }

    return $calls;
}

/** @return array<string, string> foreign key column => the table it points at */
function foreignKeyColumnsOf(string $table): array
{
    $columns = [];

    foreach (DB::select('select "table", "from" from pragma_foreign_key_list(?)', [$table]) as $key) {
        $column = is_string($key->from ?? null) ? $key->from : '';
        $parent = is_string($key->table ?? null) ? $key->table : '';

        if ($column !== '' && $parent !== '') {
            $columns[$column] = $parent;
        }
    }

    return $columns;
}

/** @return list<string> */
function identityColumnsTwoDevicesCountApart(): array
{
    $offenders = [];

    foreach (derivedIdentityTuples() as $tuple) {
        $foreignKeys = foreignKeyColumnsOf($tuple['table']);

        foreach ($tuple['columns'] as $column) {
            $parent = $foreignKeys[$column] ?? null;

            if ($parent === null || in_array($parent, parentsTwoDevicesAgreeAbout(), true)) {
                continue;
            }

            $offenders[] = $tuple['file'].': '.$tuple['table'].'.'.$column.' -> '.$parent;
        }
    }

    sort($offenders);

    return $offenders;
}

// An aggregate over an empty set passes whatever the predicate says, and this
// one has two ways to see nothing: a scan that matches no call site, and a
// schema probe that answers no foreign key. Both are asserted to see something.
it('reads a non-empty set of derived identity tuples off a schema that answers', function (): void {
    // Counted rather than merely non-empty: eight of the nine call sites
    // staying readable hides the ninth having become invisible, which is
    // exactly what a re-spelled call does.
    expect(derivedIdentityTuples())->toHaveCount(
        derivedIdentityCallSiteCount(),
        'The reader found '.count(derivedIdentityTuples()).' identity tuples for '.derivedIdentityCallSiteCount()
        .' DerivedRowId::for() call sites in the tree. A call site the pattern cannot read is one this rule never judges.'
    );

    expect(array_key_exists('transaction_id', foreignKeyColumnsOf('anomaly_alerts')))->toBeTrue(
        'pragma_foreign_key_list answered nothing for the table the defect was measured on.'
    );
});

it('folds no column a device counts for itself into a derived id', function (): void {
    expect(identityColumnsTwoDevicesCountApart())->toBe([], implode("\n", [
        'These derived ids fold a foreign key to a table whose rows get their ids per device.',
        'The peer counts its own, so the same number names a different row there and the id',
        'derives nothing — it collides with an unrelated row or misses the right one. Mint the',
        'id and give the table a unique index over columns both devices compute, or fold a',
        'value that is the same everywhere.',
    ]));
});
