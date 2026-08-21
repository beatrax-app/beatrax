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
            $table->string('slug');
            $table->string('kind', 16);
            $table->string('iban', 34);
            $table->char('default_currency', 3)->default('EUR');
            $table->timestamps();

            $table->index(['user_id', 'kind']);

            // Per-user, so two users on one instance can each own an account
            // sharing an IBAN or slug — a joint bank account, say.
            $table->unique(['user_id', 'iban']);
            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
