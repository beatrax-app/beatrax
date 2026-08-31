<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Internal\Support\SuppressionRuleKeyResolver;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Models\AnomalySuppressionRule;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class RemoveAnomalySuppressionRule
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private AnomalyAlertStateMachine $stateMachine,
        private SuppressionRuleKeyResolver $ruleKeys,
        private Dispatcher $events,
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

        $this->announceDeleted($user, [$ruleId]);
    }

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

        $this->deleteRulesFor($alert, $user);

        if ($alert->state === AnomalyAlertState::Dismissed->value) {
            $this->stateMachine->transition(
                $alert,
                AnomalyAlertState::Open->value,
                'user_undo_suppression',
                'user',
            );
        }
    }

    // Deleting on source_anomaly_alert_id alone left the merchant muted after
    // the second of two dismissals in one band: the rule the second dedupes
    // onto still names the first alert. The mute's own shape is what identifies
    // it, and the source column stays provenance.
    private function deleteRulesFor(AnomalyAlert $alert, User $user): void
    {
        $connection = $this->db->connection();
        $key = $this->ruleKeys->forAlert($alert, $user);

        $claim = static function (Builder $claimed) use ($alert, $key, $user): void {
            $claimed->where('source_anomaly_alert_id', $alert->id);

            if ($key === null) {
                return;
            }

            foreach ($key->detectors as $detector) {
                $claimed->orWhere(fn (Builder $shape): Builder => $key->scope($shape, $user->id, $detector));
            }
        };

        // Read before the delete: the op log needs the ids, and after the
        // statement there is nothing left to name them.
        $ruleIds = $connection->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->where($claim)
            ->pluck('id')
            ->all();

        $connection->table('anomaly_suppression_rules')
            ->where('user_id', $user->id)
            ->where($claim)
            ->delete();

        $this->announceDeleted($user, array_values(array_map(self::toInt(...), $ruleIds)));
    }

    // An un-mute that stays local is the same defect as a mute that does: the
    // peer keeps suppressing a merchant the reader has un-suppressed.
    /**
     * @param  list<int>  $ruleIds
     */
    private function announceDeleted(User $user, array $ruleIds): void
    {
        foreach ($ruleIds as $ruleId) {
            $this->events->dispatch(new EntityMutated(
                table: 'anomaly_suppression_rules',
                pk: $ruleId,
                userId: $user->id,
                mutationType: 'delete',
            ));
        }
    }
}
