<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the system_alerts table — the persistent operational-failure
 * surface that surfaces "the app noticed something went wrong" events to
 * the user via the dashboard's sticky banner.
 *
 * A row models exactly one alert event (corrupt backup, overdue backup,
 * misconfigured SQLite PRAGMA, etc.). Once written it stays in place
 * forever: acknowledging stamps `acknowledged_at` rather than deleting,
 * so the audit trail of operational incidents is queryable indefinitely.
 *
 * The `user_id` column is nullable: rows with `user_id IS NULL` are
 * system-wide alerts visible to every authenticated user (e.g. a
 * configuration drift that is not user-scoped). Rows with a non-null
 * `user_id` are private to that user. The read query and the acknowledge
 * action both apply the per-user-OR-system-wide scope shape.
 *
 * The `severity` column is an enum-shaped string enforced via a BEFORE
 * INSERT / BEFORE UPDATE OF severity trigger pair: a schema-level rail
 * that rejects values outside `'info','warning','critical'` even if a
 * bug in the Eloquent layer ever tries to bypass the casts. Allowed
 * severities are intentionally small and human-readable; future kinds
 * map onto these three tiers rather than expanding the enum.
 *
 * Two read indexes back the hot paths: `(user_id, acknowledged_at)`
 * powers the banner's per-user active-alerts read; `(kind, acknowledged_at)`
 * powers operational reads of "all currently-active backup_corrupt
 * alerts across users" which doctor probes and freshness checks rely
 * on. There is intentionally no UNIQUE constraint — duplicate alerts
 * of the same kind ARE expected (a user with two corrupt backup runs
 * sees two rows, both surfacing in the banner until each is resolved).
 *
 * The schema is deliberately broad enough that operational modules
 * outside Core can write rows without a follow-up migration: kind is
 * a free-form string with index support, metadata is a nullable JSON
 * blob, severity is one of three values. No JOINs to transactions /
 * domain tables are allowed; a BoundaryArchTest invariant locks this.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('system_alerts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();

            $table->index(['user_id', 'acknowledged_at']);
            $table->index(['kind', 'acknowledged_at']);
        });

        $connection = $this->db()->connection($this->getConnection());

        // The allowed-severities tuple is hard-coded; sprintf-interpolating
        // a static constant adds no safety, only indirection. Heredoc the
        // trigger DDL inline so the SQL reads as plain SQL.
        $connection->statement(<<<'SQL'
            CREATE TRIGGER system_alerts_severity_check_insert BEFORE INSERT ON system_alerts FOR EACH ROW
            WHEN NEW.severity NOT IN ('info','warning','critical')
            BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END
        SQL);
        $connection->statement(<<<'SQL'
            CREATE TRIGGER system_alerts_severity_check_update BEFORE UPDATE OF severity ON system_alerts FOR EACH ROW
            WHEN NEW.severity NOT IN ('info','warning','critical')
            BEGIN SELECT RAISE(ABORT, 'Invalid system_alerts.severity value'); END
        SQL);
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS system_alerts_severity_check_update');

        $this->schema()->dropIfExists('system_alerts');
    }
};
