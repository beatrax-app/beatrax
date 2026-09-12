<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Database\Migrations\Migrator;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
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
    public function __construct(private Migrator $migrator) {}

    /**
     * @throws BackupFromAnotherBuildException when the build cannot read this backup's schema
     * @throws BackupIoException when the file cannot be opened as a database
     */
    public function assertThisBuildCanRead(string $snapshotPath): void
    {
        $carried = $this->recordedIn($snapshotPath);

        // A file recording none makes no claim to check. That is a fixture, a
        // hand-built database and every backup written before migrations were
        // tracked; refusing them would strand a file over a question it never
        // answered.
        if ($carried === []) {
            return;
        }

        $here = $this->recordedHere();

        $ahead = array_values(array_diff($carried, $here));
        if ($ahead !== []) {
            throw BackupFromAnotherBuildException::newer($ahead);
        }

        $behind = array_values(array_diff($here, $carried));
        if ($behind !== []) {
            throw BackupFromAnotherBuildException::older($behind);
        }
    }

    // Every migration this build ships, by the same name the runner records:
    // the module paths each service provider registered, plus the shared root
    // the modules config names.
    /**
     * @return list<string>
     */
    private function recordedHere(): array
    {
        $paths = [...$this->migrator->paths(), UserDataPathService::migrationsPath()];

        /** @var list<string> $names */
        $names = array_keys($this->migrator->getMigrationFiles($paths));

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
