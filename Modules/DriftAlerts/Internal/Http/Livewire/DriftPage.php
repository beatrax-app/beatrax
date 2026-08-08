<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Anomaly\Public\Actions\AcknowledgeAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlert;
use Modules\Anomaly\Public\Actions\DismissAnomalyAlertAsExpected;
use Modules\Anomaly\Public\Actions\RemoveAnomalySuppressionRule;
use Modules\Anomaly\Public\Actions\SnoozeAnomalyAlert;
use Modules\Anomaly\Public\Services\AnomalyAlertQuery;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForAlert;

/**
 * @link ../../../../../.docs/features/drift-alerts/architecture.md
 */
final class DriftPage extends Component
{
    use DispatchesToast;

    private const int MAX_UNTIL_MONTHS = 6;

    // open (default) / history / dismissed, persisted via #[Url] so
    // back-button and bookmarks behave.
    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    // drift (default, preserves existing bookmarks) or anomaly, persisted
    // via #[Url] so ?type=anomaly deep-links the Unusual charges view.
    #[Url(as: 'type', except: 'drift')]
    public string $type = 'drift';

    public ?int $cursorId = null;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'history', 'dismissed'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->cursorId = null;
    }

    public function setType(string $type): void
    {
        if (! in_array($type, ['drift', 'anomaly'], true)) {
            return;
        }
        $this->type = $type;
        $this->cursorId = null;
    }

    public function acknowledgeAnomaly(int $alertId, CurrentUser $currentUser, AcknowledgeAnomalyAlert $action): void
    {
        $this->acknowledgeAlert($alertId, $currentUser, $action);
    }

    public function snoozeAnomaly(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeAnomalyAlert $action, Clock $clock): void
    {
        $this->snoozeAlert($alertId, $untilIso, $currentUser, $action, $clock);
    }

    public function dismissAnomaly(int $alertId, CurrentUser $currentUser, DismissAnomalyAlert $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed'));
    }

    public function markAnomalyExpected(int $alertId, CurrentUser $currentUser, DismissAnomalyAlertAsExpected $action): void
    {
        $ruleWritten = ($action)($alertId, $currentUser->user());

        if ($ruleWritten) {
            // The "Undo" affordance re-opens the anomaly and deletes the
            // suppression rule the dismissal just created.
            $this->dispatch('toast', message: Lang::get('drift-alerts::alerts.toasts.suppression_added'), undo: 'undoAnomalySuppression', undoArg: $alertId);

            return;
        }

        // No suppression rule could be written (the charge amount is
        // unresolvable, e.g. its transaction was deleted). The dismissal
        // still stands — surface that honestly rather than promising a
        // mute + an Undo that would delete nothing.
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed_expected'));
    }

    public function undoAnomalySuppression(int $alertId, CurrentUser $currentUser, RemoveAnomalySuppressionRule $action): void
    {
        $action->undoSuppression($alertId, $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.reopened'));
    }

    public function acknowledge(int $alertId, CurrentUser $currentUser, AcknowledgeDriftAlert $action): void
    {
        $this->acknowledgeAlert($alertId, $currentUser, $action);
    }

    public function snooze(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeDriftAlert $action, Clock $clock): void
    {
        $this->snoozeAlert($alertId, $untilIso, $currentUser, $action, $clock);
    }

    // Shared by the drift and anomaly streams: both acknowledge an alert by
    // invoking their own action collaborator, then surface the same toast.
    private function acknowledgeAlert(int $alertId, CurrentUser $currentUser, callable $action): void
    {
        $action($alertId, $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.acknowledged'));
    }

    // Bounds the accepted range here (see the class @link) so a tampered
    // Livewire payload with a past or unbounded-future timestamp never
    // reaches the action — defence in depth on top of the action's own
    // server-side bound. Shared by both streams.
    private function snoozeAlert(int $alertId, string $untilIso, CurrentUser $currentUser, callable $action, Clock $clock): void
    {
        try {
            $until = CarbonImmutable::parse($untilIso);
        } catch (\Throwable) {
            return;
        }

        $now = $clock->now();
        if ($until->lessThanOrEqualTo($now) || $until->greaterThan($now->addMonths(self::MAX_UNTIL_MONTHS))) {
            return;
        }

        $action($alertId, $currentUser->user(), $until);
        $this->toast(Lang::get('drift-alerts::alerts.toasts.snoozed'));
    }

    public function dismissAsCancelled(int $alertId, CurrentUser $currentUser, DismissDriftAlertAsCancelled $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed_cancelled'));
    }

    public function modelCancelInForecast(
        int $alertId,
        CurrentUser $currentUser,
        CreateCancellationScenarioForAlert $action,
        Redirector $redirector,
    ): mixed {
        $newId = ($action)($alertId, $currentUser->user());

        return $redirector->to('/forecast?scenarioId='.$newId);
    }

    public function render(
        CurrentUser $currentUser,
        DriftAlertQuery $query,
        CancellationImpactQuery $impact,
        ViewFactory $views,
        Clock $clock,
        AnomalyAlertQuery $anomalyQuery,
    ): View {
        $user = $currentUser->user();

        // Snooze targets are domain timestamps computed server-side off
        // the injected clock so `CarbonImmutable::setTestNow()` stays
        // deterministic across the test suite. Shared by both streams.
        $now = $clock->now();
        $snoozeTargets = [
            '1w' => $now->addWeek()->toIso8601String(),
            '1m' => $now->addMonth()->toIso8601String(),
            '3m' => $now->addMonths(3)->toIso8601String(),
        ];

        if ($this->type === 'anomaly') {
            $anomalyRows = match ($this->tab) {
                'history' => $anomalyQuery->historyForUser($user, $this->cursorId),
                'dismissed' => $anomalyQuery->dismissedForUser($user, $this->cursorId),
                default => $anomalyQuery->openForUser($user, $this->cursorId),
            };

            $view = $views->make('drift-alerts::livewire.drift-page', [
                'type' => 'anomaly',
                'tab' => $this->tab,
                'anomalyRows' => $anomalyRows,
                'snoozeTargets' => $snoozeTargets,
                'rows' => [],
                'grouped' => [],
                'seriesStates' => [],
                'impactBySeriesId' => [],
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => Lang::get('drift-alerts::alerts.page_title').' · Beatrax']);

            return $view;
        }

        $rows = match ($this->tab) {
            'history' => $query->historyForUser($user, $this->cursorId),
            'dismissed' => $query->dismissedForUser($user, $this->cursorId),
            default => $query->openForUser($user, $this->cursorId),
        };

        $grouped = $this->tab === 'open' ? $query->groupedBySeriesForUser($user) : [];

        // Series states are surfaced to the renderer so the
        // "Cadence flipped" cross-reference hint fires on rows whose
        // underlying series is in state='cadence_changed'.
        $seriesIds = [];
        foreach ($rows as $alert) {
            $seriesIds[] = $alert->recurringSeriesId;
        }
        foreach ($grouped as $list) {
            foreach ($list as $alert) {
                $seriesIds[] = $alert->recurringSeriesId;
            }
        }
        $seriesStates = $seriesIds === [] ? [] : $query->seriesStatesForUser($user, $seriesIds);

        // One CancellationImpactDto per distinct series id, keyed on
        // recurringSeriesId so the partial pulls the projection inline
        // without a per-row query — the batched forSeriesIds() call
        // collapses what would otherwise be N separate SELECTs into one.
        $uniqueSeriesIds = array_values(array_unique($seriesIds));
        /** @var array<int, CancellationImpactDto> $impactBySeriesId */
        $impactBySeriesId = $uniqueSeriesIds === []
            ? []
            : $impact->forSeriesIds($uniqueSeriesIds, $user);

        $view = $views->make('drift-alerts::livewire.drift-page', [
            'type' => 'drift',
            'rows' => $rows,
            'tab' => $this->tab,
            'grouped' => $grouped,
            'snoozeTargets' => $snoozeTargets,
            'seriesStates' => $seriesStates,
            'impactBySeriesId' => $impactBySeriesId,
            'anomalyRows' => [],
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('drift-alerts::alerts.page_title').' · Beatrax']);

        return $view;
    }
}
