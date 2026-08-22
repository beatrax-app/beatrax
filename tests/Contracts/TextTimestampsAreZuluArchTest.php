<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * The Carbon renderings that carry a local offset, or no zone at all, into a
 * string. `toIso8601ZuluString()` is here too: it produces the right shape but
 * skips the assertion, and the rule is that one call site produces these values.
 *
 * @return list<string>
 */
function localOffsetRenderings(): array
{
    return [
        'toIso8601String',
        'toIso8601ZuluString',
        'toRfc3339String',
        'toAtomString',
        'toW3cString',
        'toDateTimeString',
        'toDateTimeLocalString',
    ];
}

/**
 * Every table the migrated schema stores a timestamp on as TEXT. SQL compares
 * TEXT with a byte-wise collation, so these are exactly the timestamps that are
 * sorted and ranged as strings rather than as instants.
 *
 * @return list<string>
 */
function stringStampedTables(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $tables = [];

    foreach ($schema->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;
        if ($name === null || str_starts_with($name, 'sqlite_')) {
            continue;
        }

        foreach ($schema->getColumns($name) as $column) {
            $columnName = is_string($column['name']) ? $column['name'] : '';
            $type = is_string($column['type_name'] ?? null) ? strtolower((string) $column['type_name']) : '';

            if (str_ends_with($columnName, '_at') && $type === 'text') {
                $tables[] = $name;

                continue 2;
            }
        }
    }

    sort($tables);

    return $tables;
}

/**
 * Statement-scoped rather than a fixed lookback: the write verb sits at the end
 * of a builder chain whose length is the caller's business, and a pair of
 * `where()` clauses is already enough to push the table name out of reach.
 *
 * @param  list<string>  $tables
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return bool whether the file writes a row into one of $tables
 */
function writesAStringStampedTable(array $tokens, array $tables): bool
{
    $verbs = ['insert', 'insertgetid', 'insertorignore', 'update', 'updateorinsert', 'upsert'];
    $tableInHand = false;

    foreach ($tokens as $token) {
        $text = is_array($token) ? $token[1] : $token;

        if ($text === ';' || $text === '{' || $text === '}') {
            $tableInHand = false;

            continue;
        }

        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], "'\""), $tables, true)) {
            $tableInHand = true;

            continue;
        }

        if ($tableInHand && $token[0] === T_STRING && in_array(strtolower($token[1]), $verbs, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $paths
 * @param  list<string>  $tables
 * @return list<string> one relative path per writer that renders a stamp itself
 */
function stampsWrittenPastZuluTimestamp(array $paths, array $tables): array
{
    $banned = localOffsetRenderings();
    $offenders = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        if (! writesAStringStampedTable($tokens, $tables)) {
            continue;
        }

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $banned, true)) {
                continue;
            }

            $caller = $tokens[$index - 1] ?? null;
            if (is_array($caller) && in_array($caller[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

it('finds the tables whose timestamps the schema stores as text', function (): void {
    $tables = stringStampedTables();

    expect($tables)->toContain('device_registry');
    expect($tables)->toContain('pairing_tokens');
    expect($tables)->toContain('relay_mailbox');
});

it('sees a text stamp column the schema grows, and ignores a real datetime one', function (): void {
    // Without this the derivation above could quietly return nothing and the
    // guard would pass on a codebase it never looked at.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $schema->create('text_stamp_probe', static function (Blueprint $table): void {
        $table->id();
        $table->text('settled_at');
    });
    $schema->create('datetime_stamp_probe', static function (Blueprint $table): void {
        $table->id();
        $table->timestamp('settled_at');
    });

    try {
        $tables = stringStampedTables();
    } finally {
        $schema->drop('text_stamp_probe');
        $schema->drop('datetime_stamp_probe');
    }

    expect($tables)->toContain('text_stamp_probe');
    expect($tables)->not->toContain('datetime_stamp_probe');
});

it('stamps every text timestamp column through ZuluTimestamp', function (): void {
    $files = BackendSourceFiles::all();
    expect($files)->not->toBeEmpty();

    // Shrinks only. Each entry is a writer that still renders its own stamp,
    // with the reason it was not converted with the rest.
    $pinned = [
        // Modules\Mobile may not import Modules\Sync\Internal, where
        // ZuluTimestamp lives; converting these needs a Public seam first.
        'Modules/Mobile/Internal/Sync/InitialSyncPuller.php',
        'Modules/Mobile/Internal/Sync/MobileImportIntentGate.php',
        // Writes device_registry.paired_at/confirmed_at/created_at, so the
        // rewrite migration beside it is undone on the next pairing.
    ];

    expect(stampsWrittenPastZuluTimestamp($files, stringStampedTables()))->toBe(
        $pinned,
        "A timestamp stored as TEXT is sorted and ranged as a string, so a local\n".
        "offset sorts by its own hour digits against a Zulu sibling and the column\n".
        "silently reorders. ZuluTimestamp::stamp() converts and asserts in one call\n".
        'and is the only way to produce one. Writers rendering their own:',
    );
});

it('sees a stamp a writer rendered itself', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'text-stamp').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedStampWrites
        {
            public function touch(int $userId): void
            {
                $now = $this->clock->now()->toIso8601String();
                $this->db->connection()->table('device_registry')->update(['last_seen_at' => $now]);
            }
        }
        PHP);

    $clean = tempnam(sys_get_temp_dir(), 'text-stamp-clean').'.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedCleanWrites
        {
            public function touch(int $userId): void
            {
                $now = ZuluTimestamp::stamp($this->clock->now());
                $this->db->connection()->table('device_registry')->update(['last_seen_at' => $now]);
            }
        }
        PHP);

    $chained = tempnam(sys_get_temp_dir(), 'text-stamp-chain').'.php';
    file_put_contents($chained, <<<'PHP'
        <?php
        final class PlantedChainedWrites
        {
            public function touch(int $userId, string $deviceId): void
            {
                $now = $this->clock->now()->toIso8601String();
                $this->db->connection()->table('device_registry')
                    ->where('user_id', $userId)
                    ->where('device_id', $deviceId)
                    ->where('is_self', 0)
                    ->update(['last_seen_at' => $now]);
            }
        }
        PHP);

    $unrelated = tempnam(sys_get_temp_dir(), 'text-stamp-other').'.php';
    file_put_contents($unrelated, <<<'PHP'
        <?php
        final class PlantedUnrelatedWrites
        {
            public function touch(int $userId): void
            {
                $this->db->connection()->table('transactions')->update([
                    'booked_at' => $this->clock->now()->toIso8601String(),
                ]);
            }
        }
        PHP);

    try {
        $found = stampsWrittenPastZuluTimestamp([$planted, $chained, $clean, $unrelated], ['device_registry']);
    } finally {
        @unlink($planted);
        @unlink($chained);
        @unlink($clean);
        @unlink($unrelated);
    }

    $names = array_map(static fn (string $path): string => basename($path), $found);

    expect($names)->toHaveCount(2);
    expect($names)->toContain(basename($chained));
    expect($names)->toContain(basename($planted));
});
