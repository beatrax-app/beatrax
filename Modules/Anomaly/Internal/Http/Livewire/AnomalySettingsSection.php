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

// Follows the SettingsPage pattern: NO constructor DI — collaborators
// arrive as parameters on each action/render method. The first save while
// `users.anomaly_backfilled_at` is still null dispatches the full-history
// backfill exactly once; subsequent saves never re-dispatch.
final class AnomalySettingsSection extends Component
{
    public int $anomalySensitivityPercent = 50;

    public int $anomalyMinAmountMinor = 1000;

    public string $saveError = '';

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

        // Server-side bounds: a tampered payload is rejected before any
        // write reaches the database.
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
        // whether this is the first save.
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

    // The originating anomaly stays dismissed; the user is just pruning
    // their mute list.
    public function removeSuppressionRule(
        int $ruleId,
        CurrentUser $currentUser,
        RemoveAnomalySuppressionRule $action,
    ): void {
        try {
            $action->removeRule($ruleId, $currentUser->user());
            $this->dispatch('toast', message: 'Rule removed');
        } catch (NotFoundHttpException) {
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
