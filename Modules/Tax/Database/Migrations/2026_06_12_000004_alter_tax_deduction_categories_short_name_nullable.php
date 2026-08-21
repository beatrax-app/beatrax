<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A short name is optional in the domain model: both corpus entries and
// user-created categories may omit one.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->string('short_name', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->schema()->table('tax_deduction_categories', static function (Blueprint $table): void {
            $table->string('short_name', 32)->nullable(false)->change();
        });
    }
};
