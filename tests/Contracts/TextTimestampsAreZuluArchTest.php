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
 * The column-name suffixes this schema gives a moment in time. `_at` alone was
 * the original selector and it is why `internal_date` — a message's arrival
 * instant, on three tables — was invisible to this guard for as long as it was.
 *
 * @return list<string>
 */
function timestampColumnSuffixes(): array
{
    return ['_at', '_date', '_time'];
}

/**
 * Walks the migrated schema for timestamp columns of one storage class and
 * returns the tables carrying them. A table added tomorrow arrives here through
 * its own migration; nothing is written down.
 *
 * @param  list<string>  $storageTypes
 * @return list<string>
 */
function tablesStampedAs(array $storageTypes): array
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

            $named = array_filter(
                timestampColumnSuffixes(),
                static fn (string $suffix): bool => str_ends_with($columnName, $suffix),
            );

            if ($named !== [] && in_array($type, $storageTypes, true)) {
                $tables[] = $name;

                continue 2;
            }
        }
    }

    sort($tables);

    return $tables;
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
    return tablesStampedAs(['text']);
}

/**
 * Every table storing a timestamp in a real DATETIME column. These are read
 * back through CarbonImmutable::parse or an Eloquent datetime cast, both of
 * which apply the app's own offset — so the digits written have to be in that
 * frame, whatever frame the instant arrived in.
 *
 * @return list<string>
 */
