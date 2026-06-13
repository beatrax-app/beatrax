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
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForAlert;

/**
 * `/drift` page — the unified alerts home (D-02). A top-level TYPE
 * switch ("Subscription drift" | "Unusual charges") selects which alert
 * stream is shown; under it the same three lifecycle tabs
 * (Open / History / Dismissed) apply to whichever type is active.
 *
 * The drift stream reads `DriftAlertQuery`; the anomaly stream reads the
 * Anomaly module's Public `AnomalyAlertQuery` (a sanctioned Public
 * crossing — the page is owned by DriftAlerts but composes Anomaly's
 * read surface, exactly as it already composes Recurring's series query).
 *
 * Per-row drift actions: Acknowledge / Snooze (1w / 1m / 3m popover) /
 * "I cancelled this". Per-row anomaly actions: Acknowledge / Snooze /
 * Mark as expected (creates a suppression rule + emits the "Undo" toast)
 * / Dismiss. Every action dispatches a toast on success.
 *
 * Service collaborators arrive as parameters on action methods and
 * `render()`. Constructor injection is banned on Livewire `Component`
 * subclasses by phpstan-strict-rules.
 */
final class DriftPage extends Component
{
    /**
     * Active tab. Three values: open (default) / history / dismissed.
     * Persisted via #[Url] so back-button and bookmarks behave.
     */
    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    /**
     * Active alert type. `drift` (default — preserves existing
     * bookmarks) or `anomaly`. Persisted via #[Url] so `?type=anomaly`
     * deep-links the Unusual charges view.
     */
    #[Url(as: 'type', except: 'drift')]
    public string $type = 'drift';

    /**
     * Cursor: previous page's tail drift_alerts.id. Null = first page.
     */
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
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Acknowledged');
    }

    public function snoozeAnomaly(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeAnomalyAlert $action, Clock $clock): void
    {
        // The snooze popover only ever emits the three server-computed
        // targets (1 week, 1 month, 3 months). A tampered Livewire
        // payload could deliver an arbitrary ISO8601 string — bound the
        // accepted range here so a past or unbounded-future timestamp
        // never reaches the action. (The action itself ALSO enforces the
        // (now, now+6mo] bound server-side, T-09-10; this is defence in
        // depth that keeps the UI from dispatching a doomed request.)
        try {
            $until = CarbonImmutable::parse($untilIso);
        } catch (\Throwable) {
            return;
        }

        $now = $clock->now();
        if ($until->lessThanOrEqualTo($now)) {
            return;
        }
        if ($until->greaterThan($now->addMonths(6))) {
            return;
        }

        ($action)($alertId, $currentUser->user(), $until);
        $this->dispatch('toast', message: 'Snoozed');
    }

    public function dismissAnomaly(int $alertId, CurrentUser $currentUser, DismissAnomalyAlert $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Dismissed');
    }

    public function markAnomalyExpected(int $alertId, CurrentUser $currentUser, DismissAnomalyAlertAsExpected $action): void
    {
        ($action)($alertId, $currentUser->user());
        // The "Undo" affordance re-opens the anomaly and deletes the
        // suppression rule the dismissal just created (D-18).
        $this->dispatch('toast', message: 'Suppression rule added — Undo', undo: 'undoAnomalySuppression', undoArg: $alertId);
    }

    public function undoAnomalySuppression(int $alertId, CurrentUser $currentUser, RemoveAnomalySuppressionRule $action): void
    {
        $action->undoSuppression($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Reopened');
    }

    public function acknowledge(int $alertId, CurrentUser $currentUser, AcknowledgeDriftAlert $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Acknowledged');
    }

    public function snooze(int $alertId, string $untilIso, CurrentUser $currentUser, SnoozeDriftAlert $action, Clock $clock): void
    {
        // The snooze popover only ever emits the three server-computed
        // targets (1 week, 1 month, 3 months) from $snoozeTargets. A
        // tampered Livewire payload could deliver an arbitrary ISO8601
        // string — bound the accepted range so a past timestamp or an
        // unbounded future timestamp can never reach the action.
        try {
            $until = CarbonImmutable::parse($untilIso);
        } catch (\Throwable) {
            return;
        }

        $now = $clock->now();
        if ($until->lessThanOrEqualTo($now)) {
            return;
        }
        if ($until->greaterThan($now->addMonths(6))) {
            return;
        }

        ($action)($alertId, $currentUser->user(), $until);
        $this->dispatch('toast', message: 'Snoozed');
    }

    public function dismissAsCancelled(int $alertId, CurrentUser $currentUser, DismissDriftAlertAsCancelled $action): void
    {
        ($action)($alertId, $currentUser->user());
        $this->dispatch('toast', message: 'Dismissed as cancelled');
    }

    /**
     * Phase 10 launchpad — invoke the Forecasting Public Action that
     * atomically creates a new scenario pre-seeded with a
     * `cancel_series` mutation for the alert's underlying series, then
     * redirect to `/forecast?scenarioId={new}`. The drift_alerts row
     * itself is NOT modified — modelling is non-destructive; the user
     * can still dismiss or acknowledge later.
     */
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
                // Drift-only collaborators the shared view guards on `type`.
                'rows' => [],
                'grouped' => [],
                'seriesStates' => [],
                'impactBySeriesId' => [],
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => 'Alerts · beatrax']);

            return $view;
        }

        $rows = match ($this->tab) {
            'history' => $query->historyForUser($user, $this->cursorId),
            'dismissed' => $query->dismissedForUser($user, $this->cursorId),
            default => $query->openForUser($user, $this->cursorId),
        };

        // Grouped-by-series view is only meaningful on the Open tab.
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

        // CancellationImpactQuery hand-off: one DTO per distinct series
        // id rendered on the page. The map keys on recurringSeriesId so
        // the partial pulls the projection inline without a per-row
        // query. Cross-user / missing series rows are silently absent
        // from the result — the partial guards every interpolation
        // behind `@if ($cancellationImpact !== null)`. The batched
        // `forSeriesIds` call collapses what would otherwise be N
        // separate queries (one per distinct series id) into a single
        // SELECT.
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
        $view->extends('layouts.app', ['title' => 'Alerts · beatrax']);

        return $view;
    }
}
