<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('name');
            $table->string('normalized_name', 80);
            $table->foreignId('default_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'normalized_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
