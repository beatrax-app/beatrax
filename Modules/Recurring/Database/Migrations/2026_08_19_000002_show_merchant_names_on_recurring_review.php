<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// `detected_name` used to be the clustering key — lower-cased, punctuation
// stripped — so the screen that asks "do you recognise this?" showed
// `domino s pizza`. The detectors write the real name now; this heals rows no
// detector sweep would revisit. `merchants` is plaintext, so no key is needed.
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
        // Not reversible, and not worth being: `cluster_counterparty_key` still
        // holds the normalised key, so nothing depends on this column for it.
    }
};
