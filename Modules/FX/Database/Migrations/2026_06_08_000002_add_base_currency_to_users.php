<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds per-user FX preference columns to `users`.
 *
 * `base_currency` — ISO-4217 currency code the user wants all roll-ups expressed
 * in. Nullable so existing rows stay valid without a backfill; the Eloquent
 * `$attributes` default on `User` supplies 'EUR' so in-memory instances always
 * have a value. No DB-level DEFAULT on this column — Eloquent owns the default
 * (FX-01, D-04). Anchored after `default_currency_view` so the two
 * currency-related columns sit together on the `users` table.
 *
 * `fx_online_enabled` — opt-in flag for outbound rate fetches (D-04 / local-only
 * ethos). Default false: users see bundled rates unless they explicitly opt in.
 *
 * `down()` drops both columns in reverse order.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->char('base_currency', 3)
                ->nullable()
                ->after('default_currency_view');

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
