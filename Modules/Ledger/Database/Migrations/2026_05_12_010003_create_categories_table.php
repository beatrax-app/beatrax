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

            // Per-user UNIQUE so two users can each own a category with the
            // same slug (e.g. both customise "groceries"). Default-seeded
            // global categories use user_id = NULL.
            $table->unique(['user_id', 'slug']);
        });

        // SQLite partial UNIQUE on the global (user_id = NULL) slug set
        // keeps the default seed tree free of slug collisions while leaving
        // the per-user unique above to handle the populated case.
        DB::statement('CREATE UNIQUE INDEX categories_global_slug_uq ON categories(slug) WHERE user_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
