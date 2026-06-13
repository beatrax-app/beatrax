<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Core\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Removes anomaly suppression rules (D-18). There are two distinct paths,
 * both user-scoped (`where('id'|'source_anomaly_alert_id')->where('user_id')`)
 * and both raising NotFoundHttpException on a cross-user / unknown target:
 *
 *   - `removeRule` (settings "Remove"): deletes a single rule by id. The
 *     anomaly that created it STAYS dismissed — the user is just pruning
 *     their mute list.
 *
 *   - `undoSuppression` (post-dismiss toast "Undo"): deletes every rule
 *     created by a given dismissal (matched on `source_anomaly_alert_id`)
 *     AND re-opens the anomaly via the state machine's diverging
 *     `dismissed -> open` edge (D-18). This is the only place that edge is
 *     exercised.
 */
final class RemoveAnomalySuppressionRule
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AnomalyAlertStateMachine $stateMachine,
    ) {}

    /**
     * Settings path: delete a single suppression rule by id. The
     * originating anomaly is left dismissed.
     */
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

    /**
     * Undo path: delete every suppression rule created by the dismissal of
     * `$alertId`, then re-open the anomaly (dismissed -> open via the state
     * machine). Raises NotFoundHttpException if the alert is unknown or
     * belongs to another user.
     */
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

        // Only re-open if the alert is actually dismissed; the state
        // machine rejects any other source state for the dismissed->open
        // edge.
        if ($alert->state === 'dismissed') {
            $this->stateMachine->transition(
                $alert,
                'open',
                'user_undo_suppression',
                'user',
            );
        }
    }
}
