<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the `notification_preferences` table — one row per (user, device)
 * pair (D-34). Every delivery decision (`SuppressionEvaluator`) reads a
 * single device's own row; a device NEVER obeys another device's values
 * (D-07) even though every row is readable everywhere (D-35's own-device
 * write / other-devices read-only split).
 *
 * `id()` autoincrement surrogate is acceptable here — unlike
 * `notifications`, this table is NOT independently generated on two
 * devices from the same logical fact; a device only ever writes its OWN
 * row, so D-05's cross-device-convergence determinism argument does not
 * apply. The UNIQUE composite index on `(user_id, device_id)` is the real
 * identity key.
 *
 * Column defaults are the D-16/D-19/D-15/D-24 locked out-of-box values:
 * reminders ON, budget nudges ON, digest cadence WEEKLY, savings prompts
 * OFF, reminder lead time 3 days, quiet hours OFF with a 22:00-08:00
 * preset window, and OS bodies carry financial detail by default
 * (`hide_details` false). `NotificationPreferencesDto::defaults()` is the
 * single source of truth for these same values in PHP — this migration's
 * column defaults exist for direct-SQL-insert convenience only and must
 * never drift from the DTO factory.
 *
 * NONE of these columns are encrypted — they are structural preference
 * flags that must be readable with no KEK, and D-12's encrypted-column
 * set is scoped to the `notifications` table's content columns only.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('notification_preferences', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            $table->boolean('reminders_enabled')->default(true);
            $table->boolean('budget_nudges_enabled')->default(true);
            $table->string('digest_cadence', 8)->default('weekly');
            $table->boolean('savings_prompts_enabled')->default(false);
            $table->unsignedTinyInteger('reminder_lead_days')->default(3);
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->string('quiet_hours_from', 5)->nullable()->default('22:00');
            $table->string('quiet_hours_to', 5)->nullable()->default('08:00');
            $table->boolean('hide_details')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'device_id'], 'notification_preferences_user_device_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notification_preferences');
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
