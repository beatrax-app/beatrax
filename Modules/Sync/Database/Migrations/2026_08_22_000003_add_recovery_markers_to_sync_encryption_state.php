<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-rows-already-written-in-the-clear
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // All three device-local, like every other column of this table. The
        // digest is the sweep's own coverage fingerprint rather than a boolean,
        // so a release that registers a new column re-sweeps an install that is
        // already enabled — the one door plaintext can still arrive through.
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->string('resealed_columns_digest')->nullable();
            $table->timestamp('history_reprojected_at')->nullable();
            // Content hash of the keyring file, readable without the app-lock
            // key. A change to it means key material moved, which is the only
            // event that can make a previously unopenable entry openable.
            $table->string('reprojected_keyring_fingerprint')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->dropColumn(['resealed_columns_digest', 'history_reprojected_at', 'reprojected_keyring_fingerprint']);
        });
    }
};
