<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// next_expected_at is clamped when it lands in a month shorter than the day
// the bill is charged on, and the calendar and the forecast both walk forward
// from it — so a 31st bill whose next date fell in February projected the 28th
// for every month after, while the reminder said the 31st.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('billing_day')
                ->nullable()
                ->after('next_expected_confidence_low');
        });
    }

    public function down(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropColumn('billing_day');
        });
    }
};
