<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/features/core/a-purged-connection-two-services-still-hold.md
 */

// A QueryExecuted listener is dispatched from Connection::logQuery(), which
// runs after the statement succeeded and OUTSIDE the try that turns a driver
// error into a QueryException. So it rides inside whatever transaction the
// caller opened, knows nothing about it, and anything it raises is reported as
// that caller's statement failing -- as a raw PDOException a `catch
// (QueryException)` does not hold.
//
// Measured on a Galaxy A51: the one listener there is bumped a cache
// generation, the phone's cache is the DATABASE store, and
// DatabaseStore::increment() opens a transaction of its own. The sync replay
// aborted whole, and nineteen rows were recorded as primary-key collisions
// they never had.
//
// Two halves hold the shape, and both are pinned here because neither is
// visible from the other: what rides every statement, and what may retire the
// connection the riders were built over.

/**
 * The listener classes a production provider wires to QueryExecuted, resolved
 * through the registering file's own imports.
 *
 * @return array<string, string> listener FQCN => the provider naming it
 */
function ridersOnEveryStatement(): array
{
    $found = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (! str_ends_with($path, 'Provider.php')) {
            continue;
        }

        $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        if (! str_contains($source, 'QueryExecuted')) {
            continue;
        }

        $imports = importsInProviderSource($source);

        // Both spellings a listener is wired by: the invokable/handle class
        // name on its own, and the [X::class, 'method'] array callable.
        $pattern = '/listen\(\s*QueryExecuted::class\s*,\s*\[?\s*([A-Za-z_][A-Za-z0-9_]*)::class/';

        foreach (PatternScan::all($pattern, $source)[1] as $short) {
            $found[$imports[$short] ?? $short] = basename($path);
        }
    }

    ksort($found);

    return $found;
}

/**
 * @return array<string, string> short name => FQCN
 */
function importsInProviderSource(string $source): array
{
    $imports = [];

    foreach (PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source)[1] as $imported) {
        $imports[substr($imported, (int) strrpos($imported, '\\') + 1)] = $imported;
    }

    return $imports;
}

/**
 * Every production call of `purge()` on a database manager, by file. Read from
 * the two roots the backend walk covers plus the mobile bootstrap, which is
 * wiring rather than domain code and is where the purge that cost a sync lives
 * -- a guard that could not see it would have passed the day it was written.
 *
 * @return array<string, list<array{receiver: string, argument: string}>> file, repo-relative => its purge calls
 */
function databaseConnectionPurges(): array
{
    $files = BackendSourceFiles::all();
    $mobileBootstrap = base_path('mobile-app/bootstrap/app.php');

    if (is_file($mobileBootstrap)) {
        $files[] = $mobileBootstrap;
    }

    $found = [];

    foreach ($files as $path) {
        $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        foreach (singleArgumentPurgeCalls($source) as $call) {
            $found[pathRelativeToRepoRoot($path)][] = $call;
        }
    }

    ksort($found);

    return $found;
}

/**
 * Calls of `purge()` taking ONE argument, which is the database-manager shape.
 * A declaration is not a call, and the account-scoped and device-registry
 * purges this app also has take two, so the arity does the telling rather than
 * a receiver expression -- the receiver is spelled four different ways across
 * the three call sites, and the one that cost a sync is reached through
 * `$app->make(...)`.
 *
 * @return list<array{receiver: string, argument: string}>
 */
function singleArgumentPurgeCalls(string $source): array
{
    $calls = [];

    foreach (PatternScan::allWithOffsets('/(?<![A-Za-z0-9_])purge\(/', $source)[0] as [, $offset]) {
        $before = substr($source, max(0, $offset - 60), min(60, $offset));

        if (PatternScan::matches('/function\s+$/', $before)) {
            continue;
        }

        $arguments = balancedArgumentList($source, $offset + strlen('purge('));

        if ($arguments === null || str_contains($arguments, ',')) {
            continue;
        }

        $calls[] = ['receiver' => trim($before), 'argument' => trim($arguments)];
    }

    return $calls;
}

/**
 * The text between an opening parenthesis and the one that closes it, so an
 * argument that is itself a call does not end the list early.
 */
function balancedArgumentList(string $source, int $from): ?string
{
    $depth = 1;
    $length = strlen($source);

    for ($i = $from; $i < $length; $i++) {
        $character = $source[$i];

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;

            if ($depth === 0) {
                return substr($source, $from, $i - $from);
            }
        }
    }

    return null;
}

function pathRelativeToRepoRoot(string $path): string
{
    return ltrim(str_replace(base_path(), '', $path), '/');
}

/**
 * Why a purge does not have to retire anything built over the connection. The
 * only true reason is that nothing IS built over it: a name the app never
 * serves a request on, created and dropped inside one method.
 *
 * @var array<string, array{argument: string, reason: string}>
 */
const A_PURGE_NOTHING_WAS_BUILT_OVER = [
    'Modules/Core/Internal/Backup/BackupSchemaGeneration.php' => [
        'argument' => 'self::CONNECTION',
        'reason' => 'a private `_restore_forward` name, configured over a staged copy and purged inside the method that made it, so no migration run outlives its connection; no cache store, session or queue driver is ever resolved over it',
    ],
    'Modules/Core/Public/Services/RestoreEncryptedBackup.php' => [
        'argument' => '$connectionName',
        'reason' => 'a private `_restore_verify` name, configured and purged inside assertIntegrity() to run one PRAGMA against a candidate file; no cache store, session or queue driver is ever resolved over it',
    ],
];

