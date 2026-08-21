<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `pattern` is the immutable first-seen raw description; the user edits
// `generalized_pattern` to widen or narrow which other rows the alias
// matches, which is what the (user_id, generalized_pattern) index serves.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('merchant_aliases', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pattern');
            $table->string('generalized_pattern');
            $table->string('friendly_name');
            $table->json('merged_from')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'pattern']);
            $table->index(['user_id', 'generalized_pattern']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('merchant_aliases');
    }
};
