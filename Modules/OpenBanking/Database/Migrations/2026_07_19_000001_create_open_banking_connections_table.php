<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates open_banking_connections — one row per linked Enable Banking
 * connection (Req 3/7/8), keyed on (user_id, institution_id).
 *
 * METADATA ONLY. `application_id`, the RSA private-key PEM, `session_id`,
 * and the resolved bank SCA host live EXCLUSIVELY in the chmod-600
 * secrets file `OpenBankingSecretsRepository` writes at
 * storage/app/secrets/open-banking.json — never a DB column, because any
 * secret in SQLite would leak into DB backups (D-07 arch guard, enforced
 * by `OpenBankingSecretsFileGuardTest`'s migration-content grep test,
 * which this migration must keep passing).
 *
 * Two DISTINCT timestamp columns (RESEARCH.md Pitfall 5 — never
 * collapse to a single `last_sync_at`):
 *  - `last_successful_sync_at` — advances ONLY on a genuinely successful
 *    fetch; this is the sole "freshness" signal the settings page shows
 *    (Req 7's "never present stale data as fresh" invariant).
 *  - `last_attempt_at` — advances on every attempt, success or failure,
 *    so a failing scheduled sync is visible independently of the last
 *    time data actually refreshed. `last_attempt_status` carries a short
 *    machine-readable outcome string for the same attempt.
 *
 * `enabled` defaults to false — Req 4/D-12 requires the user to
 * acknowledge a loud third-party-data warning (Wave 3's settings page)
 * before this flips true; completing the consent dance (this plan) never
 * enables the connection by itself.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('open_banking_connections', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('institution_id', 64);
            $table->string('bank_display_name', 128)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamp('consent_expires_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('last_attempt_status', 32)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'institution_id']);
            $table->index(['user_id', 'enabled']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('open_banking_connections');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
