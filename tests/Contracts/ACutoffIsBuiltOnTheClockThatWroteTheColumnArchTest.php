<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// SQLite's `datetime('now')` is UTC. Every timestamp column in this app is
// written from the injected Clock, which runs at APP_TIMEZONE. A cutoff asked
// of the database and compared against a column the app clock wrote therefore
// puts the two sides of the comparison in different frames, and the retention
// edge lands an offset away from the rule that is written down.
//
// The fix belongs where the rule lives, not at the query: RetentionWindow
// builds it once, on the Clock, and both sweeps read it from there.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-retention-cutoff-read-off-a-different-clock-than-the-column

/**
 * Every backend file plus the migrations, because a database-clock default is
 * written in a migration and the guard has to be able to see it there to pin
 * it with a reason rather than miss it.
 *
 * @return list<string>
 */
function filesThatCouldNameTheDatabaseClock(): array
{
    $paths = BackendSourceFiles::all();

    foreach ([base_path('Modules'), base_path('database')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            // Leading slash: unanchored, this matched every file in a checkout
            // whose own directory happened to end in the word, and the guard then
            // reported two Core TESTS as offenders on one machine and not another.
            if ($file->isFile() && str_ends_with($path, '.php') && str_contains(strtolower($path), '/migrations/')) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * Every place a file asks SQLite what time it is, as the text of the ask rather
 * than as the name of the file holding it. A per-file answer is what let one
 * pinned migration cover any later cutoff written into the same file, which is
 * the failure this rule is about.
 *
 * @param  list<string>  $paths
 * @return list<array{file: string, ask: string}>
 */
function databaseClockAsks(array $paths): array
{
    $askingForNow = "/(?:datetime|date|julianday|unixepoch|strftime)\s*\(\s*(?:'[^']*'\s*,\s*)?'now'/i";
    $currentTimestamp = '/\bCURRENT_TIMESTAMP\b/i';
    $stringTokens = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
    $asks = [];

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (BackendSourceFiles::codeTokens($path) as $token) {
            if (! is_array($token) || ! in_array($token[0], $stringTokens, true)) {
                continue;
            }

            foreach ([$askingForNow, $currentTimestamp] as $pattern) {
                foreach (PatternScan::all($pattern, $token[1])[0] as $ask) {
                    $asks[] = ['file' => $relative, 'ask' => $ask];
                }
            }
        }
    }

    usort($asks, static fn (array $a, array $b): int => [$a['file'], $a['ask']] <=> [$b['file'], $b['ask']]);

    return $asks;
}

// Shrinks only. Each entry pins ONE ask, by the text of the declaration that
// earned it rather than by the file holding it, and states why naming the
// database clock is safe there — which always means the value is never ordered
// or ranged. `covers` decides which ask the pin excuses; `proves` re-reads the
// declaration itself, so a DEFAULT that moves to a column with a cutoff over it
// fails here rather than inheriting the exemption.
const DATABASE_CLOCK_PINS = [
    'Modules/Sync/Database/Migrations/2026_06_15_000002_create_hlc_clock_state_table.php' => [
        'reason' => 'a DEFAULT for a row the HLC writer stamps in the same statement it inserts; nothing orders or ranges hlc_clock_state.updated_at, which is read back whole per (user_id, device_id)',
        'sites' => 1,
        'covers' => "/^datetime\\('now'$/i",
        'proves' => "/updated_at\\s+DATETIME NOT NULL DEFAULT \\(datetime\\('now'\\)\\)/",
    ],
    'Modules/Sync/Database/Migrations/2026_08_27_000001_create_sync_peer_catch_up_state_table.php' => [
        'reason' => 'the same shape: sync_peer_catch_up_state.updated_at is a per-peer bookmark read by primary key, never compared against a cutoff',
        'sites' => 1,
        'covers' => "/^datetime\\('now'$/i",
        'proves' => "/updated_at\\s+DATETIME NOT NULL DEFAULT \\(datetime\\('now'\\)\\)/",
    ],
];

it('builds every cutoff on the clock that wrote the column it is compared against', function (): void {
    $paths = filesThatCouldNameTheDatabaseClock();

    // Far under the thousands the tree holds, so a walk that opened nothing
    // fails here rather than reporting a tree that asks the database nothing.
    expect(count($paths))->toBeGreaterThan(
        1000,
        'The walk opened '.count($paths).' files, which is too few to have read the tree at all.',
    );

    $offenders = [];
    $reached = [];

    foreach (databaseClockAsks($paths) as $ask) {
        $pin = DATABASE_CLOCK_PINS[$ask['file']] ?? null;

        if ($pin !== null && PatternScan::matches($pin['covers'], $ask['ask'])) {
            $reached[$ask['file']] = ($reached[$ask['file']] ?? 0) + 1;

            continue;
        }

        $offenders[] = $ask['file'].' asks for '.$ask['ask'];
    }

    expect($offenders)->toBe(
        [],
        "SQLite's datetime('now') is UTC; Clock::now() runs at APP_TIMEZONE.\n".
        "Comparing one against a column the other wrote moves the edge by the\n".
        "offset — a 365-day retention rule pruned two hours late, silently.\n".
        "Build the cutoff on the Clock. Asks of the database instead:\n  ".
        implode("\n  ", $offenders),
    );

    // A pin excusing nothing, and a pin excusing more than the one site it was
    // granted for, are the two ways this list stops describing the tree.
    $counted = array_map(static fn (array $pin): int => $pin['sites'], DATABASE_CLOCK_PINS);
    ksort($counted);
    ksort($reached);

    expect($reached)->toBe(
        $counted,
        'A pinned file asks the database a different number of times than the entry claims. A second ask in an '
        .'already-pinned file is exactly what a per-file waiver would have waved through.',
    );
});

it('still holds each pinned ask to the declaration it was granted for', function (): void {
    $broken = [];

    foreach (DATABASE_CLOCK_PINS as $relative => $pin) {
        $path = base_path($relative);

        if (! is_file($path)) {
            $broken[] = $relative.' is pinned and no longer exists';

            continue;
        }

        if (! PatternScan::matches($pin['proves'], (string) file_get_contents($path))) {
            $broken[] = $relative.' is exempt because "'.$pin['reason'].'", and it no longer reads that way';
        }
    }

    expect($broken)->toBe([], implode("\n  ", [
        'A pinned database clock is only safe on the column it was granted for. When that declaration moves, the',
        'exemption is standing over something nobody argued for:',
        ...$broken,
    ]));
});

it('sees a cutoff asked of the database, and does not mistake a column formatter for one', function (): void {
    // Without this the walk above could match nothing and the guard would
    // report a clean tree it never read.
    $planted = tempnam(sys_get_temp_dir(), 'db-clock').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedDatabaseClockRead
        {
            public function recent(Builder $query): void
            {
                $query->whereRaw("transactions.created_at >= datetime('now', '-365 days')");
            }
        }
        PHP);

    $defaulted = tempnam(sys_get_temp_dir(), 'db-clock-default').'.php';
    file_put_contents($defaulted, <<<'PHP'
        <?php
        final class PlantedDatabaseClockDefault
        {
            public function up(): void
            {
                $this->db()->statement(<<<'SQL'
                    CREATE TABLE t (updated_at DATETIME NOT NULL DEFAULT (datetime('now')))
                    SQL);
            }
        }
        PHP);

    $clean = tempnam(sys_get_temp_dir(), 'db-clock-clean').'.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedAppClockRead
        {
            public function recent(Builder $query, Clock $clock): void
            {
                $query->where('transactions.created_at', '>=', RetentionWindow::cutoff($clock));
            }
        }
        PHP);

    $formatter = tempnam(sys_get_temp_dir(), 'db-clock-format').'.php';
    file_put_contents($formatter, <<<'PHP'
        <?php
        final class PlantedColumnFormatter
        {
            public function byYear(Builder $query): void
            {
                $query->selectRaw("CAST(strftime('%Y', posted_at) AS INTEGER) as year");
            }
        }
        PHP);

    try {
        $found = databaseClockAsks([$planted, $defaulted, $clean, $formatter]);
    } finally {
        @unlink($planted);
        @unlink($defaulted);
        @unlink($clean);
        @unlink($formatter);
    }

    $names = array_map(static fn (array $ask): string => basename($ask['file']), $found);

    expect($names)->toHaveCount(2, 'The reader either missed a cutoff asked of the database or read a column formatter as one.');
    expect($names)->toContain(basename($planted));
    expect($names)->toContain(basename($defaulted));
    expect(array_column($found, 'ask'))->toBe(
        ["datetime('now'", "datetime('now'"],
        'A pin names the ask it excuses, so the ask has to come back as its own text.',
    );
});

