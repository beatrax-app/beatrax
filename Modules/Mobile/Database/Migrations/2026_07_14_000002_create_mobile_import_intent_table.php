<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('mobile_import_intent', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->text('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('mobile_import_intent');
    }
};
