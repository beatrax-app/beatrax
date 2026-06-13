<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Settings "Anomaly detection" section (D-11/D-18). Exposes the alert
 * sensitivity, the global minimum-charge floor, and the user-visible +
 * removable suppression-rules list so nothing is muted invisibly
 * (a hard requirement for a fraud-adjacent feature).
 *
 * Follows the SettingsPage pattern: NO constructor DI — collaborators
 * arrive as parameters on each action/render method.
 *
 * On the FIRST save while `users.anomaly_backfilled_at` is still null,
 * the full-history `BackfillAnomaliesJob` is dispatched once (D-13). The
 * job is `ShouldBeUniqueUntilProcessing` keyed on userId and additionally
 * guards wholesale on the same timestamp, so a duplicate dispatch is a
 * no-op; subsequent saves (after the backfill stamps the column) never
 * re-dispatch.
 *
 * Sensitivity is validated server-side to [1,100] and the floor to >= 0
 * (T-09-19) — a tampered Livewire payload cannot persist an out-of-range
 * value.
 */
final class AnomalySettingsSection extends Component
{
    /** Alert sensitivity percent (1–100). Default 50. */
    public int $anomalySensitivityPercent = 50;

    /** Minimum-charge floor in minor units (>= 0). Default 1000 (€10.00). */
    public int $anomalyMinAmountMinor = 1000;

    /** Inline validation error for the save form. */
    public string $saveError = '';

    /** Success flash after a save. */
    public bool $saved = false;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $user = $currentUser->user();

        $row = $db->connection()->table('users')
            ->where('id', $user->id)
            ->first(['anomaly_sensitivity_percent', 'anomaly_min_amount_minor']);

        if ($row !== null) {
            $this->anomalySensitivityPercent = is_numeric($row->anomaly_sensitivity_percent ?? null)
                ? (int) $row->anomaly_sensitivity_percent
                : 50;
            $this->anomalyMinAmountMinor = is_numeric($row->anomaly_min_amount_minor ?? null)
                ? (int) $row->anomaly_min_amount_minor
                : 1000;
        }
    }

    /**
     * Persist sensitivity + floor (server-validated), and on first
     * activation dispatch the full-history backfill exactly once.
     */
    public function save(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Clock $clock,
        BusDispatcher $bus,
    ): void {
        $this->saveError = '';
        $this->saved = false;

        $sensitivity = $this->anomalySensitivityPercent;
        $floor = $this->anomalyMinAmountMinor;

        // Server-side bounds (T-09-19). A tampered payload is rejected
        // before any write.
        if ($sensitivity < 1 || $sensitivity > 100) {
            $this->saveError = 'Sensitivity must be between 1 and 100.';

            return;
        }
        if ($floor < 0) {
            $this->saveError = 'Minimum charge amount cannot be negative.';

            return;
        }

        $user = $currentUser->user();

        // Read the first-activation guard BEFORE the write so we know
        // whether this is the first save (D-13).
        $backfilledAt = $db->connection()->table('users')
            ->where('id', $user->id)
            ->value('anomaly_backfilled_at');

        $db->connection()->table('users')
            ->where('id', $user->id)
            ->update([
                'anomaly_sensitivity_percent' => $sensitivity,
                'anomaly_min_amount_minor' => $floor,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        // First activation: kick off the full-history backfill once. The
        // job stamps anomaly_backfilled_at on completion, so subsequent
        // saves see a non-null guard and do not re-dispatch.
        if ($backfilledAt === null) {
            $bus->dispatch(new BackfillAnomaliesJob($user->id));
        }

        $this->saved = true;
    }

    /**
     * Remove a single suppression rule from the settings list (D-18). The
     * originating anomaly stays dismissed; the user is just pruning their
     * mute list. Cross-user / unknown ids are silently ignored in the UI.
     */
    public function removeSuppressionRule(
        int $ruleId,
        CurrentUser $currentUser,
        RemoveAnomalySuppressionRule $action,
    ): void {
        try {
            $action->removeRule($ruleId, $currentUser->user());
            $this->dispatch('toast', message: 'Rule removed');
        } catch (NotFoundHttpException) {
            // Cross-user / unknown rule — silently ignore in the UI layer.
        }
    }

    public function render(
        CurrentUser $currentUser,
        AnomalySuppressionRuleQuery $rules,
        ViewFactory $views,
    ): View {
        $suppressionRules = $rules->forUser($currentUser->user());

        return $views->make('anomaly::livewire.anomaly-settings-section', [
            'suppressionRules' => $suppressionRules,
        ]);
    }
}
