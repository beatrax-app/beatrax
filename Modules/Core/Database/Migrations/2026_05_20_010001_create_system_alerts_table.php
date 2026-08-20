<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('system_alerts', static function (Blueprint $table): void {
            $table->id();
            // A null user_id is a system-wide alert every authenticated user sees.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('acknowledged_at')->nullable();

            // Two composite reads: the banner's per-user active list, and the
            // operational "every active alert of this kind, across users" the
            // doctor probes run. No UNIQUE — repeat alerts of one kind are
            // expected, and each stays visible until acknowledged separately.
            $table->index(['user_id', 'acknowledged_at']);
            $table->index(['kind', 'acknowledged_at']);
        });

        $connection = $this->db()->connection($this->getConnection());

        // A schema-level rail: the trigger pair rejects an out-of-enum severity
        // even if a bug in the Eloquent layer bypasses the casts.
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
