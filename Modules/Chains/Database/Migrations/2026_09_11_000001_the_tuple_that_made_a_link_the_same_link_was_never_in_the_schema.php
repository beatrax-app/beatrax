<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    private const string INDEX = 'chain_links_pair_uq';

    // (user, from, to, kind) is what both write paths have always dedupe on,
    // and the id used to carry it: the row's identity was folded into its
    // primary key. That fold is wrong — both transaction ids in it are
    // autoincrements each device counts for itself — so the id is minted now
    // and the tuple has to be stated where the database can enforce it, which
    // is also what lets PeerRowAliases find a peer's link and reconcile it.
    //
    // to_transaction_id is NULL on a hint row, and SQLite counts NULLs as
    // distinct, so this index does not bind those. It is not meant to: a hint
    // is deleted whole rather than resolved in place, and the resolvers' own
    // SELECT still refuses a second one.
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $this->foldDuplicatePairs($connection);

        $this->schema()->table('chain_links', static function (Blueprint $table): void {
            $table->unique(['user_id', 'from_transaction_id', 'to_transaction_id', 'kind'], self::INDEX);
        });
    }

    public function down(): void
    {
        $this->schema()->table('chain_links', static function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }

    // A pair two devices each wrote before the ids were minted, which the index
    // below would refuse to be created over. The oldest row survives and the
    // series pointing at a loser is repointed, because that column is NULL ON
    // DELETE and would otherwise lose the funding link it still has.
    private function foldDuplicatePairs(Connection $connection): void
    {
        $rows = $connection->table('chain_links')
            ->whereNotNull('to_transaction_id')
            ->orderBy('id')
            ->get(['id', 'user_id', 'from_transaction_id', 'to_transaction_id', 'kind']);

        $survivor = [];

        foreach ($rows as $row) {
            $key = implode('|', [
                (string) ($row->user_id ?? ''),
                (string) $row->from_transaction_id,
                (string) $row->to_transaction_id,
                (string) $row->kind,
            ]);

            if (! isset($survivor[$key])) {
                $survivor[$key] = $row->id;

                continue;
            }

            $connection->table('recurring_series')
                ->where('latest_funding_chain_link_id', $row->id)
                ->update(['latest_funding_chain_link_id' => $survivor[$key]]);

            $connection->table('chain_links')->where('id', $row->id)->delete();
        }
    }
};
