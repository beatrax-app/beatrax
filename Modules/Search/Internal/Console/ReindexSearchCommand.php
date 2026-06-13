<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

/**
 * Rebuilds the entire FTS5 full-text search index from the transactions table.
 *
 * Use when the index is stale (doctor probe reports divergent row counts) or
 * after a bulk data migration. Chunked in batches of 500 to avoid OOM on
 * multi-year datasets (D-24).
 *
 * Recovery path: this command is the designed recovery tool for any desync
 * between the transactions table and transaction_search_docs / transaction_search_fts.
 * The index writer (SearchIndexWriter) is the synchronous path for normal writes.
 *
 * Process:
 *   1. Truncate transaction_search_docs and delete-all the FTS index.
 *   2. Chunk through all transactions, build a denormalized search_body per row
 *      (counterparty_name + chr(12) + description + chr(12) + tax note if any).
 *   3. Insert docs in batches.
 *   4. Run FTS 'rebuild' to atomically reconstruct the inverted index.
 */
final class ReindexSearchCommand extends Command
{
    /** @var string */
    protected $signature = 'search:reindex {--force : Skip the confirmation prompt.}';

    /** @var string */
    protected $description = 'Rebuild the FTS5 full-text search index from all transactions.';

    public function __construct(
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->db->connection();

        // Step 1: Clear existing docs and FTS entries in one transaction.
        // Note: truncate() fails on SQLite tables without sqlite_sequence (no AUTOINCREMENT).
        // Using DELETE instead, which is equivalent for our purpose and works reliably.
        $connection->transaction(function () use ($connection): void {
            $connection->table('transaction_search_docs')->delete();
            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('delete-all')",
            );
        });

        // Step 2: Stream transactions in batches, build search_body for each.
        $total = $connection->table('transactions')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $indexed = 0;

        $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $bar, &$indexed): void {
                // Fetch tax notes for this batch in one query.
                $ids = $rows->pluck('id')->all();

                /** @var array<int, string> $notesByTxId */
                $notesByTxId = $connection
                    ->table('tax_transaction_tags')
                    ->select(['transaction_id', 'note'])
                    ->whereIn('transaction_id', $ids)
                    ->get()
                    ->keyBy('transaction_id')
                    ->map(fn ($row) => is_string($row->note) ? $row->note : '')
                    ->all();

                // Build doc rows.
                $docs = [];
                foreach ($rows as $row) {
                    $txId = is_numeric($row->id) ? (int) $row->id : 0;
                    $userId = is_numeric($row->user_id) ? (int) $row->user_id : 0;
                    $counterparty = is_string($row->counterparty_name) ? $row->counterparty_name : '';
                    $description = is_string($row->description) ? $row->description : '';
                    $note = $notesByTxId[$txId] ?? '';

                    $docs[] = [
                        'transaction_id' => $txId,
                        'user_id' => $userId,
                        'search_body' => $counterparty.chr(12).$description.chr(12).$note,
                    ];
                }

                if ($docs !== []) {
                    $connection->table('transaction_search_docs')->insert($docs);
                }

                $indexed += count($docs);
                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();

        // Step 3: Rebuild the FTS inverted index from the now-populated content table.
        $connection->statement(
            "INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')",
        );

        // WR-05: fail loudly on a partial run. If the number of indexed docs
        // does not match the transaction count (e.g. a chunk failed / the
        // process was interrupted mid-stream), the index is incomplete and
        // search would silently return partial results. Surface a warning and a
        // non-zero exit code so the operator (and CI / scheduler) notices.
        if ($indexed !== $total) {
            $this->warn(
                "FTS reindex incomplete: indexed {$indexed} of {$total} transactions. ".
                'The index may be partial — re-run search:reindex.',
            );

            return self::FAILURE;
        }

        $this->info("FTS index rebuilt. {$indexed} transactions indexed.");

        return self::SUCCESS;
    }
}
