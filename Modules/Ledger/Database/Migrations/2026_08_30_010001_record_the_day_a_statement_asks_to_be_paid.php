<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// An ICS statement prints its own payment deadline in one unambiguous place and
// nothing read it, so the app dated every statement by adding a constant to the
// period it derived. The column is nullable because a source that prints no
// deadline is the normal case: MT940, CAMT.053 and every CSV carry none.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-tolerance-calibrated-on-a-synthesised-fixture-while-a-real-one-disagrees
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('statement_summaries', static function (Blueprint $table): void {
            $table->dateTime('payment_due_date')->nullable()->after('closing_balance_date');
        });
    }

    public function down(): void
    {
        $this->schema()->table('statement_summaries', static function (Blueprint $table): void {
            $table->dropColumn('payment_due_date');
        });
    }
};