it('keeps the set of listeners riding every statement to the ones written down', function (): void {
    $riders = ridersOnEveryStatement();

    expect($riders)->toBe([
        'Modules\Core\Internal\Listeners\ForgetNavCountsOnWrite' => 'CoreServiceProvider.php',
    ], implode("\n", [
        'A listener was wired to QueryExecuted, or the one that was there is gone.',
        '',
        'This is not an ordinary listener seam. QueryExecuted is dispatched from',
        'Connection::logQuery(), after the statement succeeded and outside the try',
        'that turns a driver error into a QueryException. A listener here therefore',
        'runs inside a transaction it cannot see, and whatever it raises is reported',
        'as somebody else\'s statement failing -- a raw PDOException that the merge,',
        'the importer and every other `catch (QueryException)` will not hold.',
        '',
        'A new one has to contain its own failures, and must not reach anything that',
        'opens a transaction of its own: on the phone the cache is the database store,',
        'and increment() is exactly that. Then add it here with the same reasoning.',
        '',
        'found: '.implode(', ', array_keys($riders)),
    ]));
});

it('lets nothing escape a listener that rides every statement', function (): void {
    $uncontained = [];

    foreach (array_keys(ridersOnEveryStatement()) as $listener) {
        $path = base_path(str_replace(['Modules\\', '\\'], ['Modules/', '/'], $listener).'.php');

        if (! is_file($path)) {
            $uncontained[] = $listener.' (no file at '.pathRelativeToRepoRoot($path).')';

            continue;
        }

        $source = (string) file_get_contents($path);

        // The narrow half a reader of the source can hold: a catch broad
        // enough for the PDOException that arrives from a nested BEGIN. That
        // the try covers every call the handler makes is not provable here,
        // and the behavioural half is in the Core and Sync tests this links.
        if (! PatternScan::matches('/catch\s*\(\s*\\\\?Throwable/', $source)) {
            $uncontained[] = $listener;
        }
    }

    expect($uncontained)->toBe([], implode("\n", [
        'A listener riding every statement can refuse the write it is only reading.',
        'It has to catch Throwable around everything it calls and report, never raise:',
        'a badge five minutes stale is the cost of a failure in badge invalidation,',
        'a refused merge is not.',
        '',
        ...$uncontained,
    ]));
});

it('retires what was built over a live connection in the same breath as purging it', function (): void {
    $purges = databaseConnectionPurges();

    // A walk that stopped reading would agree with an empty expectation, and
    // the mobile bootstrap is the call site that cost a sync.
    expect($purges)->toHaveKey('Modules/Core/Public/Services/LiveConnectionPurge.php');
    expect($purges)->toHaveKey('mobile-app/bootstrap/app.php');

    $bypassing = [];

    foreach ($purges as $file => $calls) {
        if ($file === 'Modules/Core/Public/Services/LiveConnectionPurge.php') {
            continue;
        }

        foreach ($calls as $call) {
            if (PatternScan::matches('/LiveConnectionPurge|\$this->purge->$/', $call['receiver'])) {
                continue;
            }

            $pin = A_PURGE_NOTHING_WAS_BUILT_OVER[$file] ?? null;

            if ($pin !== null && $pin['argument'] === $call['argument']) {
                continue;
            }

            $bypassing[] = $file.' purges '.$call['argument'];
        }
    }

    expect($bypassing)->toBe([], implode("\n", [
        'A database connection is purged somewhere other than LiveConnectionPurge.',
        '',
        'purge() reads as "close this connection" and does something narrower: it',
        'drops the Connection from the manager and out of nobody else. Every service',
        'that already resolved it keeps the object, and the framework then repairs',
        'that orphan by handing it the LIVE connection\'s PDO -- two Connection',
        'objects over one handle, the second counting transactions from zero.',
        '',
        'Route it through Modules\Core\Public\Services\LiveConnectionPurge, which',
        'retires the cache stores built over the connection as well. If nothing is',
        'built over the name being purged, pin it in A_PURGE_NOTHING_WAS_BUILT_OVER',
        'with the reason.',
        '',
        ...$bypassing,
    ]));
});

it('reads a wiring and a purge it is shown, and neither a comment nor a namesake', function (): void {
    $base = tempnam(sys_get_temp_dir(), 'planted-rider');
    $planted = $base.'Provider.php';

    file_put_contents($planted, <<<'PHP'
        <?php
        use Illuminate\Database\Events\QueryExecuted;
        use Modules\Planted\Internal\Listeners\PlantedRider;
        final class PlantedProvider
        {
            public function boot($dispatcher): void
            {
                $dispatcher->listen(QueryExecuted::class, PlantedRider::class);
                // $dispatcher->listen(QueryExecuted::class, PlantedCommented::class);
                $this->db->purge('planted_connection');
                $this->registry->purge($userId, $deviceId);
            }
        }
        PHP);

    try {
        $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($planted));
        $imports = importsInProviderSource($source);
        $wired = PatternScan::all('/listen\(\s*QueryExecuted::class\s*,\s*\[?\s*([A-Za-z_][A-Za-z0-9_]*)::class/', $source)[1];
        $purged = array_column(singleArgumentPurgeCalls($source), 'argument');
    } finally {
        @unlink($planted);
        @unlink($base);
    }

    expect(array_map(static fn (string $short): string => $imports[$short] ?? $short, $wired))
        ->toBe(['Modules\Planted\Internal\Listeners\PlantedRider'], 'the scan reads a wiring through its import and skips one left in a comment');

    expect($purged)->toBe(["'planted_connection'"], 'a purge on a device registry is not a purge of a database connection');
});
