<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\Enums\DismissedAs;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Internal\Support\SuppressionRuleKeyResolver;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class DismissAnomalyAlertAsExpected
{
    public function __construct(
        private AnomalyAlertStateMachine $stateMachine,
        private Dispatcher $events,
        private Clock $clock,
        private DatabaseManager $db,
        private SuppressionRuleKeyResolver $ruleKeys,
    ) {}

    // Returns TRUE only when a suppression rule was actually written, so the
    // caller does not promise "rule added" when nothing was muted.
    public function __invoke(int $alertId, User $user): bool
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
        }

        // A second tab, or the paired device, acting on a row this one still
        // shows as open is a no-op, not a 500: acknowledged is terminal.
        if (! AnomalyAlertState::from($alert->state)->allows(AnomalyAlertState::Dismissed)) {
            return false;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            AnomalyAlertState::Dismissed->value,
            'user_dismissed_expected',
            'user',
            null,
            ['dismissed_as' => DismissedAs::Expected->value, 'actioned_at' => $now->toDateTimeString()],
        );

        $rulesWritten = $this->insertSuppressionRules($alert, $user, $now->toDateTimeString());

        $this->events->dispatch(new AnomalyAlertDismissed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            dismissedAs: DismissedAs::Expected,
        ));

        return $rulesWritten;
    }

    private function insertSuppressionRules(AnomalyAlert $alert, User $user, string $nowString): bool
    {
        $key = $this->ruleKeys->forAlert($alert, $user);
        if ($key === null) {
            return false;
        }

        // Existence-checked rather than a UNIQUE index: SQLite treats NULL
        // counterparty_id values as distinct, so the index would not dedupe
        // the normalized-name fallback rules on re-dismissal.
        $connection = $this->db->connection();
        $wrote = false;

        foreach ($key->detectors as $detector) {
            if ($key->scope($connection->table('anomaly_suppression_rules'), $user->id, $detector)->exists()) {
                continue;
            }

            $row = [
                'counterparty_id' => $key->counterpartyId,
                'detector' => $detector->value,
                'direction' => $key->direction,
                'amount_band_low_minor' => $key->bandLowMinor,
                'amount_band_high_minor' => $key->bandHighMinor,
                'currency' => $key->currency,
                'source_anomaly_alert_id' => $alert->id,
                'created_at' => $nowString,
                'updated_at' => $nowString,
            ];
            // Derived rather than taken from the autoincrement: two devices
            // used while apart both take the next one, and this table declares
            // no unique index to tell the two rows apart. The alert's own id is
            // already derived, so both devices compute this one alike.
            $ruleId = DerivedRowId::for('anomaly_suppression_rules', [
                'source_anomaly_alert_id' => $alert->id,
                'detector' => $detector->value,
            ]);

            $connection->table('anomaly_suppression_rules')
                ->insert([...$row, 'id' => $ruleId, 'user_id' => $user->id]);
            $wrote = true;

            // Without this the alert and its dismissal converged and the mute
            // did not, so the next charge re-raised the anomaly on the peer and
            // synced it back to the device that had already muted it.
            $this->events->dispatch(new EntityMutated(
                table: 'anomaly_suppression_rules',
                pk: $ruleId,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: $row,
            ));
        }

        return $wrote;
    }
}
