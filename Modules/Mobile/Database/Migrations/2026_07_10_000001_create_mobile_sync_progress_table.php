<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the mobile_sync_progress table — the durable per-user, per-peer
 * resumable-initial-sync cursor (R5 / D-03 / D-04, MOBILE-01/MOBILE-02).
 *
 * `SetupProgressScreen::mount()` (Plan 08) reads this table so a
 * cold-started process (an app-kill mid-pull, which is exactly what
 * happens routinely on mobile OSes per RESEARCH.md Pitfall 5) resumes at
 * the TRUE current percentage on its very first render — never flashing
 * back to 0. `InitialSyncPuller` (Plan 08) writes the watermark/counters
 * here after each bounded batch.
 *
 * Column notes:
 *   - `user_id` is unsignedInteger, NOT NULL, no FK/cascade — consistent
 *     with `pairing_tokens`/`device_registry` (Sync module precedent):
 *     no reliance on the BelongsToUser global scope, since this table is
 *     read/written from console/background-task contexts that never carry
 *     an authenticated-session global scope (PATTERNS.md "user_id-scoped
 *     queries everywhere", T-15-01). Deliberately NOT added to
 *     UserIdColumnArchTest's nullable-domain-table list, mirroring the
 *     Sync tables' own exclusion.
 *   - `peer_device_id` identifies which peer this cursor is pulling from —
 *     a phone may (eventually) pull from more than one desktop peer.
 *   - `records_expected` is nullable — the total is not known until the
 *     first catch-up response arrives.
 *   - `records_applied` defaults to 0 (unsigned int) — plain integer
 *     progress math only, no float/double (RESEARCH.md convention).
 *   - `last_hlc_l`/`last_hlc_c` mirror `PeerCatchUpExchanger`'s HLC
 *     watermark shape (hlc_l/hlc_c on op_log_entries): `last_hlc_l` is
 *     unsignedBigInteger (logical time can grow large across years of
 *     history), `last_hlc_c` is unsignedInteger (bounded per-tick
 *     counter).
 *   - `phase` is a plain TEXT enum: pending|pulling|complete — no
 *     database ENUM column type, consistent with op_log_entries.op_type
 *     (project convention).
 *
 * One index:
 *   - UNIQUE(user_id, peer_device_id) — one durable cursor per
 *     (user, peer) pair; a resumed pull upserts the same row rather than
 *     accumulating duplicate cursors.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('mobile_sync_progress', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('peer_device_id');
            $table->unsignedInteger('records_expected')->nullable();
            $table->unsignedInteger('records_applied')->default(0);
            $table->unsignedBigInteger('last_hlc_l')->default(0);
            $table->unsignedInteger('last_hlc_c')->default(0);
            $table->text('phase')->default('pending'); // pending|pulling|complete
            $table->text('created_at');
            $table->text('updated_at');
        });

        $connection = $this->db()->connection($this->getConnection());

        $connection->statement(
            'CREATE UNIQUE INDEX mobile_sync_progress_user_peer_idx ON mobile_sync_progress (user_id, peer_device_id)'
        );
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('mobile_sync_progress');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