/**
 * The calls that move an instant from one zone into another. Each is a decision
 * about which frame a value is in, and that decision belongs in one class.
 *
 * @return list<string>
 */
function reZoningCalls(): array
{
    return ['setTimezone', 'shiftTimezone', 'utc'];
}

/**
 * How many times each file re-zones an instant, rather than merely whether it
 * does. A pin carrying only a path excuses every later conversion written into
 * the same class, and the seam is the one file most likely to grow one.
 *
 * @param  list<string>  $paths
 * @return array<string, int> relative path => the re-zoning calls it makes
 */
function reZonedInstantSites(array $paths): array
{
    $calls = reZoningCalls();
    $sites = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $calls, true)) {
                continue;
            }

            $caller = $tokens[$index - 1] ?? null;
            if (is_array($caller) && in_array($caller[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $sites[$relative] = ($sites[$relative] ?? 0) + 1;
            }
        }
    }

    ksort($sites);

    return $sites;
}

// Shrinks only. Each entry states why it moves an instant itself, and pins how
// many times: a third conversion inside the seam is a decision nobody reviewed,
// and a path alone would have carried it.
const RE_ZONING_PINS = [
    // The seam. It owns both directions: to UTC for a Zulu label, to the app
    // zone for a DATETIME column.
    'Modules/Core/Public/Support/Instant.php' => 2,
    // A fixture rebaser, not a reader of stored data: it recomputes a CAMT
    // export's own printed offset when it shifts the file's dates, and leaves
    // any offset that zone does not explain alone.
    'app/Fixtures/Camt053Rebaser.php' => 2,
];

