<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Search\Internal\Services\SearchDocumentBody;

return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('search_index_repairs')) {
            $this->schema()->create('search_index_repairs', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('transaction_id');
                $table->string('requested_at');
                // The keyring a repair pass last failed under. A column sealed
                // to an epoch whose wrap never reached this device fails on
                // every attempt, so without it the drain re-decrypts the same
                // rows on every request and recovers nothing.
                $table->string('failed_fingerprint')->nullable();
                $table->unique(['user_id', 'transaction_id'], 'search_index_repairs_row_idx');
            });
        }

        $this->seedFromEmptiedBodies();
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('search_index_repairs');
    }

    // A peer syncing into a desktop whose window was shut rebuilt every touched
    // body out of columns it could not open, so the rows are on disk with their
    // words gone and nothing names them. The predicate is the damage itself: an
    // empty body over a transaction that still carries content.
    private function seedFromEmptiedBodies(): void
    {
        if (! $this->schema()->hasTable('transaction_search_docs') || ! $this->schema()->hasTable('transactions')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());
        $now = now()->toDateTimeString();

        $rows = $connection->table('transaction_search_docs as d')
            ->join('transactions as t', 't.id', '=', 'd.transaction_id')
            ->where('d.search_body', SearchDocumentBody::join('', '', ''))
            ->where(static function (QueryBuilder $q): void {
                $q->whereRaw("coalesce(t.description, '') <> ''")
                    ->orWhereRaw("coalesce(t.counterparty_name, '') <> ''")
                    ->orWhereExists(static function (QueryBuilder $tag): void {
                        $tag->selectRaw('1')
                            ->from('tax_transaction_tags')
                            ->whereColumn('tax_transaction_tags.transaction_id', 't.id')
                            ->whereNull('tax_transaction_tags.transaction_split_id')
                            ->whereRaw("coalesce(tax_transaction_tags.note, '') <> ''");
                    });
            })
            ->get(['d.transaction_id', 'd.user_id']);

        foreach ($rows->chunk(200) as $chunk) {
            $coordinates = [];

            foreach ($chunk as $row) {
                if (! is_numeric($row->transaction_id) || ! is_numeric($row->user_id)) {
                    continue;
                }

                $coordinates[] = [
                    'user_id' => (int) $row->user_id,
                    'transaction_id' => (int) $row->transaction_id,
                    'requested_at' => $now,
                ];
            }

            if ($coordinates !== []) {
                $connection->table('search_index_repairs')->insertOrIgnore($coordinates);
            }
        }
    }
};
