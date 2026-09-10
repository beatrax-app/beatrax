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
        $this->rebuildRowsCarryingANote();
    }

    // The columns were added to the index, not the notes to the rows: a note
    // written before this shipped is on disk and in no body, and nothing
    // rebuilds one on its own. A later sync does not, and search:reindex cannot
    // on an encrypted desktop, where a console run holds no app-lock key.
    public function down(): void {}

    // Through the writer rather than around it — one composer of the body, and
    // on a sealed ledger the safe one: handed a note it cannot open, the writer
    // leaves the stored body alone and records the coordinate in
    // search_index_repairs, which the next unlocked request drains.
    private function rebuildRowsCarryingANote(): void
    {
        if (! $this->schema()->hasTable('transaction_search_docs') || ! $this->schema()->hasTable('transaction_splits')) {
            return;
        }

        /** @var SearchIndexWriterContract $writer */
        $writer = Container::getInstance()->make(SearchIndexWriterContract::class);

        $this->db()->connection($this->getConnection())
            ->table('transactions')
            ->select(['id', 'user_id'])
            ->where(static function (QueryBuilder $carriesANote): void {
                $carriesANote->whereRaw("coalesce(transactions.note, '') <> ''")
                    ->orWhereExists(static function (QueryBuilder $leg): void {
                        $leg->selectRaw('1')
                            ->from('transaction_splits')
                            ->whereColumn('transaction_splits.transaction_id', 'transactions.id')
                            ->whereRaw("coalesce(transaction_splits.note, '') <> ''");
                    });
            })
            ->orderBy('id')
            ->chunk(self::CHUNK_SIZE, static function (Collection $rows) use ($writer): void {
                foreach ($rows as $row) {
                    if (is_numeric($row->id) && is_numeric($row->user_id)) {
                        $writer->upsertForTransaction((int) $row->id, (int) $row->user_id);
                    }
                }
            });
    }
};
