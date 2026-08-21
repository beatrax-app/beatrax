<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// insight_key is the stable key SavingsInsightsQuery composes ("cheaper:42",
// "cancel:42"), so a recomputed insight set filters against this table by key.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('savings_insight_dismissals', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('insight_key', 64);
            $table->timestamps();

            $table->unique(['user_id', 'insight_key'], 'savings_insight_dismissals_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('savings_insight_dismissals');
    }
};