function appZoneStampedTables(): array
{
    return tablesStampedAs(['datetime', 'timestamp']);
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
function writesAStampedTable(array $tokens, array $tables): bool
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
 * The writer count is returned beside the offenders rather than counted in a
 * pass of its own: it is the denominator the verdict is read off — a statement
 * reader that stopped pairing a table name with its verb finds no writer at all
 * and reports a tree that routes every stamp through the seam — and a second
 * walk would tokenise six thousand files twice for one integer.
 *
 * @param  list<string>  $paths
 * @param  list<string>  $tables
 * @return array{writers: int, offenders: list<string>} one relative path per
 *                                                      writer that renders a stamp itself, and how many writers were read at all
 */
function stampsWrittenPastTheSeam(array $paths, array $tables): array
{
    $banned = localOffsetRenderings();
    $writers = 0;
    $offenders = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        if (! writesAStampedTable($tokens, $tables)) {
            continue;
        }

        $writers++;

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

    return ['writers' => $writers, 'offenders' => $offenders];
}

/**
 * A `format()` argument that produces a stored stamp rather than a label a
 * reader sees. A display format ('d MMMM', 'H:i') is none of this guard's
 * business; these two shapes are what a DATETIME column and a day key take.
 *
 * @return list<string>
 */
function storedStampFormats(): array
{
    return ['Y-m-d H:i:s', 'Y-m-d'];
}

/**
 * @param  list<string>  $paths
 * @param  list<string>  $tables
 * @return array{writers: int, offenders: list<string>} one relative path per
 *                                                      writer rendering its own stamp, and how many writers were read at all
 */
function appZoneStampsWrittenPastTheSeam(array $paths, array $tables): array
{
    $stored = storedStampFormats();
    $writers = 0;
    $offenders = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        if (! writesAStampedTable($tokens, $tables)) {
            continue;
        }

        $writers++;

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'format') {
                continue;
            }

            $caller = $tokens[$index - 1] ?? null;
            if (! is_array($caller) || ! in_array($caller[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            if (in_array(trim(BackendSourceFiles::callArguments($tokens, $index), "'\""), $stored, true)) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    sort($offenders);

    return ['writers' => $writers, 'offenders' => array_values(array_unique($offenders))];
}

it('finds the tables whose timestamps the schema stores as text, and those it stores as DATETIME', function (): void {
    $text = stringStampedTables();
    $appZone = appZoneStampedTables();

    // Named rather than counted: a schema read that lost a storage class would
    // still return tables, and the count it reported would look healthy.
    $missing = [];

    foreach (['device_registry', 'pairing_tokens', 'relay_mailbox'] as $table) {
        if (! in_array($table, $text, true)) {
            $missing[] = $table.' is no longer read as storing its timestamps as TEXT';
        }
    }

    // The three tables carrying internal_date. None of them ends in _at, which
    // is exactly why the original selector could not see them.
    foreach (['inbox_messages', 'file_imports', 'discovered_senders'] as $table) {
        if (! in_array($table, $appZone, true)) {
            $missing[] = $table.' is no longer read as storing its timestamps as DATETIME';
        }
    }

    expect($missing)->toBe([], "The schema read no longer finds the tables the two rules below are written around:\n  ".implode("\n  ", $missing));

    expect(array_intersect($text, $appZone))->toBe(
        [],
        'A table was read as storing timestamps in BOTH storage classes, so one of the two rules '
        .'below is being applied to a column the other one owns.',
    );
});

it('sees a stamp column the schema grows, in either storage class and under any of the suffixes', function (): void {
    // Without this the derivations above could quietly return nothing and the
    // guard would pass on a codebase it never looked at.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $schema->create('text_stamp_probe', static function (Blueprint $table): void {
        $table->id();
        $table->text('settled_at');
    });
    $schema->create('text_dated_probe', static function (Blueprint $table): void {
        $table->id();
        $table->text('internal_date');
    });
    $schema->create('datetime_stamp_probe', static function (Blueprint $table): void {
        $table->id();
        $table->timestamp('internal_date');
    });
    $schema->create('unstamped_probe', static function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    try {
        $text = stringStampedTables();
        $appZone = appZoneStampedTables();
    } finally {
        $schema->drop('text_stamp_probe');
        $schema->drop('text_dated_probe');
        $schema->drop('datetime_stamp_probe');
        $schema->drop('unstamped_probe');
    }

    $wrong = [];

    foreach ([
        'text_stamp_probe read as TEXT-stamped' => in_array('text_stamp_probe', $text, true),
        'text_dated_probe read as TEXT-stamped under _date' => in_array('text_dated_probe', $text, true),
        'datetime_stamp_probe kept out of the TEXT set' => ! in_array('datetime_stamp_probe', $text, true),
        'datetime_stamp_probe read as DATETIME-stamped' => in_array('datetime_stamp_probe', $appZone, true),
        'text_stamp_probe kept out of the DATETIME set' => ! in_array('text_stamp_probe', $appZone, true),
        'unstamped_probe kept out of the TEXT set' => ! in_array('unstamped_probe', $text, true),
        'unstamped_probe kept out of the DATETIME set' => ! in_array('unstamped_probe', $appZone, true),
    ] as $expectation => $held) {
        if (! $held) {
            $wrong[] = $expectation;
        }
    }

    expect($wrong)->toBe([], "The schema reader no longer separates the two storage classes:\n  ".implode("\n  ", $wrong));
});

it('stamps every text timestamp column through the seam', function (): void {
    $files = BackendSourceFiles::all();
    $tables = stringStampedTables();

    expect(count($files))->toBeGreaterThan(2000, 'the backend walk read almost nothing — the roots are wrong, not the tree.');

    $scan = stampsWrittenPastTheSeam($files, $tables);

    // Twelve writers today, over the tables the live schema declares.
    expect($scan['writers'])->toBeGreaterThan(3, 'nothing anywhere was read as writing a TEXT-stamped table, so the verdict below is read off a scan that found no writers.');

    // Empty, and it shrinks only. The two Modules\Mobile writers that sat here
    // were pinned because ZuluTimestamp lived in Modules\Sync\Internal, which
    // Mobile may not import; the seam is Modules\Core\Public now, so they went.
    expect($scan['offenders'])->toBe(
        [],
        "A timestamp stored as TEXT is sorted and ranged as a string, so a local\n".
        "offset sorts by its own hour digits against a Zulu sibling and the column\n".
        "silently reorders. Instant::zulu() converts and asserts in one call\n".
        'and is the only way to produce one. Writers rendering their own:',
    );
});

it('writes every DATETIME column in the frame it is read back in', function (): void {
    $files = BackendSourceFiles::all();
    $tables = appZoneStampedTables();

    expect(count($files))->toBeGreaterThan(2000, 'the backend walk read almost nothing — the roots are wrong, not the tree.');

    $scan = appZoneStampsWrittenPastTheSeam($files, $tables);

    // Seven writers today, over the tables the live schema declares.
    expect($scan['writers'])->toBeGreaterThan(2, 'nothing anywhere was read as writing a DATETIME-stamped table, so the verdict below is read off a scan that found no writers.');

    // No pins. The reason that used to sit here — a Y-m-d rendered back out of
    // a parsed user-typed string, which is a validation and not a stored stamp
    // — named no entry, and a written reason with nothing under it reads as an
    // exemption somebody granted.
    expect($scan['offenders'])->toBe(
        [],
        "A DATETIME column is read back with CarbonImmutable::parse or a datetime\n".
        "cast, both of which apply the app's offset. A writer that formats a\n".
        "foreign instant itself stores the SENDER's wall clock under that reading:\n".
        'a receipt filed a day early, in the wrong Y/m folder. Writers doing so:',
    );
});

it('sees a foreign instant stored without moving it into the app zone', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'app-zone').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedForeignInstantWrites
        {
            public function store(DateTimeImmutable $internalDate): void
            {
                $this->db->connection()->table('inbox_messages')->insertOrIgnore([
                    'internal_date' => $internalDate->format('Y-m-d H:i:s'),
                ]);
            }
        }
        PHP);

    $clean = tempnam(sys_get_temp_dir(), 'app-zone-clean').'.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedCleanAppZoneWrites
        {
            public function store(DateTimeImmutable $internalDate): void
            {
                $this->db->connection()->table('inbox_messages')->insertOrIgnore([
                    'internal_date' => Instant::appLocal($internalDate),
                ]);
            }
        }
        PHP);

    $display = tempnam(sys_get_temp_dir(), 'app-zone-display').'.php';
    file_put_contents($display, <<<'PHP'
        <?php
        final class PlantedDisplayFormatter
        {
            public function label(DateTimeImmutable $moment): string
            {
                $this->db->connection()->table('inbox_messages')->insertOrIgnore(['status' => 'fetched']);

                return $moment->format('H:i');
            }
        }
        PHP);

    try {
        $scan = appZoneStampsWrittenPastTheSeam([$planted, $clean, $display], ['inbox_messages']);
    } finally {
        @unlink($planted);
        @unlink($clean);
        @unlink($display);
    }

    expect($scan['writers'])->toBe(3, 'All three planted files write inbox_messages; the reader saw a different number of writers.');

    $names = array_map(static fn (string $path): string => basename($path), $scan['offenders']);

    expect($names)->toBe(
        [basename($planted)],
        'The reader must flag only the file that formats a foreign instant itself. The one routing '
        .'through Instant::appLocal() and the one formatting a display label beside an unrelated '
        .'write are both correct, and flagging either turns this rule into noise.',
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
                $now = Instant::zulu($this->clock->now());
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
        $scan = stampsWrittenPastTheSeam([$planted, $chained, $clean, $unrelated], ['device_registry']);
    } finally {
        @unlink($planted);
        @unlink($chained);
        @unlink($clean);
        @unlink($unrelated);
    }

    // Three of the four write device_registry; the fourth writes transactions.
    expect($scan['writers'])->toBe(3, 'The reader counted a different number of device_registry writers than the three planted here.');

    $names = array_map(static fn (string $path): string => basename($path), $scan['offenders']);

    expect($names)->toHaveCount(
        2,
        'The reader must flag the direct write and the one whose verb sits three where() clauses '
        .'below the table name, and must leave both the seam call and the unrelated table alone.',
    );
    expect($names)->toContain(basename($chained))->toContain(basename($planted));
});
