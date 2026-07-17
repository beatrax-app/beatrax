<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds `envelope_settings.threshold_percent` (D-20) — the per-envelope
 * over-budget notification threshold Req 6's nudge trigger (plan 18-07)
 * reads. `envelope_settings` is the LIVE per-(user, category) settings row of
 * the post-D-13-cutover envelope model (it already holds `overspend_mode`),
 * making it the correct home for this setting now that the flat
 * `category_budgets` table is write-dead (see the sibling
 * 2026_07_17_000002 drop migration).
 *
 * Nullable with NO database default: null means "use the D-20 90% default",
 * which keeps the single default in ONE place
 * (`CarryoverQuery::DEFAULT_NOTIFY_THRESHOLD_PERCENT`) rather than duplicating
 * it into the schema, so a future default change applies to every existing
 * envelope without a data migration. Being nullable, it stays OUT of
 * `envelope_settings`'s `_create_required` sync set (asserted by
 * `EnvelopeSettingsRegistryColumnsTest`).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('envelope_settings', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('threshold_percent')->nullable();
        });
    }

    public function down(): void
    {
        $this->schema()->table('envelope_settings', static function (Blueprint $table): void {
            $table->dropColumn('threshold_percent');
        });
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
