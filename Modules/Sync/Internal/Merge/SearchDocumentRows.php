<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;

// The rows a replay changed that a search document is built from, and the
// transaction each of them belongs to. A merge knows a table and a pk; the
// index is keyed by transaction and composed from more than one table, so
// neither the applier nor the writer can answer that on its own.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#the-tables-a-search-document-is-built-from
 */
final class SearchDocumentRows
{
    // Every table SearchIndexWriter::upsertForTransaction() reads to compose
    // one document, against the column of it naming the transaction that
    // document belongs to. Held to the writer's real composition by
    // ASearchDocumentIsRebuiltForEveryTableItReadsTest.
    private const array TRANSACTION_KEY = [
        'transactions' => 'id',
        'tax_transaction_tags' => 'transaction_id',
    ];

    /** @var list<int> */
    private array $touched = [];

    /** @var list<int> */
    private array $tombstoned = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @return list<string>
     */
    public static function sourceTables(): array
    {
        return array_keys(self::TRANSACTION_KEY);
    }

    public function rowWritten(string $table, int|string $pk, int $userId): void
    {
        foreach ($this->documentsOf($table, $pk, $userId) as $transactionId) {
            $this->touched[] = $transactionId;
        }
    }

    // The pair cascade reclassifies a transaction this replay never named,
    // which changes no indexed text but reaches the same index.
    public function transactionWritten(int $transactionId): void
    {
        $this->touched[] = $transactionId;
    }

    // Ask this BEFORE deleting the row. A row that is not the transaction
    // itself names one in a column, and once it is gone there is nothing left
    // to resolve it by — which is how an untag left the note in the index.
    /**
     * @return list<int>
     */
    public function documentsOf(string $table, int|string $pk, int $userId): array
    {
        $key = self::TRANSACTION_KEY[$table] ?? null;

        if ($key === null) {
            return [];
        }

        if ($this->isTheTransaction($table)) {
            return is_int($pk) ? [$pk] : [];
        }

        $named = $this->db->connection()
            ->table($table)
            ->where('id', $pk)
            ->where('user_id', $userId)
            ->value($key);

        return is_numeric($named) ? [(int) $named] : [];
    }

    // Deleting the transaction takes its document with it; deleting anything
    // else the document was composed from leaves the transaction behind, so
    // that document is rebuilt without the deleted row rather than dropped.
    /**
     * @param  list<int>  $documentIds  What documentsOf() answered while the row was still here.
     */
    public function rowDeleted(string $table, array $documentIds): void
    {
        foreach ($documentIds as $transactionId) {
            if ($this->isTheTransaction($table)) {
                $this->tombstoned[] = $transactionId;

                continue;
            }

            $this->touched[] = $transactionId;
        }
    }

    /**
     * @return list<int>
     */
    public function touched(): array
    {
        return array_values(array_unique($this->touched));
    }

    /**
     * @return list<int>
     */
    public function tombstoned(): array
    {
        return array_values(array_unique($this->tombstoned));
    }

    private function isTheTransaction(string $table): bool
    {
        return (self::TRANSACTION_KEY[$table] ?? null) === 'id';
    }
}
