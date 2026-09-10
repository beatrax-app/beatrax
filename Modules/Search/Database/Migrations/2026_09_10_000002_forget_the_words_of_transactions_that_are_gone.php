<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;

return new class extends ModuleMigration
{
    private const int CHUNK_SIZE = 500;

    public function up(): void
    {
        $this->forgetDocumentsWithNoTransaction();
    }

    // Nothing to put back: the rows this removes describe transactions that do
    // not exist, so a reverse would have to invent them.
    public function down(): void {}

    // A document outlives its transaction when something deleted the row
    // without telling the writer -- a database cascade did exactly that until
    // it was removed tree-wide. The cause is gone and the residue is not: the
    // body is a PLAINTEXT shadow, so a deleted purchase keeps its merchant name
    // readable on disk for the life of the install.
    //
    // search:reindex would clear them, and cannot here: it skips a user whose
    // columns a console run holds no key for, which on an encrypted desktop is
    // every user.
    private function forgetDocumentsWithNoTransaction(): void
    {
        if (! $this->schema()->hasTable('transaction_search_docs') || ! $this->schema()->hasTable('transactions')) {
            return;
        }

        /** @var SearchIndexWriterContract $writer */
        $writer = Container::getInstance()->make(SearchIndexWriterContract::class);

        $this->db()->connection($this->getConnection())
            ->table('transaction_search_docs')
            ->select(['transaction_id', 'user_id'])
            ->whereNotExists(static function (QueryBuilder $transaction): void {
                $transaction->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.id', 'transaction_search_docs.transaction_id');
            })
            ->orderBy('transaction_id')
            ->chunk(self::CHUNK_SIZE, static function (Collection $rows) use ($writer): void {
                foreach ($rows as $row) {
                    if (! is_numeric($row->transaction_id) || ! is_numeric($row->user_id)) {
                        continue;
                    }

                    // Through the writer, because the FTS5 index is external
                    // content: deleting the row alone leaves the terms behind,
                    // which is the whole of what this is removing. The owner is
                    // the actor here -- a migration acts for the row it found.
                    $writer->deleteForTransaction((int) $row->transaction_id, (int) $row->user_id);
                }
            });
    }
};
