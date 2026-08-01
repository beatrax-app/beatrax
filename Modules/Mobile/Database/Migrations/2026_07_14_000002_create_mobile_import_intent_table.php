<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the `mobile_import_intent` table — a durable, per-user marker
 * that THIS device's local account was bootstrapped via the "Import from
 * another device" flow (`MobileImportBootstrap`), fixing MEDIUM-01 and
 * LOW-01 from `15-import-join-REVIEW.md`.
 *
 * ## Why this table exists (correctness-critical, not cosmetic)
 *
 * Both findings trace back to the SAME root cause: the import-vs-normal
 * distinction lived ONLY in a URL query string (`?mode=import`), read once
 * at `MobilePairingScan::mount()`. A re-entry to `/mobile/pair` WITHOUT the
 * query param (back-button, bookmark, a relaunched/tombstoned NativePHP
 * process) silently lost the signal:
 *
 *   - MEDIUM-01: `MobilePairingScan::confirmMatch()`/`checkPairingState()`
 *     would self-mint a local GDK epoch 1 on such a re-entry, colliding
 *     with the desktop's delivered epoch 1 (silently dropped by
 *     `GdkEpochControlHandler`'s idempotency guard) and permanently
 *     stranding the imported history in `gdk_decrypt_failed` quarantine.
 *   - LOW-01: `InitialSyncPuller`/`SetupProgressScreen` had no way to tell
 *     a genuine "encryption never enabled" completion (a legitimate,
 *     permanently-empty keyring) apart from "import still waiting for the
 *     desktop's epochs to arrive" (a TEMPORARILY-empty keyring) — both look
 *     identical from `sync_encryption_state.current_epoch IS NULL` alone.
 *
 * A single durable, server-side marker (never re-derived from a query
 * string on every request) resolves both: `MobilePairingScan::mount()`
 * persists it the moment `?mode=import` is FIRST observed (so a LATER
 * re-entry without the param still reads the durable marker) and
 * `MobileImportBootstrap::provisionDeviceLocally()` persists it
 * authoritatively at bootstrap time — the true origin of "this device is
 * importing."
 *
 * Column notes:
 *   - `user_id` UNIQUE — one row per user (single-user v1, mirrors the
 *     rest of this schema's `user_id`-scoped convention). A second
 *     `markImporting()` call for the same user is a no-op (idempotent).
 *   - No key material, no new trust decision — a plain marker of WHETHER
 *     this device's account originated from an import, consumed only to
 *     decide (a) whether to defer local self-mint and (b) whether to keep
 *     the setup gate blocking until real epochs arrive. Never read by any
 *     crypto/admission code path.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('mobile_import_intent', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->text('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('mobile_import_intent');
    }
};
