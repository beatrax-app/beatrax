<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// This is spatie/laravel-activitylog's published `activity_log` schema under a
// name that says what it holds. The column shape has to stay verbatim spatie or
// Activity::query() and the ActivityLogger pipeline stop resolving rows.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('dev_mode_audit', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('dev_mode_audit');
    }
};
