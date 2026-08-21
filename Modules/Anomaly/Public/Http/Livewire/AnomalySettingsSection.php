<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Services\AnomalySuppressionRuleQuery;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AnomalySettingsSection extends Component
{
    use DispatchesToast;

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
        WriteUserPreference $writeUserPreference,
        BusDispatcher $bus,
    ): void {
        $this->saveError = '';
        $this->saved = false;

        $sensitivity = $this->anomalySensitivityPercent;
        $floor = $this->anomalyMinAmountMinor;

        if ($sensitivity < 1 || $sensitivity > 100) {
            $this->saveError = Lang::get('anomaly::settings.errors.sensitivity_range');

            return;
        }
        if ($floor < 0) {
            $this->saveError = Lang::get('anomaly::settings.errors.min_amount_negative');

            return;
        }

        $user = $currentUser->user();

        // Read before the write: the write itself is what makes this no longer
        // a first activation.
        $backfilledAt = $db->connection()->table('users')
            ->where('id', $user->id)
            ->value('anomaly_backfilled_at');

        ($writeUserPreference)($user->id, [
            'anomaly_sensitivity_percent' => $sensitivity,
            'anomaly_min_amount_minor' => $floor,
        ]);

        if ($backfilledAt === null) {
            $bus->dispatch(new BackfillAnomaliesJob($user->id));
        }

        $this->saved = true;
    }

    // Removing the rule does NOT re-open the originating alert; that is the
    // sibling undoSuppression() path.
    public function removeSuppressionRule(
        int $ruleId,
        CurrentUser $currentUser,
        RemoveAnomalySuppressionRule $action,
    ): void {
        try {
            $action->removeRule($ruleId, $currentUser->user());
            $this->toast(Lang::get('anomaly::settings.suppression.removed_toast'));
        } catch (NotFoundHttpException) {
            // A rule that vanished between render and click is already in the
            // desired state, so a missing row is a no-op, not an error.
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
