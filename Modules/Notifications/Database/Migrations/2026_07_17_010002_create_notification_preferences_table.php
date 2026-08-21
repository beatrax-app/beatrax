<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('notification_preferences', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('device_id');
            // These defaults exist only so a direct SQL insert lands a usable
            // row; NotificationPreferencesDto::defaults() is the source of
            // truth and the two must not drift. Nothing here is encrypted —
            // suppression has to be decidable with no KEK.
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

            // The real identity key; the surrogate id() is safe here only
            // because a device writes nobody's row but its own.
            $table->unique(['user_id', 'device_id'], 'notification_preferences_user_device_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('notification_preferences');
    }
};
