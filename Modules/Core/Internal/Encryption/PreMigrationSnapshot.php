<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Encryption;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Grammars\Grammar;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\RowChunk;
use Modules\Core\Public\Support\SecretFileMode;

final readonly class PreMigrationSnapshot
{
    // The same sensitive-column map the migration's projection re-encrypt pass
    // consumes; the op-log pass uses the field registry as its own source.
    public const array PROJECTION_COLUMNS = [
        'transactions' => ['note', 'description', 'counterparty_name', 'counterparty_iban', 'raw_payload'],
        'counterparties' => ['display_name', 'merchant_name', 'iban'],
        'tax_transaction_tags' => ['note'],
        'transaction_splits' => ['note'],
        // Every registered notification column. Absent from this list the
        // enable-time sweep skipped the table entirely, so a user who had
        // notifications before enabling kept their titles and bodies readable
        // on disk while the interface said encryption was on.
        'notifications' => ['title', 'body', 'params', 'trigger_type'],
    ];

    // The pre-migration state of an op-log row is its plaintext value under no
    // epoch at all, so the epoch column is captured beside the value and the
    // restore is driven entirely by what the file holds.
    private const array OP_LOG_COLUMNS = ['value', 'gdk_epoch'];

    private const int READ_CHUNK_SIZE = RowChunk::DEFAULT_SIZE;

    private const int RESTORE_BUFFER_ROWS = RowChunk::DEFAULT_SIZE;

    // A build compiled before SQLite's parameter ceiling was raised to 32766
    // stops at 999, so a batched write sizes itself to stay under the lower
    // number and remains one statement on every build.
    private const int MAX_BINDINGS_PER_STATEMENT = 900;

    public function __construct(
        private FileEncryptor $backupEncryptor,
        private Clock $clock,
    ) {}

    // A targeted sensitive-column snapshot, not a whole-file SQLite copy: only
    // the values the migration is about to rewrite need a safety net.
    public function takeSnapshot(int $userId, ConnectionInterface $connection, string $kek): string
    {
        $dir = UserDataPathService::appPath('sync/backups');
        @mkdir($dir, SecretFileMode::DIRECTORY, true);

        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_premig_'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->streamSnapshot($tmpPlainPath, $userId, $connection);

            $encPath = UserDataPathService::appPath(
                sprintf('sync/backups/pre-encryption-%d-%s.enc', $userId, $this->clock->now()->format('YmdHis_u')),
            );

            // The KEK is already a uniformly random data key, so the
            // passphrase path's Argon2 stretch buys nothing and costs ~500ms
            // on a device mid-migration. restoreFromSnapshot() still reads
            // through decrypt(), which takes its cost from the header.
            $this->backupEncryptor->encryptWithKey($tmpPlainPath, $encPath, $kek);

            return $encPath;
        } finally {
            @unlink($tmpPlainPath);
        }
    }

    // One JSON line per row, appended as each chunk is read: a ledger the size
    // of a life never becomes one array and one encoded string held in memory
    // at the same time, which is what exhausts a phone.
    private function streamSnapshot(string $path, int $userId, ConnectionInterface $connection): void
    {
        $handle = $this->openStagedPayload($path);

        try {
            $connection->table('op_log_entries')
                ->where('user_id', $userId)
                ->whereNull('gdk_epoch')
                ->whereNotNull('value')
                ->select(['id', 'value', 'gdk_epoch'])
                ->orderBy('id')
                ->chunkById(self::READ_CHUNK_SIZE, function ($rows) use ($handle): void {
                    foreach ($rows as $row) {
                        $this->appendRow($handle, 'op_log_entries', (array) $row);
                    }
                }, 'id');

            // Driven off PROJECTION_COLUMNS rather than a second literal list:
            // a table on one list and not the other is how notifications came to
            // be registered as sensitive and never swept.
            foreach (self::PROJECTION_COLUMNS as $table => $columns) {
                $connection->table($table)
                    ->where('user_id', $userId)
                    ->select(array_merge(['id'], $columns))
                    ->orderBy('id')
                    ->chunkById(self::READ_CHUNK_SIZE, function ($rows) use ($handle, $table): void {
                        foreach ($rows as $row) {
                            $this->appendRow($handle, $table, (array) $row);
                        }
                    }, 'id');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function openStagedPayload(string $path)
    {
        // Suppressed so the `=== false` check decides; unsuppressed the
        // E_WARNING becomes an ErrorException first and the guard never ran.
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new BackupIoException('Failed to stage the pre-migration snapshot payload.');
        }

        // Locked down before the first plaintext byte reaches it, not after the
        // whole payload is on disk.
        if (! @chmod($path, SecretFileMode::FILE)) {
            fclose($handle);
            throw new BackupIoException('Cannot chmod the pre-migration snapshot payload to 0600.');
        }

        @flock($handle, LOCK_EX);

        return $handle;
    }

    /**
     * @param  resource  $handle
     * @param  array<array-key, mixed>  $row
     */
    private function appendRow($handle, string $table, array $row): void
    {
        $line = json_encode([$table, $row], JSON_THROW_ON_ERROR)."\n";

        if (@fwrite($handle, $line) !== strlen($line)) {
            throw new BackupIoException('Failed to stage the pre-migration snapshot payload.');
        }
    }

    public function restoreFromSnapshot(string $snapshotPath, string $kek, ConnectionInterface $connection): void
    {
        $dir = dirname($snapshotPath);
        $tmpPlainPath = $dir.DIRECTORY_SEPARATOR.'beatrax_premig_restore_'.bin2hex(random_bytes(8)).'.tmp';

        try {
            $this->backupEncryptor->decrypt($snapshotPath, $tmpPlainPath, $kek);

            // Narrowed before the first plaintext byte is read back out: the
            // encryptor renames its own staging file into place at the process
            // umask default, and this file holds every sensitive column the
            // migration is about to rewrite. Unreadable beats world-readable.
            $handle = @chmod($tmpPlainPath, SecretFileMode::FILE)
                ? @fopen($tmpPlainPath, 'rb')
                : false;

            // Unreadable snapshot: the rollback already reverted every DB write, so
            // there is nothing to repair. Do not throw — the ORIGINAL failure is what
            // the caller must surface.
            if ($handle === false) {
                return;
            }

            try {
                // One transaction so the restore is all-or-nothing; a throw partway would
                // otherwise leave a half-restored mix of plaintext and ciphertext. Only
                // ever invoked on a genuine rollback, so it never contradicts a commit.
                $connection->transaction(function () use ($connection, $handle): void {
                    $this->replaySnapshot($connection, $handle);
                });
            } finally {
                fclose($handle);
            }
        } finally {
            @unlink($tmpPlainPath);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function replaySnapshot(ConnectionInterface $connection, $handle): void
    {
        /** @var list<array<string, mixed>> $buffered */
        $buffered = [];
        $buffering = null;

        while (($line = fgets($handle)) !== false) {
            $entry = $this->decodeRow($line);
            if ($entry === null) {
                continue;
            }

            [$table, $row] = $entry;

            // Flushed on the table changing as well as on the row count, so the
            // reader holds one bounded run and never one partial buffer per
            // table it has passed.
            if ($buffering !== null && $buffering !== $table) {
                self::writeRowsById($connection, $buffering, $buffered);
                $buffered = [];
            }

            $buffering = $table;
            $buffered[] = $row;

            if (count($buffered) >= self::RESTORE_BUFFER_ROWS) {
                self::writeRowsById($connection, $table, $buffered);
                $buffered = [];
            }
        }

        if ($buffering !== null) {
            self::writeRowsById($connection, $buffering, $buffered);
        }
    }

    // A line names its own table and carries only columns this snapshot is
    // allowed to write back, so a payload that decrypts to something other than
    // what takeSnapshot() wrote can never reach an unrelated column.
    /**
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function decodeRow(string $line): ?array
    {
        $decoded = json_decode(trim($line), true);
        if (! is_array($decoded)) {
            return null;
        }

        $table = $decoded[0] ?? null;
        $row = $decoded[1] ?? null;
        if (! is_string($table) || ! is_array($row)) {
            return null;
        }

        $restored = self::projectRestorableColumns($table, $row);

        return $restored === null ? null : [$table, $restored];
    }

    // Nothing but the id survives on its own: a line whose every other column
    // was dropped by the filter above restores an id over a live row and
    // blanks nothing, which is a write with no content and no reason.
    /**
     * @param  array<mixed, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function projectRestorableColumns(string $table, array $row): ?array
    {
        $id = $row['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $restored = ['id' => $id];
        foreach (self::restorableColumns($table) as $column) {
            if (array_key_exists($column, $row)) {
                $restored[$column] = $row[$column];
            }
        }

        return count($restored) > 1 ? $restored : null;
    }

    /**
     * @return list<string>
     */
    private static function restorableColumns(string $table): array
    {
        return $table === 'op_log_entries'
            ? self::OP_LOG_COLUMNS
            : (self::PROJECTION_COLUMNS[$table] ?? []);
    }

    // One statement per batch of rows rather than one per row: both this
    // restore and the enable-time sweep write inside a single transaction, and
    // a statement per ledger line holds the one SQLite writer lock for the
    // whole pass.
    /**
     * @param  list<array<string, mixed>>  $rows  each carrying an `id` alongside the columns to write
     */
    public static function writeRowsById(ConnectionInterface $connection, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $grammar = $connection->table($table)->getGrammar();

        $batch = [];
        $bindings = 0;

        foreach ($rows as $row) {
            if (! isset($row['id']) || count($row) < 2) {
                continue;
            }

            $cost = 2 * (count($row) - 1) + 1;
            if ($batch !== [] && $bindings + $cost > self::MAX_BINDINGS_PER_STATEMENT) {
                self::writeBatch($connection, $grammar, $table, $batch);
                $batch = [];
                $bindings = 0;
            }

            $batch[] = $row;
            $bindings += $cost;
        }

        if ($batch !== []) {
            self::writeBatch($connection, $grammar, $table, $batch);
        }
    }

    // Every column a row does not carry falls through to `else <column>`, so a
    // batch of rows updating different column sets still writes in one
    // statement and no column outside the batch's own keys is disturbed.
    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private static function writeBatch(ConnectionInterface $connection, Grammar $grammar, string $table, array $batch): void
    {
        $wrappedId = $grammar->wrap('id');

        /** @var array<string, list<mixed>> $cases */
        $cases = [];
        $ids = [];

        foreach ($batch as $row) {
            $id = $row['id'];
            $ids[] = $id;

            foreach ($row as $column => $value) {
                if ($column === 'id') {
                    continue;
                }

                $cases[$column][] = $id;
                $cases[$column][] = $value;
            }
        }

        $assignments = [];
        $updateBindings = [];

        foreach ($cases as $column => $columnBindings) {
            $wrapped = $grammar->wrap($column);
            $whens = str_repeat(' when ? then ?', intdiv(count($columnBindings), 2));
            $assignments[] = "{$wrapped} = case {$wrappedId}{$whens} else {$wrapped} end";

            foreach ($columnBindings as $binding) {
                $updateBindings[] = $binding;
            }
        }

        if ($assignments === []) {
            return;
        }

        $connection->update(sprintf(
            'update %s set %s where %s in (%s)',
            $grammar->wrapTable($table),
            implode(', ', $assignments),
            $wrappedId,
            implode(', ', array_fill(0, count($ids), '?')),
        ), array_merge($updateBindings, $ids));
    }
}
