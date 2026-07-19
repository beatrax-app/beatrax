<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder;

/**
 * Pure-data migration (mirrors
 * Modules/Ledger/Database/Migrations/2026_05_27_000002_backfill_starting_balance_from_statement_summaries.php's
 * "resolve service from the container, call run()" shape): lands the
 * system `known_senders` row(s) the ICS "statement ready" nudge detector
 * needs `IncrementalScanJob` to actually fetch the message (Req 14,
 * D-14/D-15). Runs automatically on every `php artisan migrate`, so
 * existing installs pick this up without a manual re-seed step.
 *
 * Not part of the original `known_senders` table-creation migration
 * because this row is new, Phase-19 functionality — the table's own
 * migration is immutable history.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Anonymous migrations are instantiated by Laravel's migrator with
        // no constructor arguments, so the seeder is resolved from the
        // application container at the migration boundary.
        /** @var IcsStatementSenderSeeder $seeder */
        $seeder = Container::getInstance()->make(IcsStatementSenderSeeder::class);

        $seeder->run();
    }

    public function down(): void
    {
        // Data-only; re-running up() is idempotent and safe. Deliberately
        // does not delete the seeded row(s) on rollback — a per-user
        // promotion of the same email_pattern (real user_id, source='user')
        // must never be touched by this migration's down(), and there is
        // no reliable way to distinguish "the row this migration inserted"
        // from "a row a later manual admin action also inserted" once
        // other code paths exist that could write the same system pattern.
    }
};
