<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds two nullable JSON columns to the existing `user_preferences` table
 * for per-user calendar account-filter persistence (D-03).
 *
 * `calendar_entries_accounts` — JSON array of account IDs whose recurring
 * entries appear on the calendar grid. Null = all accounts (entries all ON).
 *
 * `calendar_balance_accounts` — JSON array of account IDs whose forecast
 * balances are summed for the daily balance line. Null = spendable default
 * (checking + PayPal ON; savings + ICS liability OFF) resolved at
 * CalendarQuery read time in Plan 02.
 *
 * The migration is additive — it does NOT (re)create the table. Uses the
 * Container DI pattern for DatabaseManager rather than the DB facade,
 * matching the project's DI-only invariant.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('user_preferences', static function (Blueprint $table): void {
            // null = "all accounts" default (all entries ON per D-03)
            $table->json('calendar_entries_accounts')->nullable();
            // null = "spendable default" (checking + PayPal) resolved at CalendarQuery read time
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
