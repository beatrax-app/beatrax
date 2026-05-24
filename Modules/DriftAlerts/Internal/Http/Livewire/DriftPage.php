<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use Livewire\Attributes\Url;
use Livewire\Component;
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
 * `/drift` page — the dedicated drift-alerts surface. Three tabs
 * (Open / History / Dismissed) backed by `DriftAlertQuery` reads.
 * Per-row Acknowledge / Snooze (1w / 1m / 3m popover) / "I cancelled
 * this" actions dispatch a toast on success.
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
    ): View {
        $user = $currentUser->user();

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

        // Snooze targets are domain timestamps computed server-side off
        // the injected clock so `CarbonImmutable::setTestNow()` stays
        // deterministic across the test suite.
        $now = $clock->now();
        $snoozeTargets = [
            '1w' => $now->addWeek()->toIso8601String(),
            '1m' => $now->addMonth()->toIso8601String(),
            '3m' => $now->addMonths(3)->toIso8601String(),
        ];

        $view = $views->make('drift-alerts::livewire.drift-page', [
            'rows' => $rows,
            'tab' => $this->tab,
            'grouped' => $grouped,
            'snoozeTargets' => $snoozeTargets,
            'seriesStates' => $seriesStates,
            'impactBySeriesId' => $impactBySeriesId,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Drift alerts · beatrax']);

        return $view;
    }
}
