<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    public function up(): void
    {
        $merchants = DB::table('merchants')->get(['user_id', 'name', 'normalized_name']);

        foreach ($merchants as $merchant) {
            $name = is_string($merchant->name) ? $merchant->name : '';
            $normalized = is_string($merchant->normalized_name) ? $merchant->normalized_name : '';
            if ($name === '' || $normalized === '' || $name === $normalized) {
                continue;
            }

            DB::table('recurring_series')
                ->where('user_id', $merchant->user_id)
                ->where('detected_name', $normalized)
                ->update(['detected_name' => $name]);
        }
    }

    public function down(): void
    {
        // Not reversible, and not worth being: the normalised key is still in
        // `cluster_counterparty_key`, so nothing depends on this column
        // holding it.
    }
};
