<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 * @link ../../.docs/features/recurring/architecture.md
 */

// recurring_series_occurrences is read by Calendar, Chains and Sync as well as
// by its own module, and that is fine: a second READER breaks nothing. A second
// WRITER does. OccurrenceWriter appends with insertOrIgnore against the
// (recurring_series_id, transaction_id) UNIQUE, which is what makes re-running
// detection a no-op, and it is the whole reason Sync's merge rules declare the
// table append-only with no mergeable field. A writer that updated a row in
// place would replicate as a create the peer already has, and the peer would
// keep the value the detector overwrote.
/**
 * @return list<string>
 */
function recurringOccurrenceScannedFiles(): array
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
            // Migrations create and drop the table by definition, and a test
            // fixture builds the rows the production path would have detected.
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

// Comments are blanked rather than dropped so a reported line number still
// points at the line the write is on.
function recurringOccurrenceSource(string $source): string
{
    return (string) preg_replace_callback(
        '#/\*.*?\*/|//[^\n]*#s',
        static fn (array $m): string => (string) preg_replace('/[^\r\n]/', ' ', $m[0]),
        $source,
    );
}

/**
 * Every write into the table found in one file, as `line  verb`.
 *
 * @return list<string>
 */
function recurringOccurrenceWrites(string $source): array
{
    $stripped = recurringOccurrenceSource($source);
    $hits = [];

    foreach (recurringOccurrenceStatements($stripped) as [$offset, $statement]) {
        $verb = recurringOccurrenceVerb($statement);

        if ($verb !== null) {
            $hits[] = (substr_count($stripped, "\n", 0, $offset) + 1).'  '.$verb;
        }
    }

    return $hits;
}

/**
 * The statement each mention of the table opens, up to the `;` that closes it.
 * A statement is the unit because the verb sits at the end of a builder chain
 * whose table name sits at the front of it.
 *
 * @return list<array{0: int, 1: string}>
 */
function recurringOccurrenceStatements(string $stripped): array
{
    $pattern = '/(?:->|::)?\b(?:table|from|insert into|update|replace into)\s*\(?\s*'
        ."['\"]recurring_series_occurrences|\\bRecurringSeriesOccurrence\\b/i";

    $matches = PatternScan::allWithOffsets($pattern, $stripped);

    $statements = [];

    foreach ($matches[0] as $match) {
        $offset = (int) $match[1];
        $end = strpos($stripped, ';', $offset);
        $statements[] = [$offset, substr($stripped, $offset, $end === false ? null : $end - $offset)];
    }

    return $statements;
}

// delete() is deliberately absent: the demo teardown deletes whole users, and a
// delete replicates cleanly because the table's merge rule is _delete_wins. The
// invariant is about a row being written twice, not about it going away.
function recurringOccurrenceVerb(string $statement): ?string
{
    foreach ([
        'insertOrIgnore',
        'insertGetId',
        'insertUsing',
        'updateOrInsert',
        'updateOrCreate',
        'firstOrCreate',
        'forceCreate',
        'insert',
        'upsert',
        'update',
        'create',
        'save',
    ] as $verb) {
        if (preg_match('/->'.$verb.'\s*\(/', $statement) === 1) {
            return $verb;
        }
    }

    if (preg_match('/^\s*(insert into|update|replace into)/i', $statement) === 1) {
        return 'raw SQL';
    }

    return null;
}

it('writes recurring_series_occurrences from exactly one production class, and only by appending', function (): void {
    $writer = base_path('Modules/Recurring/Internal/Detectors/OccurrenceWriter.php');
    // A demo fixture builds the rows a real detection would have produced, on a
    // database no peer ever pairs with.
    $demoSeeder = base_path('Modules/DriftAlerts/Database/Seeders/Demo/DemoDriftAlertsSeeder.php');

    $foreignWriters = [];
    $writerVerbs = [];

    foreach (recurringOccurrenceScannedFiles() as $path) {
        $writes = recurringOccurrenceWrites((string) file_get_contents($path));

        if ($writes === []) {
            continue;
        }

        $label = str_replace(base_path().'/', '', $path);

        if ($path === $writer) {
            foreach ($writes as $write) {
                $writerVerbs[] = $label.':'.$write;
            }

            continue;
        }

        if ($path === $demoSeeder) {
            continue;
        }

        foreach ($writes as $write) {
            $foreignWriters[] = $label.':'.$write;
        }
    }

    expect(is_file($writer))->toBeTrue('OccurrenceWriter is the subject of this rule and has to exist for it to mean anything');

    expect($foreignWriters)->toBe(
        [],
        'recurring_series_occurrences has exactly one production writer, '
        .'OccurrenceWriter::write(). Reading it from another module is fine and three '
        .'already do — Calendar, Chains and Sync each need a shape the Recurring query '
        .'cannot give them. Writing it from another module is not. The append is what '
        .'makes re-detection a no-op, and Sync declares the table append-only with no '
        .'mergeable field on the strength of it, so a second writer replicates as a '
        ."create the peer already holds. Offenders:\n  ".implode("\n  ", $foreignWriters),
    );

    expect(array_values(array_unique(array_map(
        static fn (string $hit): string => substr($hit, (int) strrpos($hit, '  ') + 2),
        $writerVerbs,
    ))))->toBe(
        ['insertOrIgnore'],
        'OccurrenceWriter::write() must append with insertOrIgnore and nothing else. The '
        .'(recurring_series_id, transaction_id) UNIQUE is what absorbs a re-detection of the '
        .'same cluster, and an insert() would raise on it while an upsert() or update() would '
        ."silently rewrite an observation a peer already replicated. Found:\n  "
        .implode("\n  ", $writerVerbs),
    );
});

it('sees a second writer that a query-builder chain hides', function (): void {
    // The rule is worth exactly what its ability to go red is worth, and a
    // scanner that matched nothing would pass this repo just as quietly.
    $planted = <<<'PHP'
        <?php
        final class PlantedOccurrenceWriter
        {
            public function readsAreFine(): void
            {
                $this->db->connection()->table('recurring_series_occurrences')
                    ->where('user_id', 1)
                    ->get(['transaction_id']);
            }

            public function run(): void
            {
                $this->db->connection()->table('recurring_series_occurrences')->upsert(
                    [['transaction_id' => 1]],
                    ['recurring_series_id', 'transaction_id'],
                );
            }

            public function throughTheModel(): void
            {
                RecurringSeriesOccurrence::query()->create(['transaction_id' => 2]);
            }
        }
        PHP;

    expect(recurringOccurrenceWrites($planted))->toBe(['13  upsert', '21  create']);
});

it('reads a mention only where it is a write', function (): void {
    $readOnly = <<<'PHP'
        <?php
        $this->db->connection()->table('recurring_series_occurrences as rso')
            ->join('transactions as t', 't.id', '=', 'rso.transaction_id')
            ->get();
        $this->belongsTo(RecurringSeriesOccurrence::class, 'latest_occurrence_id');
        PHP;

    expect(recurringOccurrenceWrites($readOnly))->toBe([]);

    $commentedOut = <<<'PHP'
        <?php
        // $db->table('recurring_series_occurrences')->insert($row);
        PHP;

    expect(recurringOccurrenceWrites($commentedOut))->toBe([]);
});
