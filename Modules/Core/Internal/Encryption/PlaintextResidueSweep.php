<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Encryption;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\RowChunk;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-rows-already-written-in-the-clear
 */
final readonly class PlaintextResidueSweep
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
    ) {}

    // What the sweep is able to reach, hashed. Stored beside the epoch pointer
    // and compared on every pass, so registering a column on an install that
    // already enabled encryption re-runs this once instead of never.
    public static function columnsDigest(): string
    {
        return substr(hash('sha256', json_encode(PreMigrationSnapshot::PROJECTION_COLUMNS, JSON_THROW_ON_ERROR)), 0, 32);
    }

    // Seals values still sitting in the clear in a registered column. Returns
    // the number of columns rewritten. The caller proves a key is reachable
    // first; without one every write here would refuse.
    public function run(int $userId, Session $session): int
    {
        $connection = $this->db->connection();
        $sealed = 0;

        foreach (PreMigrationSnapshot::PROJECTION_COLUMNS as $table => $columns) {
            $sealed += $this->sweepTable($connection, $table, $columns, $userId, $session);
        }

        return $sealed;
    }

    /**
     * @param  list<string>  $columns
     */
    private function sweepTable(
        ConnectionInterface $connection,
        string $table,
        array $columns,
        int $userId,
        Session $session,
    ): int {
        $sealed = 0;

        $connection->table($table)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(RowChunk::DEFAULT_SIZE, function ($rows) use ($connection, $table, $columns, $userId, $session, &$sealed): void {
                $writes = [];

                foreach ($rows as $row) {
                    $updates = $this->resealsForRow($table, $columns, $row, $userId, $session);

                    if ($updates !== []) {
                        $writes[] = ['id' => $row->id] + $updates;
                        $sealed += count($updates);
                    }
                }

                PreMigrationSnapshot::writeRowsById($connection, $table, $writes);
            }, 'id');

        return $sealed;
    }

    // A value is residue only when the codec hands it straight back: every
    // epoch in the keyring failed to open it AND it does not look like
    // ciphertext. The enable-time pass asks the current epoch alone, which
    // reads a correctly sealed pre-rotation value as plaintext and wraps it twice.
    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function resealsForRow(string $table, array $columns, stdClass $row, int $userId, Session $session): array
    {
        $updates = [];

        foreach ($columns as $column) {
            /** @var mixed $value */
            $value = $row->{$column} ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }

            $opened = $this->codec->decryptValue($table, $column, $value, $userId, $session);
            if ($opened['decrypted'] || $opened['value'] !== $value) {
                continue;
            }

            $updates[$column] = $this->codec->encryptValue($table, $column, $value, $userId, $session);
        }

        return $updates;
    }
}
