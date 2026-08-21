<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `currency` carries no DB-level default, which is what keeps it in the Sync
// registry's required-create set; EnvelopeWriter always supplies it.
// `user_id` is NOT NULL here, so this table stays out of UserIdColumnArchTest.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('envelope_assignments', static function (Blueprint $table): void {
            $table->id();
            // NOT NULL: NULL is distinct in a unique index, so a nullable
            // user_id would leave the UNIQUE upsert below unenforceable.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->date('period_start');
            $table->bigInteger('assigned_minor'); // always >= 0; a zero assignment deletes the row
            $table->string('currency', 3);
            $table->timestamps();

            $table->unique(['user_id', 'category_id', 'period_start'], 'envelope_assignments_user_cat_period_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_assignments');
    }
};
