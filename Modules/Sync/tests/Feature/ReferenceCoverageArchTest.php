<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\RowOwnership;

uses(RefreshDatabase::class);

// RowOwnership derives its references from the live foreign keys, so a column
// that names a user-scoped row WITHOUT a foreign key is invisible to it, and the
// only thing standing between that column and a cross-user write is someone
// remembering. Every id-shaped column must therefore be classified somewhere.

// Correlation and foreign-system identifiers: they end in _id but reference
// nothing in this database, and being varchar rather than integer is the tell.
const ACKNOWLEDGED_NON_REFERENCES = [
    'envelope_moves.move_group_id',
    'migration_source_map.source_external_id',
    'notification_preferences.device_id',
];

it('classifies every id-shaped column on a covered table', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $schema = $db->connection()->getSchemaBuilder();
    $ownership = new RowOwnership($db);
    $unclassified = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        if (! $schema->hasTable($table)) {
            continue;
        }

        $derived = $ownership->ownedReferences($table);
        $polymorphic = $ownership->polymorphicReferences($table);

        foreach ($schema->getColumns($table) as $column) {
            $name = $column['name'];

            if ($name === 'user_id' || ! str_ends_with($name, '_id')) {
                continue;
            }

            if (isset($derived[$name]) || isset($polymorphic[$name])) {
                continue;
            }

            if (in_array($table.'.'.$name, ACKNOWLEDGED_NON_REFERENCES, true)) {
                expect($column['type_name'])->toBe('varchar', $table.'.'.$name.' is acknowledged as a non-reference but is an integer');

                continue;
            }

            $unclassified[] = $table.'.'.$name;
        }
    }

    expect($unclassified)->toBe([], implode("\n  ", array_merge(
        ['These columns name a row and nothing checks who owns it.',
            'Add a foreign key (which RowOwnership derives automatically), or list the column in',
            'RowOwnership::UNENFORCED_REFERENCES / POLYMORPHIC_REFERENCES, or, if it references nothing',
            'in this database, add it to ACKNOWLEDGED_NON_REFERENCES here:'],
        $unclassified,
    )));
});

it('keeps every unenforced reference pointing at a table that exists and is user-scoped', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $schema = $db->connection()->getSchemaBuilder();
    $ownership = new RowOwnership($db);

    // A reference listed by hand is only worth listing if its target is real
    // and owner-scoped; a typo would otherwise read as coverage.
    foreach (['transactions' => 'counterparty_id', 'forecast_scenario_mutations' => 'target_series_id'] as $table => $column) {
        $target = $ownership->ownedReferences($table)[$column] ?? null;

        expect($target)->not->toBeNull($table.'.'.$column.' is no longer covered')
            ->and($schema->hasTable((string) $target))->toBeTrue()
            ->and($ownership->hasUserIdColumn((string) $target))->toBeTrue();
    }
});

// EntityChangeApplier maps a SOURCE entity type to its beatrax name at runtime
// rather than naming one, so the scan below cannot read a word out of it. Every
// word it can return is already written as a literal by PromoteStagingToDomain,
// which is where the scan does read them.
const ACKNOWLEDGED_DERIVED_ENTITY_TYPE_ARGUMENTS = [
    'Modules/Migration/Internal/Pipeline/EntityChangeApplier.php' => 'self::beatraxEntityType($entityType)',
];

/**
 * @return array{literals: array<string, string>, derived: array<int, string>}
 */
function sourceMapRecordedEntityTypes(): array
{
    $literals = [];
    $derived = [];
    $root = dirname(__DIR__, 4).'/Modules/Migration';

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php' || str_contains($path, '/tests/')) {
            continue;
        }

        $relative = 'Modules/Migration'.substr($path, strlen($root));
        $tokens = token_get_all((string) file_get_contents($path));

        foreach (array_keys($tokens) as $index) {
            if (! sourceMapWriterRecordCallAt($tokens, $index)) {
                continue;
            }

            $argument = sourceMapRecordArgument($tokens, $index + 1, 2);

            if (count($argument) === 1 && is_array($argument[0]) && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[trim($argument[0][1], "'\"")] = $relative;

                continue;
            }

            $derived[] = $relative.' -> '.implode('', array_map(
                static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
                $argument,
            ));
        }
    }

    return ['literals' => $literals, 'derived' => $derived];
}

