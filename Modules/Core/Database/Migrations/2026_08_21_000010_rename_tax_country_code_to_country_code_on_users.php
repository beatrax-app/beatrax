<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasColumn('users', 'tax_country_code')) {
            return;
        }

        // A rename, not an add-and-copy: the country a reader already chose
        // scopes their government and bank-fee classification, so losing it
        // silently reclassifies every future import.
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->renameColumn('tax_country_code', 'country_code');
        });
    }

    public function down(): void
    {
        if (! $this->schema()->hasColumn('users', 'country_code')) {
            return;
        }

        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->renameColumn('country_code', 'tax_country_code');
        });
    }
};
