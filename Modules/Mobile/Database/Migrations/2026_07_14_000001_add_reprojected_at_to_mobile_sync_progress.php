<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `reprojected_at` column to `mobile_sync_progress` — Phase 15
 * import-join (Task 4, O1) resumability marker.
 *
 * `InitialSyncPuller::pull()` runs `HistoryReprojector::reproject()`
 * (`OpLogRebuilder::rebuild()`) AT MOST ONCE per (user, peer) cursor, the
 * moment the GDK keyring transitions from empty to non-empty — this column
 * is that guard. Without it a repeated `pull()` call (e.g. a `wire:poll`
 * tick) would re-run the full-history rebuild on every tick once the
 * keyring is populated, which is idempotent but wastefully expensive.
 *
 * Column notes:
 *   - NULLABLE TEXT ISO8601 timestamp, additive/backward-compatible — an
 *     existing cursor row (from a device that paired before this plan)
 *     simply reads NULL, meaning "not yet reprojected", and the next
 *     `pull()` step evaluates it normally.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            $table->text('reprojected_at')->nullable()->after('phase');
        });
    }

    public function down(): void
    {
        $this->schema()->table('mobile_sync_progress', static function (Blueprint $table): void {
            $table->dropColumn('reprojected_at');
        });
    }
};
