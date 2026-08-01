<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the merchant_aliases table — per-user remappings of cryptic
 * bank-statement descriptions to friendly merchant names. Rendered at
 * preview / list time via the resolver precedence chain (exact alias →
 * generalized alias → community corpus → raw description fallback).
 * Never mutates the stored `transactions.description`; aliases are
 * pure presentation-layer rewrites that the user can edit or delete at
 * any time from `Settings → Aliases`.
 *
 * `pattern` is the immutable first-seen raw description; the
 * resolver's exact-match query hits this column. `generalized_pattern`
 * is the `PatternGeneralizer` output and is the column the user edits
 * inline in the Settings page to widen / narrow which other rows the
 * alias matches; the (user_id, generalized_pattern) index supports
 * the per-user fuzzy scan.
 *
 * `merged_from` is a nullable JSON array of `{id, pattern,
 * friendly_name}` triples preserving provenance after a bulk-merge in
 * Settings → Aliases. The composite UNIQUE on (user_id, pattern)
 * blocks duplicate-pattern rows for the same user at the DB layer.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('merchant_aliases', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('pattern');
            $table->string('generalized_pattern');
            $table->string('friendly_name');
            $table->json('merged_from')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'pattern']);
            $table->index(['user_id', 'generalized_pattern']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('merchant_aliases');
    }
};
