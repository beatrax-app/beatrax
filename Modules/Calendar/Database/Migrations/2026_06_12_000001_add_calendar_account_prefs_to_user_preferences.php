<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            // null = all accounts
            $table->json('calendar_entries_accounts')->nullable();
            // null = the spendable default (checking + PayPal), resolved at
            // CalendarQuery read time
            $table->json('calendar_balance_accounts')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            $table->dropColumn(['calendar_entries_accounts', 'calendar_balance_accounts']);
        });
    }
};
