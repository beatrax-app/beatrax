<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The day the issuer printed, carried onto the statement promoted from the
// summary that read it. NULL means the statement printed no deadline, and
// StatementDueDate::of() dates those from the period they bill -- which is
// every statement already on disk, so this column leaves them exactly as the
// reader has seen them until an import supplies a printed day.
/**
 * @link ../../../../.docs/features/chains/card-statement-lifecycle.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('card_statements', static function (Blueprint $table): void {
            $table->dateTime('due_date')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        $this->schema()->table('card_statements', static function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });
    }
};
