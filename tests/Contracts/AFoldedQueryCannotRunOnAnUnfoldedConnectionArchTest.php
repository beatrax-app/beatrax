<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Internal\Providers\AlreadyOpenConnectionsProvider;
use Modules\Core\Internal\Providers\UnicodeFoldingProvider;
use Modules\Core\Public\Support\UnicodeFolding;
use Pdo\Sqlite;
use Symfony\Component\Process\Process;
use Tests\Support\FoldedProbeJob;

// "Ignore case" is a SQLite user function here, and a user function lives on ONE
// connection. A connection nobody registered it on does not fold differently --
// it cannot run the query at all. That refusal is deliberate: a fallback to
// LOWER() would be the ASCII-only comparison this replaced, returning fewer rows
// with no signal. Which makes the registration the whole risk, and this file the
// thing that keeps it from being one connection short.
//
// The enumeration is against the symbol: ConnectionEstablished is dispatched
// from four places in Illuminate\Database\DatabaseManager -- connection(),
// connectUsing()/build(), reconnect(), and the reconnector a lost connection
// calls back into -- and AlreadyOpenConnectionsProvider replays it over anything
// opened before the listener was attached. Every case below is one of those.
//
// The one PDO none of this reaches is Modules/DevMode/Internal/Sql/
// IsolatedSelectProcess, which opens the database file in a child process
// outside the framework to run one developer-typed SELECT under a timeout. It
// carries the same exemption there that PRAGMA busy_timeout has, for the same
// reason, and a folded query typed into that panel is refused by name.

/**
 * @link ../../.docs/architecture/case-folding-is-one-function.md
 */
function foldedProbeNeedle(): string
{
    return 'MÖRK ΑΘΗΝΑ ЩУКА';
}

function foldedProbeExpected(): string
{
    return 'mörk αθηνα щука';
}

function foldedProbeAnswer(Connection $connection): mixed
{
    $row = $connection->selectOne(
        'select '.UnicodeFolding::sql('?').' as folded',
        [foldedProbeNeedle()],
    );

    return $row instanceof stdClass ? $row->folded ?? null : null;
}

/**
 * @return array<string, mixed>
 */
function foldedProbeConfig(): array
{
    return [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ];
}

// The subject is SQL, and a comment naming the spelling it warns against is not
// SQL -- this file's own prose would otherwise be the first offender. Only whole
// comment lines go; a LOWER( parked after code on a line stays visible, which
// errs towards reporting.
function foldedProbeWithoutCommentLines(string $source): string
{
    $kept = [];

    foreach (explode("\n", $source) as $line) {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        $kept[] = $line;
    }

    return implode("\n", $kept);
}

// One walk, several needles: reading the tree three times to answer three
// questions costs three times as much and says the same thing.
/**
 * @param  array<string, string>  $needles
 * @return array{walked: int, hits: array<string, list<string>>}
 */
function foldedProbeScan(array $needles): array
{
    $walked = 0;
    $hits = array_fill_keys(array_keys($needles), []);

    foreach (['Modules', 'bootstrap', 'config', 'database', 'routes', 'scripts'] as $root) {
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
            $source = foldedProbeWithoutCommentLines((string) file_get_contents($path));

            foreach ($needles as $label => $needle) {
                if (str_contains($source, $needle)) {
                    $hits[$label][] = str_replace(base_path().'/', '', $path);
                }
            }
        }
    }

    foreach ($hits as $label => $found) {
        sort($found);
        $hits[$label] = $found;
    }

    return ['walked' => $walked, 'hits' => $hits];
}

it('answers on the connection the application hands out', function (): void {
    expect(foldedProbeAnswer(DB::connection()))->toBe(foldedProbeExpected());
});

// Enumerated from configuration rather than named here, so a connection added to
// config/database.php is a connection this case starts checking. The pinned list
// is the second half: a new entry has to be looked at, not silently covered.
it('answers on every connection config/database.php configures', function (): void {
    /** @var array<string, mixed> $connections */
    $connections = (array) config('database.connections');
    $checked = [];

    foreach ($connections as $name => $configured) {
        // mysql/mariadb/pgsql/sqlsrv are nulled out -- SQLite is the only engine.
        if (! is_array($configured)) {
            continue;
        }

        // Redirected at :memory: so enumerating the shapes never opens, WALs or
        // creates the reader's own ledger file. This is also the connectUsing()
        // / build() dispatch site.
        $built = DB::build([...$configured, 'database' => ':memory:', 'name' => 'folded_probe_'.$name]);

        expect($built)->toBeInstanceOf(Connection::class);
        /** @var Connection $built */
        expect(foldedProbeAnswer($built))->toBe(foldedProbeExpected(), 'connection ['.$name.'] folds nothing.');

        $built->disconnect();
        DB::purge('folded_probe_'.$name);
        $checked[] = (string) $name;
    }

    expect($checked)->toBe(['sqlite', 'sqlite_testing', 'readonly_select']);
});

