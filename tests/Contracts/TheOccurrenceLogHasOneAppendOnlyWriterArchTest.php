<?php

declare(strict_types=1);

/**
 * @link ../../.docs/features/recurring/how-to-test.md
 */

/** @return array<string, string> repo-relative path => source of every production PHP file */
function occurrenceLogScannedSources(): array
{
    $sources = [];

    foreach ([base_path('Modules'), base_path('app')] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($walk as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();

            // Migrations build and backfill the table once; the invariant here
            // is about the steady-state write path Sync replicates. The suite
            // writes occurrence rows directly on purpose, in factories and in
            // the fixtures that assert on the detector's own output.
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }

            $sources[str_replace(base_path().'/', '', $path)] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

// A statement is everything from the table name to the next semicolon, because
// a builder chain puts the verb several lines below the ->table() call.
/**
 * @param  array<string, string>  $sources  repo-relative path => source
 * @return array<string, list<string>> path => the write verbs it aims at the log
 */
function occurrenceLogWriteSites(array $sources): array
{
    $verbs = [
        'insertOrIgnore', 'insertGetId', 'insertUsing', 'insert',
        'updateOrInsert', 'update', 'upsert', 'delete', 'truncate',
    ];
    $sites = [];

    foreach ($sources as $path => $source) {
        $offset = 0;

        while (($at = strpos($source, 'recurring_series_occurrences', $offset)) !== false) {
            $end = strpos($source, ';', $at);
            $statement = substr($source, $at, $end === false ? 400 : $end - $at);

            foreach ($verbs as $verb) {
                if (str_contains($statement, '->'.$verb.'(')) {
                    $sites[$path][$verb] = true;
                }
            }

            $offset = $at + 1;
        }
    }

    return array_map(static fn (array $verbs): array => array_keys($verbs), $sites);
}

// A sweep that reads its table list off the live schema clears the occurrence
// log without ever naming it, so the scan above is blind to it — and that is
// the shape the teardown paths now take.
/**
 * @param  array<string, string>  $sources  repo-relative path => source
 * @return list<string>
 */
function occurrenceLogSchemaWideDeleters(array $sources): array
{
    $deleters = [];

    foreach ($sources as $path => $source) {
        if (! str_contains($source, 'getTableListing(')) {
            continue;
        }

        $offset = 0;

        while (($at = strpos($source, '->table($', $offset)) !== false) {
            $end = strpos($source, ';', $at);
            $statement = substr($source, $at, $end === false ? 400 : $end - $at);

            if (str_contains($statement, '->delete(') || str_contains($statement, '->truncate(')) {
                $deleters[$path] = true;
            }

            $offset = $at + 1;
        }
    }

    return array_keys($deleters);
}

it('finds the writer it is named for, so a silent scan cannot pass this file', function (): void {
    $sources = occurrenceLogScannedSources();

    // 6,471 production files today, thirteen of which name the table. Floored
    // far under: a walk that lost a root reports the same empty offender lists
    // the three rules below report on a tree with one writer.
    expect(count($sources))->toBeGreaterThan(2000, 'the production walk read almost nothing — the roots are wrong, not the tree.');

    $writers = array_keys(occurrenceLogWriteSites($sources));

    expect(in_array('Modules/Recurring/Internal/Detectors/OccurrenceWriter.php', $writers, true))->toBeTrue(
        'The one writer this rule is written around was not found by the statement scan, so the '
        .'three verdicts below are read off a walk that reached nothing. Found: '.implode(', ', $writers),
    );
});

it('lets nothing but the detector append to the occurrence log', function (): void {
    $appenders = [];
    foreach (occurrenceLogWriteSites(occurrenceLogScannedSources()) as $path => $verbs) {
        if (array_intersect($verbs, ['insertOrIgnore', 'insertGetId', 'insertUsing', 'insert', 'updateOrInsert', 'upsert']) !== []) {
            $appenders[] = $path;
        }
    }
    sort($appenders);

    expect($appenders)->toBe(['Modules/Recurring/Internal/Detectors/OccurrenceWriter.php'], implode("\n", [
        'A second writer of recurring_series_occurrences. Offenders:',
        ...$appenders,
        '',
        'OccurrenceWriter::write() is the one production writer, and its',
        'insertOrIgnore against the (series, transaction) UNIQUE is what makes a',
        're-detection sweep a no-op rather than a duplicate. Sync\'s merge rules',
        'for this table are written against that append-only shape, so a second',
        'writer does not just add rows — it changes what replication is allowed',
        'to assume. Go through the detector, or widen it.',
        'A second READER breaks nothing and is not covered here.',
    ]));
});

it('never rewrites an occurrence in place', function (): void {
    $mutators = [];
    foreach (occurrenceLogWriteSites(occurrenceLogScannedSources()) as $path => $verbs) {
        if (array_intersect($verbs, ['update', 'updateOrInsert', 'upsert']) !== []) {
            $mutators[] = $path;
        }
    }
    sort($mutators);

    expect($mutators)->toBe([], implode("\n", [
        'An occurrence row is updated in place. Offenders:',
        ...$mutators,
        '',
        'This is the half that breaks replication rather than merely duplicating.',
        'The rows are append-only, and the merge rules resolve them on that',
        'basis; a row that changes after it has been replicated has no rule that',
        'says which side wins. Append a corrected occurrence instead.',
    ]));
});

it('keeps deletion to the account-scoped purge', function (): void {
    $sources = occurrenceLogScannedSources();
    $deleters = occurrenceLogSchemaWideDeleters($sources);
    foreach (occurrenceLogWriteSites($sources) as $path => $verbs) {
        if (array_intersect($verbs, ['delete', 'truncate']) !== []) {
            $deleters[] = $path;
        }
    }
    sort($deleters);

    // A whole-account teardown — the demo reset, and an account deleting itself
    // — is not a writer in the steady-state sense the rules above are about,
    // and both reach the log through this one purge. Sync's applier replays a
    // peer's copy of that same delete and names no table of its own.
    expect($deleters)->toBe(['Modules/Auth/Internal/Account/UserScopedDataPurge.php'], implode("\n", [
        'Something other than the account-scoped purge deletes occurrence rows. Offenders:',
        ...$deleters,
        '',
        'An empty list is a failure too: it means neither scan can see the purge',
        'that does delete them, and a scan blind to a legitimate deleter would',
        'be blind to an illegitimate one.',
    ]));
});

it('sees a second appender, an in-place rewrite and a schema-wide sweep', function (): void {
    $sources = [
        'planted/SecondAppender.php' => <<<'PHP'
            <?php
            $this->db->table('recurring_series_occurrences')->insert(['series_id' => 1]);
            PHP,
        'planted/InPlaceRewrite.php' => <<<'PHP'
            <?php
            $this->db->table('recurring_series_occurrences')
                ->where('series_id', $id)
                ->where('transaction_id', $transaction)
                ->update(['matched_at' => $now]);
            PHP,
        'planted/SchemaWideSweep.php' => <<<'PHP'
            <?php
            foreach ($schema->getTableListing() as $table) {
                $this->db->table($table)->where('user_id', $userId)->delete();
            }
            PHP,
        'planted/ReadsOnly.php' => <<<'PHP'
            <?php
            $rows = $this->db->table('recurring_series_occurrences')->where('series_id', $id)->get();
            PHP,
    ];

    $sites = occurrenceLogWriteSites($sources);

    expect(array_keys($sites))->toBe(
        ['planted/SecondAppender.php', 'planted/InPlaceRewrite.php'],
        'The statement reader must find the appender and the rewrite, must reach the verb past three '
        .'where() clauses, and must not call a read a write.',
    );

    expect($sites['planted/SecondAppender.php'])->toBe(['insert'], 'The appender was read as some other verb.');
    expect($sites['planted/InPlaceRewrite.php'])->toBe(['update'], 'The in-place rewrite was read as some other verb.');

    expect(occurrenceLogSchemaWideDeleters($sources))->toBe(
        ['planted/SchemaWideSweep.php'],
        'A sweep that reads its table list off the live schema clears this log without naming it, '
        .'which is the whole reason the second reader exists.',
    );
});
