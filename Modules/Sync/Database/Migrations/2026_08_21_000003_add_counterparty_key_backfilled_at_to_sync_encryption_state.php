<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        // Device-local like the rest of this table. It records that this
        // device's rows have been converted to the keyed counterparty digest,
        // which is what decides whether a peer's differing blind-index key can
        // still be adopted or must be refused.
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->timestamp('counterparty_key_backfilled_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('sync_encryption_state', static function (Blueprint $table): void {
            $table->dropColumn('counterparty_key_backfilled_at');
        });
    }
};
