<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a nullable `matcher_key` column to inbox_messages so the
 * matcher consumer can record which SenderMatcher claimed each
 * fetched message. The pair (inbox_id, matcher_key) is indexed so
 * audit queries — "how many PayPal receipts did inbox X yield?" —
 * land on a composite index rather than a full table scan.
 *
 * The column is added by the Receipts module rather than the
 * EmailScan module so the EmailScan migration footprint stays
 * exclusively about the fetch lifecycle. The matcher_key value is
 * the stable lowercase-kebab identifier returned by
 * `SenderMatcher::key()` (e.g. 'paypal-receipt'); NULL means no
 * matcher has run against the row yet (still in `status='fetched'`).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('inbox_messages', static function (Blueprint $table): void {
            $table->string('matcher_key', 64)->nullable()->after('status');
            $table->index(['inbox_id', 'matcher_key']);
        });
    }

    public function down(): void
    {
        $this->schema()->table('inbox_messages', static function (Blueprint $table): void {
            $table->dropIndex(['inbox_id', 'matcher_key']);
            $table->dropColumn('matcher_key');
        });
    }
};
