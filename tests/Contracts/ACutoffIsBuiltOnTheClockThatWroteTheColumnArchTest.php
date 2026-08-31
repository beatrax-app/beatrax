<?php

declare(strict_types=1);

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
            if ($file->isFile() && str_ends_with($path, '.php') && str_contains(strtolower($path), 'migrations/')) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * @param  list<string>  $paths
 * @return list<string> one relative path per file that asks SQLite for the time
 */
function filesAskingTheDatabaseForTheTime(array $paths): array
{
    $askingForNow = "/(?:datetime|date|julianday|unixepoch|strftime)\s*\(\s*(?:'[^']*'\s*,\s*)?'now'/i";
    $currentTimestamp = '/\bCURRENT_TIMESTAMP\b/i';
    $stringTokens = [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
    $offenders = [];

    foreach ($paths as $path) {
        foreach (BackendSourceFiles::codeTokens($path) as $token) {
            if (! is_array($token) || ! in_array($token[0], $stringTokens, true)) {
                continue;
            }

            if (preg_match($askingForNow, $token[1]) === 1 || preg_match($currentTimestamp, $token[1]) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    sort($offenders);

    return array_values(array_unique($offenders));
}

it('builds every cutoff on the clock that wrote the column it is compared against', function (): void {
    $paths = filesThatCouldNameTheDatabaseClock();
    expect($paths)->not->toBeEmpty();

    // Shrinks only. Each entry states why naming the database clock is safe
    // there — which always means the value is never ordered or ranged.
    $pinned = [
        // A DEFAULT for a row the HLC writer stamps in the same statement it
        // inserts. Nothing orders or ranges hlc_clock_state.updated_at; it is
        // read back whole, per (user_id, device_id).
        'Modules/Sync/Database/Migrations/2026_06_15_000002_create_hlc_clock_state_table.php',
        // Same shape: sync_peer_catch_up_state.updated_at is a per-peer
        // bookmark read by primary key, never compared against a cutoff.
        'Modules/Sync/Database/Migrations/2026_08_27_000001_create_sync_peer_catch_up_state_table.php',
    ];

    expect(filesAskingTheDatabaseForTheTime($paths))->toBe(
        $pinned,
        "SQLite's datetime('now') is UTC; Clock::now() runs at APP_TIMEZONE.\n".
        "Comparing one against a column the other wrote moves the edge by the\n".
        "offset — a 365-day retention rule pruned two hours late, silently.\n".
        'Build the cutoff on the Clock. Files asking the database instead:',
    );
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
        $found = filesAskingTheDatabaseForTheTime([$planted, $defaulted, $clean, $formatter]);
    } finally {
        @unlink($planted);
        @unlink($defaulted);
        @unlink($clean);
        @unlink($formatter);
    }

    $names = array_map(static fn (string $path): string => basename($path), $found);

    expect($names)->toHaveCount(2);
    expect($names)->toContain(basename($planted));
    expect($names)->toContain(basename($defaulted));
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
 * @param  list<string>  $paths
 * @return list<string> one relative path per file that re-zones an instant
 */
function filesReZoningAnInstant(array $paths): array
{
    $calls = reZoningCalls();
    $offenders = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $calls, true)) {
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

    return array_values(array_unique($offenders));
}

it('decides which frame an instant is in only inside the seam', function (): void {
    $files = BackendSourceFiles::all();
    expect($files)->not->toBeEmpty();

    // Shrinks only. Each entry states why it moves an instant itself.
    $pinned = [
        // The seam. It owns both directions: to UTC for a Zulu label, to the
        // app zone for a DATETIME column.
        'Modules/Core/Public/Support/Instant.php',
        // A fixture rebaser, not a reader of stored data: it recomputes a
        // CAMT export's own printed offset when it shifts the file's dates,
        // and leaves any offset that zone does not explain alone.
        'app/Fixtures/Camt053Rebaser.php',
    ];

    expect(filesReZoningAnInstant($files))->toBe(
        $pinned,
        "Re-zoning an instant beside a query is how a cutoff ends up in a frame\n".
        "its column was never written in. Two readers converted their dedup\n".
        "cutoff to UTC under a comment about a CURRENT_TIMESTAMP default that the\n".
        'model had stopped using, and the window ran at 3h. Files re-zoning:',
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
        $found = filesReZoningAnInstant([$planted, $clean]);
    } finally {
        @unlink($planted);
        @unlink($clean);
    }

    expect(array_map(static fn (string $path): string => basename($path), $found))
        ->toBe([basename($planted)]);
});
