<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

/**
 * Gives `chain_links` the UNIQUE it has never had.
 *
 * The table shipped with three plain indexes and no uniqueness constraint at
 * all, so nothing at the schema layer said what a chain link IS. Both write
 * paths carried their own idea of it instead, and they did not agree:
 * `ChainLinkInsertHelper` guarded on `(user_id, from_transaction_id,
 * to_transaction_id, kind)` while `CreateChainLinkFromHint` guarded on
 * `(user_id, from_transaction_id, kind)`.
 *
 * The tuple below is the first of those, with the NULL endpoint folded to a
 * sentinel:
 *
 *   (user_id, from_transaction_id, kind, ifnull(to_transaction_id, -1))
 *
 * `to_transaction_id` is the discriminator because that is what genuinely
 * separates sibling rows: `IcsSettlementResolver` writes ONE row per covered
 * expense against a single bulk settlement, so the transfer's own id repeats
 * by design and only the expense it paid for tells the rows apart. Ids are
 * positive, so `-1` cannot collide with one.
 *
 * The fold is what makes the constraint work at all. SQLite treats NULLs in a
 * UNIQUE index as distinct, so the endpoint-less rows — exceeded-tolerance ICS
 * candidates and both receipt hint kinds — would each have been exempt from
 * the very constraint meant to deduplicate them. Folded, they collapse to one
 * row per `(user, from, kind)`, which is exactly the rule the hint listener
 * was already applying by hand.
 *
 * @link ../../../.docs/features/chains/architecture.md
 */
return new class extends ModuleMigration
{
    private const string INDEX_NAME = 'chain_links_uniq';

    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // A duplicate here means two rows already claim the same link, and
        // picking a survivor is a judgement about the user's ledger that a
        // migration has no standing to make.
        $collisions = $connection->table('chain_links')
            ->selectRaw('user_id, from_transaction_id, kind, ifnull(to_transaction_id, -1) as endpoint, COUNT(*) as row_count')
            ->groupBy('user_id', 'from_transaction_id', 'kind', 'endpoint')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($collisions->isNotEmpty()) {
            $described = $collisions->map(static fn (object $row): string => sprintf(
                'user %s / from %s / %s / to %s: %s rows',
                (string) ($row->user_id ?? 'null'),
                (string) ($row->from_transaction_id ?? 'null'),
                (string) ($row->kind ?? 'null'),
                (string) ($row->endpoint ?? 'null'),
                (string) ($row->row_count ?? '?'),
            ))->implode('; ');

            throw new RuntimeException(
                'chain_links cannot be made unique on (user_id, from_transaction_id, kind, to_transaction_id): '
                .'these links already have more than one row — '.$described,
            );
        }

        $connection->statement(sprintf(
            'CREATE UNIQUE INDEX %s ON chain_links (user_id, from_transaction_id, kind, ifnull(to_transaction_id, -1))',
            self::INDEX_NAME,
        ));
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
