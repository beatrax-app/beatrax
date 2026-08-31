<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Metadata only: no credential column may ever be added to this table, since a
// secret in SQLite would leak into every DB backup the user takes.
//
// `fetched_through_at` splits the fetch cursor away from
// `last_successful_sync_at`, which is a freshness signal a reader looks at. One
// column could not be both: a preview the reader never confirmed advanced the
// cursor over rows nothing had written. `consent_revoked_at` records the
// aggregator refusing the session, so a revoked connection stops reading
// "Connected" until it is re-linked.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->timestamp('fetched_through_at')->nullable()->after('last_successful_sync_at');
            $table->timestamp('consent_revoked_at')->nullable()->after('consent_expires_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->dropColumn(['fetched_through_at', 'consent_revoked_at']);
        });
    }
};