it('decides which frame an instant is in only inside the seam', function (): void {
    $files = BackendSourceFiles::all();

    // Far under the thousands the tree holds, so a walk that opened nothing
    // fails here rather than reporting a tree that re-zones nowhere.
    expect(count($files))->toBeGreaterThan(
        1000,
        'The walk opened '.count($files).' backend files, which is too few to have read the tree at all.',
    );

    $expected = RE_ZONING_PINS;
    ksort($expected);

    expect(reZonedInstantSites($files))->toBe(
        $expected,
        "Re-zoning an instant beside a query is how a cutoff ends up in a frame\n".
        "its column was never written in. Two readers converted their dedup\n".
        "cutoff to UTC under a comment about a CURRENT_TIMESTAMP default that the\n".
        "model had stopped using, and the window ran at 3h. A pinned file that has\n".
        'grown a conversion fails here too, because the count is pinned and not the path.',
    );
});

it('sees an instant re-zoned outside the seam, and a plain clock read left alone', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 're-zone').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedReZonedCutoff
        {
            public function recent(): bool
            {
                $cutoff = $this->clock->now()->subHour()->setTimezone('UTC');

                return $this->db->connection()->table('system_alerts')
                    ->where('created_at', '>=', $cutoff)
                    ->exists();
            }
        }
        PHP);

    $clean = tempnam(sys_get_temp_dir(), 're-zone-clean').'.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedAppFrameCutoff
        {
            public function recent(): bool
            {
                $cutoff = Instant::appLocal($this->clock->now()->subHour());

                return $this->db->connection()->table('system_alerts')
                    ->where('created_at', '>=', $cutoff)
                    ->exists();
            }
        }
        PHP);

    try {
        $found = reZonedInstantSites([$planted, $clean]);
    } finally {
        @unlink($planted);
        @unlink($clean);
    }

    expect(array_map(static fn (string $path): string => basename($path), array_keys($found)))
        ->toBe([basename($planted)], 'The reader either missed a re-zoned cutoff or read a plain clock read as one.');
    expect(array_values($found))->toBe([1], 'A pin names how many conversions it excuses, so the count has to be the file\'s own.');
});
