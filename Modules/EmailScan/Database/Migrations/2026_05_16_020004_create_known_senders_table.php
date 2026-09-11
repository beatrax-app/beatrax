<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\DerivedRowId;

// `user_id` is nullable on purpose: a NULL row is an application seed every
// user sees, read back as `WHERE user_id = ? OR user_id IS NULL`.
// The seeds are inserted after the trigger pair so migrating itself proves the
// trigger accepts 'system'.
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
            // Blocks promoting the same sender twice. NULL != NULL under SQL
            // UNIQUE, so the system seeds (user_id = NULL) still coexist with a
            // per-user row carrying the same pattern.
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

        $now = CarbonImmutable::now()->toDateTimeString();

        // 2026_08_28_000002 rewrites these three to their derived ids and takes
        // the AUTOINCREMENT off. Naming the id here says so at the insert
        // rather than leaving the seeds correct only for as long as that later
        // migration keeps running after this one.
        $connection->table('known_senders')->insert([
            [
                'id' => DerivedRowId::for('known_senders', ['user_id' => null, 'email_pattern' => 'paypal.com']),
                'user_id' => null,
                'email_pattern' => 'paypal.com',
                'label' => 'PayPal',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => DerivedRowId::for('known_senders', ['user_id' => null, 'email_pattern' => '@ics.nl']),
                'user_id' => null,
                'email_pattern' => '@ics.nl',
                'label' => 'ICS Cards',
                'source' => 'system',
                'added_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => DerivedRowId::for('known_senders', ['user_id' => null, 'email_pattern' => 'googleplay-noreply@google.com']),
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
