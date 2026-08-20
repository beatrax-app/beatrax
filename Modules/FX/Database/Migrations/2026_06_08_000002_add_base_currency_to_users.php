<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            // Nullable so existing rows need no backfill, and deliberately with
            // no DB DEFAULT: User's Eloquent $attributes owns 'EUR', and two
            // competing defaults would drift.
            $table->char('base_currency', 3)
                ->nullable()
                ->after('default_currency_view');

            // Off by default: rate fetches are the only outbound traffic the app
            // makes, so they are opt-in and bundled rates are the fallback.
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
