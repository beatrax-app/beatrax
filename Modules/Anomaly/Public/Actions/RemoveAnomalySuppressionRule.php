<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RemoveAnomalySuppressionRule
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AnomalyAlertStateMachine $stateMachine,
    ) {}

    public function removeRule(int $ruleId, User $user): void
    {
        /** @var AnomalySuppressionRule|null $rule */
        $rule = AnomalySuppressionRule::query()
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->first();

        if ($rule === null) {
            throw new NotFoundHttpException('Suppression rule not found.');
        }

        $this->db->connection()->table('anomaly_suppression_rules')
            ->where('id', $ruleId)
            ->where('user_id', $user->id)
            ->delete();
    }

    // The only caller of the state machine's `dismissed -> open` edge.
    public function undoSuppression(int $alertId, User $user): void
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
        }

        $this->db->connection()->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->where('source_anomaly_alert_id', $alertId)
            ->delete();

        if ($alert->state === AnomalyAlertState::Dismissed->value) {
            $this->stateMachine->transition(
                $alert,
                AnomalyAlertState::Open->value,
                'user_undo_suppression',
                'user',
            );
        }
    }
}
