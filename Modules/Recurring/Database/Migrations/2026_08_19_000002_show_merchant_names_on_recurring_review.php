<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `recurring_series.detected_name` was copied straight from the clustering
 * key, which is lower-cased with the punctuation stripped. So the one screen
 * whose whole job is "do you recognise this?" listed `netflix international
 * bv`, `asn bank gea` and `domino s pizza` — the last of those having lost
 * the apostrophe rather than escaped it.
 *
 * The detectors now write the merchant's name as written. This heals the rows
 * that already exist, which no detector sweep would revisit. `merchants` is
 * the same lookup rule evaluation joins on, so it is plaintext and needs no
 * key to read. A row the user has renamed is untouched — the rename lives in
 * `display_name_override` and outranks this column either way.
 *
 * @link ../../../../.docs/features/recurring/architecture.md
 */
return new class extends Migration
{
    // One correlated statement, not one UPDATE per merchant: this runs at
    // launch-time migration on a phone, where every round trip is a synchronous
    // call on the path between tapping the icon and seeing anything.
    public function up(): void
    {
        $schema = Schema::getFacadeRoot();

        if (! $schema->hasTable('merchants') || ! $schema->hasTable('recurring_series')) {
            return;
        }

        $match = <<<'SQL'
            SELECT m.name
            FROM merchants m
            WHERE m.user_id = recurring_series.user_id
              AND m.normalized_name = recurring_series.detected_name
              AND m.name <> ''
              AND m.normalized_name <> ''
              AND m.name <> m.normalized_name
            LIMIT 1
            SQL;

        DB::statement(
            "UPDATE recurring_series SET detected_name = ({$match}) WHERE EXISTS ({$match})"
        );
    }

    public function down(): void
    {
        // Not reversible, and not worth being: the normalised key is still in
        // `cluster_counterparty_key`, so nothing depends on this column
        // holding it.
    }
};
