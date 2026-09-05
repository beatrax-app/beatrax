<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Illuminate\Support\Str;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use ReflectionClass;

// The scan behind the column-level capture guard, kept out of the Pest file it
// serves: a helper declared in a namespace-less test file exists only in the
// worker that loaded it, so a second file asking the same question silently
// skips. CaptureSites is the same answer for the table-level question.
final class SyncedColumnWrites
{
    // Its own guard already holds every users column, and its row mixes the
    // reader's settings with this device's password — a rule that treated it
    // like any other table would report every password write.
    private const string SELF_SCOPED = 'users';

    /**
     * The columns the merge registry declares mergeable, per table that travels.
     *
     * @return array<string, list<string>>
     */
    public static function mergeableColumns(MergeRulesRegistry $registry): array
    {
        /** @var list<string> $deviceLocal */
        $deviceLocal = (new ReflectionClass(OpLogBackfiller::class))->getConstant('DEVICE_LOCAL_TABLES');

        $columns = [];

        foreach (array_keys($registry->rules()) as $table) {
            $table = (string) $table;

            if ($table === self::SELF_SCOPED || in_array($table, $deviceLocal, true)) {
                continue;
            }

            $synced = $registry->syncedColumns($table);

            if ($synced !== []) {
                $columns[$table] = $synced;
            }
        }

        ksort($columns);

        return $columns;
    }

    // The scope, and the reason each root it declines is somebody else's to
    // read, live in RepoTree — the one place a walk over this tree is declared
    // and the only one held to `git ls-files`. A hand-written list here was how
    // the delete guard beside this one came to cover Modules/ alone, with a
    // console command raw-deleting two travelling tables out of app/.
    /**
     * @return list<string>
     */
    public static function writerFiles(): array
    {
        return RepoTree::files(RepoTree::RUNTIME_DOMAIN_PHP);
    }

    // The codebase's own prose names both halves of the rule it describes, so
    // an unstripped file reports the comment that explains the exemption.
    public static function stripped(string $source): string
    {
        return PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
    }

    // The substring both patterns below require, asked once per file instead of
    // once per column: the walk is a hundred columns wide over six thousand
    // files, and a regex answering "no" that many times is the whole cost.
    public static function mayName(string $table, ?string $model, string $source): bool
    {
        return str_contains($source, "'".$table."'")
            || ($model !== null && str_contains($source, $model));
    }

    // Rooted at the table, never at the column alone: a bare `'name' =>` in an
    // update payload matched merchants, categories and accounts alike, and the
    // question is which table the statement is aimed at.
    public static function updatesColumn(string $table, string $column, string $source, ?string $model): bool
    {
        $tail = '(?:\s*->\s*[a-zA-Z]+\([^;]*?\))*?\s*->\s*update\([^;]*?\''.$column.'\'\s*=>';

        if (PatternScan::matches('/table\(\s*\''.$table.'\'\s*\)'.$tail.'/s', $source)) {
            return true;
        }

        if ($model === null) {
            return false;
        }

        if (PatternScan::matches('/\b'.$model.'::(?:query\(\)|withoutGlobalScopes\([^;]*?\))?'.$tail.'/s', $source)) {
            return true;
        }

        // The third shape, and the only one with no statement to root: an
        // assignment and a save() are two statements on a variable. The model
        // class being named in the file is what stands in for the table, which
        // is the same stand-in the users guard has always used.
        return PatternScan::matches('/\b'.$model.'\b/', $source)
            && PatternScan::matches('/->'.$column.'\s*=[^=]/', $source)
            && PatternScan::matches('/->\s*save(?:Quietly)?\(\)/', $source);
    }

    // Whole-file, exactly as the delete and users guards ask it. A file writing
    // one column of a table and announcing another is not the failure this
    // catches; a file that tells no peer anything at all is.
    public static function announces(string $source): bool
    {
        return PatternScan::matches(
            '/new\s+[A-Za-z]*Mutated\(|->\s*write(?:Set|Increment|CreateRow|Delete)\(|captureRowsById\(|captureTransactions\(|syncCapture->/',
            $source,
        );
    }

    /**
     * The Eloquent model each covered table is reached through, so the guard
     * sees `Transaction::query()->update([...])` as a write to `transactions`.
     * Derived from the models themselves rather than listed, because a list of
     * thirty-nine table names is the kind that rots without failing.
     *
     * @param  array<string, list<string>>  $tables
     * @return array<string, string> table => model short class name
     */
    public static function modelsByTable(array $tables): array
    {
        $models = [];

        foreach (glob(base_path('Modules/*/Models/*.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);
            $class = basename($path, '.php');

            $declared = PatternScan::first('/protected\s+\$table\s*=\s*\'([a-z_]+)\'/', $source)[1] ?? null;
            $table = is_string($declared) ? $declared : Str::plural(Str::snake($class));

            if (isset($tables[$table])) {
                $models[$table] = $class;
            }
        }

        return $models;
    }
}
