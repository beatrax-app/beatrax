<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the anomaly_suppression_rules table (D-17/D-18) — the
 * user-visible, undoable mute list produced when a user dismisses an
 * anomaly "as expected".
 *
 * A rule keys narrowly on merchant (`counterparty_id`) + amount band
 * (`amount_band_low_minor` .. `amount_band_high_minor`) + `detector`
 * + `direction`, so a genuinely larger/different later charge from the
 * same merchant still fires. Per D-18 nothing is muted invisibly — the
 * settings surface lists every rule and lets the user revoke it.
 *
 * `source_anomaly_alert_id` records the alert whose dismissal created
 * the rule (nullable so a manually-authored rule, or a rule whose
 * source alert was later deleted, stays valid). The FK is set null on
 * cascade-delete rather than removing the rule.
 *
 * Scoped per-user (`user_id`); the BelongsToUser global scope is a
 * secondary guard only — queue/console evaluation paths must carry an
 * explicit user_id filter (Counterparty cross-user posture).
 *
 * The (user_id, counterparty_id, detector) index supports the
 * evaluation-time "does a suppression rule match this candidate?"
 * lookup.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('anomaly_suppression_rules', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('counterparty_id')->nullable()->constrained('counterparties')->nullOnDelete();
            $table->string('detector', 16);
            $table->enum('direction', ['expense', 'income']);
            $table->bigInteger('amount_band_low_minor');
            $table->bigInteger('amount_band_high_minor');
            $table->char('currency', 3);
            $table->foreignId('source_anomaly_alert_id')->nullable()->constrained('anomaly_alerts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'counterparty_id', 'detector']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('anomaly_suppression_rules');
    }
};
