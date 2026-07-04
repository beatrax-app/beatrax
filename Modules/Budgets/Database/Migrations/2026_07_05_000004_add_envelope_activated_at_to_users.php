<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds the per-user envelope-activation genesis anchor `envelope_activated_at`
 * to `users` (D-12b; RESEARCH.md Pitfall 2 / Open Question 1).
 *
 * NULL until the pot-archive cutover (Plan 06) stamps it — the same one-shot
 * event that performs the D-14 category-linked-pot archive. After that,
 * `CarryoverQuery`'s genesis-to-target fold bounds its past walk at this
 * timestamp's containing period: "genesis carried_in is always 0" (D-12b)
 * has a fixed point to anchor against instead of drifting as new
 * `envelope_assignments` rows accumulate.
 *
 * Mirrors `users.anomaly_backfilled_at` (Modules/Anomaly, Phase 9) — a
 * one-shot per-user marker gating a backfill/genesis job, added to `users`
 * from a non-Core module. Anchored directly after that column so the two
 * "activation" markers for adjacent budgeting-adjacent features sit
 * together.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->timestamp('envelope_activated_at')
                ->nullable()
                ->after('anomaly_backfilled_at');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn(['envelope_activated_at']);
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