/**
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function sourceMapWriterRecordCallAt(array $tokens, int $index): bool
{
    $name = $tokens[$index];

    return is_array($name)
        && $name[0] === T_STRING
        && $name[1] === 'record'
        && ($tokens[$index - 2] ?? null) !== null
        && is_array($tokens[$index - 2])
        && $tokens[$index - 2][1] === 'sourceMapWriter';
}

// Splits the argument list that starts at the next '(' and returns the tokens
// of one argument, whitespace dropped. Depth-counted rather than comma-split:
// a nested call or array literal carries commas of its own.
/**
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 * @return array<int, array{0: int, 1: string, 2: int}|string>
 */
function sourceMapRecordArgument(array $tokens, int $from, int $wanted): array
{
    $depth = 0;
    $position = 0;
    $collected = [];

    for ($index = $from, $total = count($tokens); $index < $total; $index++) {
        $token = $tokens[$index];

        if (is_array($token)) {
            if ($depth >= 1 && $position === $wanted && $token[0] !== T_WHITESPACE) {
                $collected[] = $token;
            }

            continue;
        }

        if (in_array($token, ['(', '[', '{'], true)) {
            $depth++;

            if ($depth === 1) {
                continue;
            }
        } elseif (in_array($token, [')', ']', '}'], true)) {
            $depth--;

            if ($depth === 0) {
                break;
            }
        } elseif ($token === ',' && $depth === 1) {
            $position++;

            continue;
        }

        if ($depth >= 1 && $position === $wanted) {
            $collected[] = $token;
        }
    }

    return $collected;
}

// The map row is polymorphic, so `beatrax_id` is only checkable once
// `beatrax_entity_type` resolves to a table. A word the promoter writes and the
// merge layer does not know is not waved through — it is refused as cross-user,
// which quarantines a perfectly legitimate mapping on the paired device.
it('resolves every entity type the migration writer records into beatrax_entity_type', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $scanned = sourceMapRecordedEntityTypes();

    expect($scanned['literals'])->not->toBe([], 'the scan found no SourceMapWriter::record() call sites at all');

    foreach ($scanned['derived'] as $call) {
        [$file] = explode(' -> ', $call, 2);
        expect($call)->toBe(
            $file.' -> '.(ACKNOWLEDGED_DERIVED_ENTITY_TYPE_ARGUMENTS[$file] ?? ''),
            $call.' passes a computed beatrax_entity_type this test cannot read; name it in ACKNOWLEDGED_DERIVED_ENTITY_TYPE_ARGUMENTS with the reason its words are covered elsewhere',
        );
    }

    $ownership = new RowOwnership($db);
    $unresolved = [];

    foreach ($scanned['literals'] as $entityType => $writtenIn) {
        $resolves = $ownership->referencesBelongToUser(
            'migration_source_map',
            ['beatrax_id' => PHP_INT_MAX, 'beatrax_entity_type' => $entityType],
            1,
        );

        if (! $resolves) {
            $unresolved[] = $entityType.' (written by '.$writtenIn.')';
        }
    }

    expect($unresolved)->toBe([], implode("\n  ", array_merge(
        ['These entity types are written into migration_source_map.beatrax_entity_type and',
            'RowOwnership::POLYMORPHIC_TABLES has no table for them, so every row carrying one is',
            'quarantined as cross_user on the paired device instead of being replicated:'],
        $unresolved,
    )));
});

// Without this the probe above would pass on a map that resolved everything.
it('refuses an entity type the merge layer has never heard of', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect((new RowOwnership($db))->referencesBelongToUser(
        'migration_source_map',
        ['beatrax_id' => PHP_INT_MAX, 'beatrax_entity_type' => 'not_an_entity_type'],
        1,
    ))->toBeFalse();
});
