<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // No DB DEFAULT on purpose: User's Eloquent $attributes owns 'EUR',
            // and two competing defaults drift.
            $table->char('base_currency', 3)
                ->nullable()
                ->after('default_currency_view');

            // Rate fetches are the app's only outbound traffic, so they are
            // opt-in with bundled rates as the fallback.
            $table->boolean('fx_online_enabled')
                ->default(false)
                ->after('base_currency');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['fx_online_enabled', 'base_currency']);
        });
    }
};
