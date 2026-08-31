<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        // A system-wide alert is one row every member of the household sees,
        // so acknowledgement cannot live on it: stamping acknowledged_at there
        // took a database-integrity warning off the other member's screen.
        // An owned alert keeps using its own column — there is exactly one
        // person it was ever addressed to.
        $this->schema()->create('system_alert_acknowledgements', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('system_alert_id')->constrained('system_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acknowledged_at');

            $table->unique(['system_alert_id', 'user_id'], 'system_alert_acks_alert_user_idx');
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('system_alert_acknowledgements');
    }
};
