<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind', 16);
            $table->string('iban', 34)->unique();
            $table->char('default_currency', 3)->default('EUR');
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
