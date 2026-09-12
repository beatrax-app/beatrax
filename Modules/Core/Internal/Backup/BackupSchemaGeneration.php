<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Internal\Support\MigrationWindow;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SqliteDatabase;
use PDO;
use PDOException;
use Throwable;

// A backup carries a database, and a database has a shape the code around it
// expects. Restored where that shape does not match, the swap succeeds, the
// integrity check passes, and the application then runs against a schema its
// own code does not match.
/**
 * @link ../../../../.docs/features/core/a-backup-from-another-build.md
 */
final readonly class BackupSchemaGeneration
{
    private const string CONNECTION = '_restore_forward';

    public function __construct(
        private Migrator $migrator,
        private Repository $config,
        private DatabaseManager $db,
        private MigrationWindow $window,
    ) {}

    // Takes a STAGED copy to this build's schema, never the live database: a
    // migration that fails part way is permanent on this store, so the file
    // this runs against has to be one a refusal can throw away.
    /**
     * @return int how many migrations it had to run
     *
     * @throws BackupFromANewerBuildException when the backup is ahead of this build
     * @throws BackupCouldNotBeBroughtUpToDateException when a migration fails
     * @throws BackupIoException when the file cannot be opened as a database
     */
    public function bringUpToDate(string $stagedPath): int
    {
        $carried = $this->recordedIn($stagedPath);

        // A file recording none makes no claim to check. That is a fixture, a
        // hand-built database and every backup written before migrations were
        // tracked; judging them would strand a file over a question it never
        // answered.
        if ($carried === []) {
            return 0;
        }

        $here = $this->recordedHere();

        // Migrations only move forward, so a backup naming one this build does
        // not have has no path to a shape this build reads. There is nothing
        // to run and nothing to offer.
        $ahead = array_values(array_diff($carried, $here));
        if ($ahead !== []) {
            throw new BackupFromANewerBuildException(
                'The backup was taken on a build carrying '.count($ahead).' schema changes this one does not have.',
                $ahead,
            );
        }

        $behind = array_values(array_diff($here, $carried));

        return $behind === [] ? 0 : $this->runForward($stagedPath, $behind);
    }

    /**
     * @param  list<string>  $behind
     *
     * @throws BackupCouldNotBeBroughtUpToDateException
     */
    private function runForward(string $stagedPath, array $behind): int
    {
        // The live connection's own settings with the path swapped, so the
        // migrations meet the foreign-key, journal and locking behaviour they
        // would meet on a real run rather than a laxer copy of it.
        $this->config->set('database.connections.'.self::CONNECTION, [
            ...$this->liveSettings(),
            'database' => $stagedPath,
        ]);
        $this->db->purge(self::CONNECTION);

        try {
            $this->migrator->usingConnection(self::CONNECTION, function (): void {
                $this->migrator->run($this->paths());
            });
        } catch (Throwable $e) {
            throw new BackupCouldNotBeBroughtUpToDateException(
                'The backup could not be brought to this build\'s schema: '.$e::class,
                count($behind),
                $e,
            );
        } finally {
            $this->settle($stagedPath);
        }

        return count($behind);
    }

    // A WAL beside the staged file is pages the swap would not read, and the
    // swap opens it read-only. Checkpointed and the sidecars dropped while a
    // connection still exists to do it.
    private function settle(string $stagedPath): void
    {
        // Migrator fires MigrationsEnded only where the loop finished, so a
        // run that threw leaves the window open on a request that carries on
        // serving — and the listener it gates stops invalidating on writes.
        $this->window->close();

        try {
            $this->db->connection(self::CONNECTION)->statement('PRAGMA journal_mode = DELETE');
        } catch (Throwable) {
            // A staged file the forward run has already abandoned. The caller
            // is about to discard it either way.
        } finally {
            $this->db->purge(self::CONNECTION);
            $this->config->set('database.connections.'.self::CONNECTION, null);
        }

        clearstatcache(true, $stagedPath);
    }

    // Refused rather than defaulted. A bare `['driver' => 'sqlite']` is the
    // laxer copy this exists to avoid: no foreign keys, no busy timeout, a
    // different locking mode — a run whose result nobody measured.
    /**
     * @return array<mixed> whatever the live connection is configured with
     *
     * @throws BackupNotSupportedException when it is configured with nothing
     */
    private function liveSettings(): array
    {
        $name = SqliteDatabase::connectionName($this->config);
        $live = $this->config->get('database.connections.'.$name);

        if (! is_array($live)) {
            throw new BackupNotSupportedException('There is no database connection called '.$name.' to bring a backup forward against.');
        }

        return $live;
    }

    // The same set the phone's first launch replays, spelled the same way:
    // every path a module registered, plus the shared root the framework only
    // adds inside its own migrate command.
    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_values(array_unique([
            ...$this->migrator->paths(),
            UserDataPathService::migrationsPath(),
        ]));
    }

    // Every migration this build ships, by the same name the runner records:
    // the module paths each service provider registered, plus the shared root
    // the modules config names.
    /**
     * @return list<string>
     */
    private function recordedHere(): array
    {
        /** @var list<string> $names */
        $names = array_keys($this->migrator->getMigrationFiles($this->paths()));

        return $names;
    }

    // Read with a fresh PDO rather than a framework connection: this file is
    // not the live database, and a named connection pointed at it would be
    // reconfigured by the listeners that own the live one.
    /**
     * @return list<string>
     *
     * @throws BackupIoException
     */
    private function recordedIn(string $snapshotPath): array
    {
        $pdo = $this->open($snapshotPath);

        try {
            return $this->namesIn($pdo);
        } catch (Throwable) {
            // A file whose migrations table will not read is a file this cannot
            // judge. The integrity check ahead of it is what refuses a damaged
            // database; answering "no claim" leaves that the one refusal rather
            // than adding a second, vaguer one beside it.
            return [];
        }
    }

    /**
     * @throws BackupIoException
     */
    private function open(string $snapshotPath): PDO
    {
        try {
            return new PDO('sqlite:'.$snapshotPath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new BackupIoException('Cannot open the backup to read the schema it was taken at: '.$snapshotPath, 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    private function namesIn(PDO $pdo): array
    {
        if (! $this->tracksMigrations($pdo)) {
            return [];
        }

        $rows = $pdo->query('SELECT migration FROM migrations');

        /** @var list<string> $names */
        $names = $rows === false ? [] : $rows->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    private function tracksMigrations(PDO $pdo): bool
    {
        $table = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'migrations'");

        if ($table === false) {
            return false;
        }

        $table->execute();

        return $table->fetchColumn() !== false;
    }
}
