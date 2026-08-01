<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the known_senders table — the curated list of sender
 * patterns the primary scan filter narrows the per-inbox fetch to.
 *
 * The `user_id` column is nullable on purpose: rows seeded by the
 * application (PayPal, ICS Cards, Google Play) carry user_id = NULL
 * and source = 'system'; users may add or promote per-account rows
 * which carry user_id = $user->id and source = 'user'. The runtime
 * query reads `WHERE user_id = $userId OR user_id IS NULL` so each
 * user sees the system seeds plus their own additions.
 *
 * The `source` column is an enum-shaped string ('system' or 'user')
 * enforced via paired BEFORE INSERT / BEFORE UPDATE triggers.
 *
 * Three system rows are inserted at the end of up() so that a clean
 * migrate:fresh always lands the canonical seed set. The seed insert
 * runs after the trigger pair is in place to confirm the trigger
 * accepts the 'system' value, exercising the schema invariant during
 * the migration itself.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('known_senders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('email_pattern', 320);
            $table->string('label', 100);
            $table->string('source', 16)->default('user');
            $table->timestamp('added_at');
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['source']);
            // UNIQUE on (user_id, email_pattern) prevents a user from
            // promoting an already-known sender twice — the discovered-
            // sender promotion path is idempotent at the action layer,
            // but a future seeder or import path could trip the
            // invariant otherwise. Note: NULL != NULL in SQL UNIQUE
            // semantics, so the system seeds (user_id = NULL) coexist
            // with a per-user (user_id = N, email_pattern = same) row
            // without conflict — exactly the desired behaviour.
            $table->unique(['user_id', 'email_pattern']);
        });

        $connection = $this->db()->connection($this->getConnection());
        $allowedSources = "'system','user'";

        $connection->statement(sprintf(
            "CREATE TRIGGER known_senders_source_check_insert BEFORE INSERT ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END",
            $allowedSources,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER known_senders_source_check_update BEFORE UPDATE OF source ON known_senders FOR EACH ROW
             WHEN NEW.source NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid known_senders.source value'); END",
            $allowedSources,
        ));

        // Canonical system seeds — the three sender patterns the
        // primary scan filter starts with on a fresh install. Each
        // row carries user_id = NULL so every user sees these rows in
        // the union read; the source = 'system' marker keeps them
        // distinguishable from per-user additions in the UI.
        $now = CarbonImmutable::now()->toDateTimeString();
        $connection->table('known_senders')->insert([
            [
                'user_id' => null,
                'email_pattern' => 'paypal.com',
                'label' => 'PayPal',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'email_pattern' => '@ics.nl',
                'label' => 'ICS Cards',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'email_pattern' => 'googleplay-noreply@google.com',
                'label' => 'Google Play',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('known_senders');
    }
};
