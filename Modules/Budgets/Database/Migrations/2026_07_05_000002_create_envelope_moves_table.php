<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Virtual allocations, not real money: these rows must never reach forecasts,
// cash-flow surfaces or categorization. `user_id` is nullable and denormalised
// per the append-only convention, which is what puts this table — alone among
// the envelope tables — in UserIdColumnArchTest.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('envelope_moves', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            // cascadeOnDelete, not nullOnDelete: never leave half a pair.
            $table->foreignId('counterpart_category_id')->constrained('categories')->cascadeOnDelete();
            $table->date('period_start');
            $table->bigInteger('amount_minor'); // signed: + into the envelope, − out of it
            // No DB-level default keeps this in the Sync registry's
            // required-create set; EnvelopeWriter always supplies it.
            $table->string('currency', 3);
            $table->string('kind', 32); // 'move_in' | 'move_out'
            $table->string('memo')->nullable();
            // Pairs a move's two rows for undoMove(), which otherwise has only
            // a created_at good to the second. Nullable: legacy rows predate it.
            $table->string('move_group_id', 36)->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'category_id', 'period_start']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_moves');
    }
};
