<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Anomaly\Internal\Jobs\BackfillAnomaliesJob;
use Modules\Anomaly\Internal\Support\AnomalySensitivity;
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

    public int $anomalySensitivityPercent = AnomalySensitivity::DEFAULT_PERCENT;

    public int $anomalyMinAmountMinor = AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR;

    public string $saveError = '';

    public bool $saved = false;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        $user = $currentUser->user();

        $row = $db->connection()->table('users')
            ->where('id', $user->id)
            ->first(['anomaly_sensitivity_percent', 'anomaly_min_amount_minor']);

        if ($row !== null) {
            $this->anomalySensitivityPercent = AnomalySensitivity::fromStored($row->anomaly_sensitivity_percent ?? null)->percent;
            $this->anomalyMinAmountMinor = is_numeric($row->anomaly_min_amount_minor ?? null)
                ? (int) $row->anomaly_min_amount_minor
                : AnomalySensitivity::DEFAULT_MIN_AMOUNT_MINOR;
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

        $sensitivity = AnomalySensitivity::tryFrom($this->anomalySensitivityPercent);
        $floor = $this->anomalyMinAmountMinor;

        if ($sensitivity === null) {
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
            'anomaly_sensitivity_percent' => $sensitivity->percent,
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
