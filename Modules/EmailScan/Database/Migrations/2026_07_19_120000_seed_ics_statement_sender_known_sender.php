<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Migrations\Migration;
use Modules\EmailScan\Database\Seeders\IcsStatementSenderSeeder;

// Without this system known_senders row IncrementalScanJob never fetches the
// message the ICS "statement ready" nudge detects. A migration rather than a
// seeder so existing installs pick it up on `migrate`.
return new class extends Migration
{
    public function up(): void
    {
        /** @var IcsStatementSenderSeeder $seeder */
        $seeder = Container::getInstance()->make(IcsStatementSenderSeeder::class);

        $seeder->run();
    }

    public function down(): void
    {
        // Deliberately empty: a per-user promotion of the same email_pattern
        // cannot be told apart from the row this migration seeded, so deleting
        // on rollback would take someone's own row with it.
    }
};
