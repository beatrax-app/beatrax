<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the drift_alert_transitions append-only audit table — one
 * row per state transition produced by `DriftAlertStateMachine`.
 *
 * Each row carries `from_state`, `to_state`, the actor (`user` or
 * `detector`), and the structured `transition_reason`
 * (`user_action`, `user_dismissed_cancelled`, `detector_opened`,
 * `detector_revived_snooze`). Optional `notes` text holds longer-form
 * context surfaced by the drift page. `transitioned_at` is the
 * canonical timestamp; `created_at` + `updated_at` fall through to
 * Laravel convention.
 *
 * The (drift_alert_id, transitioned_at) index supports the drill-in
 * audit query that walks the history of a single alert in
 * chronological order.
 *
 * No DDL trigger on the transitions table — append-only behaviour is
 * a project-wide schema invariant rather than a per-table SQL guard.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('drift_alert_transitions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('drift_alert_id')->constrained('drift_alerts')->cascadeOnDelete();
            $table->string('from_state', 24);
            $table->string('to_state', 24);
            $table->string('transition_reason', 64);
            $table->string('actor', 16);
            $table->timestamp('transitioned_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['drift_alert_id', 'transitioned_at']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('drift_alert_transitions');
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
