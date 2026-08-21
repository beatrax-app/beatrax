<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('kind', 16);
            $table->unsignedInteger('display_order')->default(100);
            $table->timestamps();

            // Per-user, so two users can each own a "groceries" slug. The
            // default-seeded global rows carry user_id = NULL.
            $table->unique(['user_id', 'slug']);
        });

        // A partial UNIQUE for the global set, which the index above cannot
        // cover: NULL user_ids never collide with each other.
        DB::statement('CREATE UNIQUE INDEX categories_global_slug_uq ON categories(slug) WHERE user_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