it('answers on a connection DatabaseManager::reconnect() re-made', function (): void {
    config()->set('database.connections.folded_probe', foldedProbeConfig());

    expect(foldedProbeAnswer(DB::connection('folded_probe')))->toBe(foldedProbeExpected());

    $again = DB::reconnect('folded_probe');

    expect($again)->toBeInstanceOf(Connection::class);
    /** @var Connection $again */
    expect(foldedProbeAnswer($again))->toBe(foldedProbeExpected());

    DB::purge('folded_probe');
});

// The path a dropped connection takes on its own: Connection::run() calls
// reconnectIfMissingConnection() before every statement, which goes back through
// the manager's reconnector. Nothing in the calling code knows it happened.
it('answers on a connection that lost its PDO and re-opened inside the query', function (): void {
    config()->set('database.connections.folded_probe_lost', foldedProbeConfig());

    $connection = DB::connection('folded_probe_lost');
    $connection->disconnect();

    expect(foldedProbeAnswer($connection))->toBe(foldedProbeExpected());

    DB::purge('folded_probe_lost');
});

// The desktop's shape: NativePHP's provider opens its connection inside its own
// boot(), before this module attaches anything, and a resolved connection is
// cached so the event never fires for it again. Standing in for that here by
// putting a PDO nobody registered anything on under an open connection.
it('answers on a connection that was already open before the listener existed', function (): void {
    config()->set('database.connections.folded_probe_open', foldedProbeConfig());

    $connection = DB::connection('folded_probe_open');
    $connection->setPdo(new Sqlite('sqlite::memory:'));

    // The negative control: without it a green line below would prove only that
    // the connection had never stopped carrying the function.
    expect(fn (): mixed => foldedProbeAnswer($connection))->toThrow(QueryException::class);

    (new AlreadyOpenConnectionsProvider($this->app))->boot(app(Dispatcher::class), app(DatabaseManager::class));

    expect(foldedProbeAnswer($connection))->toBe(foldedProbeExpected());

    DB::purge('folded_probe_open');
});

// The replay cannot reach a connection inside a transaction -- it re-dispatches
// the event that writes PRAGMA journal_mode, which SQLite refuses there -- so
// the folding provider walks what is open for itself as well.
it('answers on an already-open connection the replay has to skip', function (): void {
    config()->set('database.connections.folded_probe_held', foldedProbeConfig());

    $connection = DB::connection('folded_probe_held');
    $connection->setPdo(new Sqlite('sqlite::memory:'));
    $connection->beginTransaction();

    (new AlreadyOpenConnectionsProvider($this->app))->boot(app(Dispatcher::class), app(DatabaseManager::class));

    expect(fn (): mixed => foldedProbeAnswer($connection))
        ->toThrow(QueryException::class, 'no such function');

    (new UnicodeFoldingProvider($this->app))->boot(app(Dispatcher::class), app(DatabaseManager::class));

    expect(foldedProbeAnswer($connection))->toBe(foldedProbeExpected());

    $connection->rollBack();
    DB::purge('folded_probe_held');
});

// A worker resolves its own connection, per job, in a process that never served
// a request. Pushed onto the queue rather than dispatched, because the database
// queue is after_commit and the suite is inside a transaction that never commits.
it('answers inside a job the queue worker ran', function (): void {
    FoldedProbeJob::$folded = null;

    Queue::connection('database')->push(new FoldedProbeJob);

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(0, 'The job failed, so what it folded says nothing.')
        ->and(FoldedProbeJob::$folded)->toBe(foldedProbeExpected());
});

// The console kernel, in a process of its own: the scheduler, every artisan
// command and the queue worker's own process boot through it. Pointed at
// :memory: so it touches no ledger.
it('answers in a second process that booted the console kernel', function (): void {
    $child = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $row = $app->make(Illuminate\Database\DatabaseManager::class)
            ->connection()
            ->selectOne('select beatrax_fold(?) as folded', [$argv[2]]);
        echo $row->folded;
        PHP;

    $process = new Process(
        [PHP_BINARY, '-r', $child, base_path(), foldedProbeNeedle()],
        base_path(),
        ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'],
    );
    $process->setTimeout(120.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe(foldedProbeExpected());
});

// The other half of "one rule": a second folding written beside this one would
// agree today and drift the first time either moved. SQLite's LOWER() and
// UPPER() fold ASCII and stop, so in SQL they are never the right answer to
// "ignore case" -- and they are the spelling the LIKE arm used to carry.
it('leaves no SQL in the tree that folds case any other way', function (): void {
    $scan = foldedProbeScan([
        'lower' => 'LOWER(',
        'upper' => 'UPPER(',
        'registrations' => 'UnicodeFolding::registerOn(',
        'control' => 'declare(strict_types=1);',
    ]);

    // The walk's own positive control. "No offenders" and "read nothing" look
    // the same from here otherwise.
    expect($scan['walked'])->toBeGreaterThan(2000)
        ->and(count($scan['hits']['control']))->toBeGreaterThan(2000);

    expect([...$scan['hits']['lower'], ...$scan['hits']['upper']])->toBe([]);

    expect($scan['hits']['registrations'])
        ->toBe(['Modules/Core/Internal/Providers/UnicodeFoldingProvider.php']);

    $provider = (string) file_get_contents(
        base_path('Modules/Core/Internal/Providers/UnicodeFoldingProvider.php'),
    );

    expect($provider)->toContain('ConnectionEstablished::class');
});
