<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `overspend_mode` has no DB-level default, which keeps it in the Sync
// registry's required-create set; a missing settings row reads as
// 'reduce_to_budget' in CarryoverQuery instead. `user_id` is NOT NULL here,
// so this table stays out of UserIdColumnArchTest.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('envelope_settings', static function (Blueprint $table): void {
            $table->id();
            // NOT NULL: NULL is distinct in a unique index, so a nullable
            // user_id would leave the UNIQUE upsert below unenforceable.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('overspend_mode', 32); // 'reduce_to_budget' | 'carry_negative'
            $table->timestamps();

            $table->unique(['user_id', 'category_id'], 'envelope_settings_user_cat_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_settings');
    }
};
