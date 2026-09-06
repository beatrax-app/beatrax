<?php

declare(strict_types=1);

// `PRAGMA busy_timeout` is CONNECTION-scoped and outlives the transaction that
// issues it. Sixteen write paths set it to 5000 inside a transaction, so any
// request or queue worker that ran a state-machine transition, an EmailScan
// action or the Open Banking callback spent the rest of its life at five
// seconds against the thirty config/database.php asks for.
//
// Measured: a fresh connection reads 30000; after one of those transactions
// commits it reads 5000 and stays there. ProjectForecastJob is one of the
// sixteen and is in failed_jobs with "database is locked".
//
// SqliteOptimizationsProvider is the one place that may set it on a connection
// Laravel opened, and it reads the configured value rather than naming a number
// -- its own comment records this same bug being fixed there once already.
//
// IsolatedSelectProcess is the one exception, and only because its PDO is NOT
// one of those connections: the dev SQL panel's cap runs the SELECT in a child
// process that opens the database file itself, outside the framework, so the
// ConnectionEstablished listener never fires for it and it would otherwise run
// at SQLite's default of no wait at all. It cannot lower the timeout for
// anything, because nothing else runs on that PDO -- the process exits with the
// query. It is still held to the same rule below: the value is passed in from
// the calling connection's config, never written as a number.

it('sets the busy timeout in exactly the two places that open their own PDO', function (): void {
    $offenders = [];
    $walked = 0;

    // Every root that ships PHP able to reach a connection, not Modules alone:
    // a scheduler script or a bootstrap file opening its own PDO would lower the
    // timeout for the process just as effectively, and the old walk could not
    // see one.
    foreach (['Modules', 'app', 'bootstrap', 'config', 'database', 'routes', 'scripts'] as $root) {
        $directory = base_path($root);

        if (! is_dir($directory)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $walked++;

            if (! str_contains((string) file_get_contents($path), 'PRAGMA busy_timeout')) {
                continue;
            }

            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($offenders);

    expect($walked)->toBeGreaterThan(2000, 'The walk read almost no PHP, so the pinned pair below would be missing for a reason that is not the tree.');

    expect($offenders)->toBe(
        [
            'Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php',
            'Modules/DevMode/Internal/Sql/IsolatedSelectProcess.php',
        ],
        'PRAGMA busy_timeout is connection-scoped: a second site lowers it for everything that runs after it '
        .'on the same connection. A new site is only allowed when it opens a PDO of its own that nothing else '
        ."shares, and it must still take the value from configuration.\n".implode("\n", $offenders),
    );
});

// A literal there would override the configured value, which is how it was cut
// to five seconds the first time.
it('takes that timeout from the configuration rather than a literal', function (): void {
    $provider = (string) file_get_contents(
        base_path('Modules/Core/Internal/Providers/SqliteOptimizationsProvider.php'),
    );

    expect($provider)->toContain("getConfig('busy_timeout')")
        ->and($provider)->toContain('PRAGMA busy_timeout = ')
        // The pragma is built from the variable the configured value was read
        // into, never written out as a number.
        ->and($provider)->not->toMatch('/PRAGMA busy_timeout = \d/');
});

// The child process reads no configuration of its own, so the guarantee has to
// hold across the two files: the caller looks the value up, the child only
// interpolates what it was handed.
it('hands the isolated query process a configured timeout rather than a literal', function (): void {
    $child = (string) file_get_contents(
        base_path('Modules/DevMode/Internal/Sql/IsolatedSelectProcess.php'),
    );
    $caller = (string) file_get_contents(
        base_path('Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php'),
    );

    expect($child)->toContain('PRAGMA busy_timeout = ')
        ->and($child)->not->toMatch('/PRAGMA busy_timeout = \d/')
        ->and($caller)->toContain("getConfig('busy_timeout')");
});
