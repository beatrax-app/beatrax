<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

// The designed recovery tool for any desync between transactions and
// transaction_search_docs/transaction_search_fts. Chunked in batches
// of 500 to avoid OOM on multi-year datasets. SearchIndexWriter is the
// synchronous path for normal writes; this is the full-rebuild path.
/**
 * @link ../../../../.docs/features/search/architecture.md
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

        // truncate() fails on SQLite tables without sqlite_sequence
        // (no AUTOINCREMENT); DELETE is equivalent for our purpose.
        $connection->transaction(function () use ($connection): void {
            $connection->table('transaction_search_docs')->delete();
            $connection->statement(
                "INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('delete-all')",
            );
        });

        $total = $connection->table('transactions')->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $indexed = 0;

        $connection
            ->table('transactions')
            ->select(['id', 'user_id', 'counterparty_name', 'description'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($connection, $bar, &$indexed): void {
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

        $connection->statement(
            "INSERT INTO transaction_search_fts(transaction_search_fts) VALUES('rebuild')",
        );

        // Fail loudly on a partial run — a mismatched count means the
        // index is incomplete, and search would silently return
        // partial results otherwise.
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
