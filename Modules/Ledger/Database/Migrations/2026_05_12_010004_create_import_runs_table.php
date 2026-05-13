<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('source_format', 32);
            $table->string('raw_file_path');
            $table->char('sha256', 64);
            $table->dateTime('uploaded_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('status', 16)->default('previewed');
            $table->timestamps();

            $table->unique(['user_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
