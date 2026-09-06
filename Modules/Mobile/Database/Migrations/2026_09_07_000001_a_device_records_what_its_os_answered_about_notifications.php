<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Device-local by construction rather than by exclusion: the answer describes
// THIS install's operating system, so it lives in a table the sync registry
// never covers instead of as columns on notification_preferences, which
// travels. A peer's grant says nothing about this device's.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('mobile_notification_grant', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();

            // When this install last raised the prompt. Set before the OS is
            // called, so a crash mid-dialog does not re-ask on every boot.
            $table->text('requested_at');

            // Null until an answer comes back, which is a state of its own:
            // the reader may still be looking at the dialog, or the page that
            // was listening may have been navigated away from before they
            // tapped. Not the same as a refusal, and never reported as one.
            $table->boolean('granted')->nullable();
            $table->text('answered_at')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('mobile_notification_grant');
    }
};
