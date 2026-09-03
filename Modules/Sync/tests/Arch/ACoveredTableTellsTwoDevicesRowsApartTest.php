<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// A covered table whose only identity is an autoincrement cannot tell one
// device's row from another's. Two devices used while apart both take the next
// id, and the arriving create is refused by the primary key — for a long time
// in silence, and now as a quarantined collision. Either way the row is lost.

// Three answers make a table safe, and a table needs one of them:
//   a natural unique index, so PeerRowAliases can find the local twin;
//   a derived id, so both devices compute the same one;
//   a minted id, so no second device lands on it.

// Never on the wire in either direction, so no second device ever writes one.
/** @return list<string> */
function neverTravels(): array
{
    return ['categorization_rules', 'rule_conditions', 'rule_actions'];
}

// Empty, and it stays empty. transaction_splits was the last entry: its legs
// now carry a split_uuid minted once and never rewritten, so the derived id
// survives the reorder that sort_order does not.
/** @return list<string> */
function stillExposed(): array
{
    return [];
}

// Walked rather than found through Finder: composer-require-checker reads
// Modules/*/tests as production code, and that dependency is dev-only. Named
// apart from the walker in AModalNothingOpensIsAControl...: both load into one
// process, and a second global of the same name is a fatal.
/** @return list<string> */
function rowIdentitySourceFiles(bool $includeTests = false, ?string $only = null): array
{
    $paths = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if (! $includeTests && preg_match('#/tests/#', $path) === 1) {
            continue;
        }

        if ($only !== null && preg_match($only, $path) !== 1) {
            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

/** @return list<string> tables named in a DerivedRowId or DeviceMintedRowId call */
function tablesWithAnIdScheme(): array
{
    $named = [];
    $minting = [];

    foreach (rowIdentitySourceFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match_all("/DerivedRowId::for\(\s*'([a-z_]+)'/", $source, $found) !== false) {
            $named = [...$named, ...$found[1]];
        }

        if (str_contains($source, 'DeviceMintedRowId::mint(')) {
            $minting[] = $path;
        }
    }

    // A minted id carries no table name at the call site, so the table is read
    // off the write it feeds — and only from the window around the call. A
    // whole file is too loose: PotWriter names the Goal model for a foreign
    // key, which would have counted goals as answered by the pots writer.
    $byModel = modelTablesByClassName();

    foreach ($minting as $path) {
        foreach (mintingWindows((string) file_get_contents($path)) as $window) {
            if (preg_match_all("/table\(\s*'([a-z_]+)'\s*\)|table:\s*'([a-z_]+)'/", $window, $found) !== false) {
                $named = [...$named, ...array_filter([...$found[1], ...$found[2]])];
            }

            foreach ($byModel as $class => $table) {
                if (preg_match('/\b'.preg_quote($class, '/').'\b/', $window) === 1) {
                    $named[] = $table;
                }
            }
        }
    }

    return array_values(array_unique($named));
}

// The statement the mint feeds, give or take: enough to reach the model or the
// table literal it is written beside, and not enough to reach the next write.
/** @return list<string> */
function mintingWindows(string $source): array
{
    $windows = [];
    $offset = 0;

    while (($at = strpos($source, 'DeviceMintedRowId::mint(', $offset)) !== false) {
        $windows[] = substr($source, max(0, $at - 400), 500);
        $offset = $at + 1;
    }

    return $windows;
}

/** @return array<string, string> model short name => the table it declares */
function modelTablesByClassName(): array
{
    $tables = [];

    foreach (rowIdentitySourceFiles(includeTests: false, only: '#/Models/#') as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
            continue;
        }

        $class = $ns[1].'\\'.basename($path, '.php');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        /** @var Model $model */
        $model = new $class;
        $tables[basename($path, '.php')] = $model->getTable();
    }

    return $tables;
}

/** @return list<string> covered tables whose id is an autoincrement and nothing else */
function tablesWithNoIdentity(): array
{
    $scheme = tablesWithAnIdScheme();
    $exempt = [...neverTravels(), ...stillExposed()];
    $offenders = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        if (in_array($table, $exempt, true) || in_array($table, $scheme, true)) {
            continue;
        }

        $create = DB::selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);
        $sql = is_object($create) && is_string($create->sql ?? null) ? $create->sql : '';

        if (! str_contains(strtolower($sql), 'autoincrement')) {
            continue;
        }

        $unique = false;
        foreach (DB::select('select name, "unique", origin from pragma_index_list(?)', [$table]) as $index) {
            $columns = array_map(
                static fn (object $c): string => is_string($c->name ?? null) ? $c->name : '',
                DB::select('select name from pragma_index_info(?)', [$index->name]),
            );

            if ((int) $index->unique === 1 && $index->origin !== 'pk' && $columns !== ['id']) {
                $unique = true;
            }
        }

        if (! $unique) {
            $offenders[] = $table;
        }
    }

    sort($offenders);

    return $offenders;
}

it('gives every covered table a way to tell two devices rows apart', function (): void {
    expect(tablesWithNoIdentity())->toBe([], implode("\n", [
        'These covered tables have an autoincrement primary key, no other unique index,',
        'and no derived or minted id. Two devices used while apart will hand one id to',
        'two different rows, and the arriving create is refused. Give the table one of',
        'the three answers above, or name it in stillExposed() with the reason.',
    ]));
});

// The list only shrinks, and it has reached nothing. A table added to it needs
// the argument written above it, and taking one off needs an id scheme rather
// than a smaller list.
it('leaves no covered table exposed', function (): void {
    expect(stillExposed())->toBe([]);
});
