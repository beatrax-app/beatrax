<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `skipped_update_versions` JSON column to the existing
 * `user_preferences` table — the per-user list of release versions
 * the user has explicitly dismissed via the auto-update banner's
 * "Skip this version" action.
 *
 * The SystemAlertsBanner consults this list at render time so a
 * dismissed version never re-banners as `update.available`. The
 * filter is INTENTIONALLY narrow — `update.stale` and
 * `update.critical` ignore the skip list because their threat models
 * (long-unsupported version, security-fix critical update) explicitly
 * override the user's earlier dismissal.
 *
 * Additive change: the `user_preferences` table is created by the
 * Plan 17-04a foundation migration; this migration ONLY adds the
 * column. If that table is missing at execution time, the schema
 * builder fails loud — the foundation plan's depends_on contract is
 * the load-order guarantee.
 *
 * Default `[]` (JSON empty array) backfills every existing row so
 * the read path can safely cast to an array without a null guard.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->json('skipped_update_versions')->default('[]');
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn('skipped_update_versions');
        });
    }
};
