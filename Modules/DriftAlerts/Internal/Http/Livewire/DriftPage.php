<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Redirector;
use Livewire\Attributes\Locked;
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
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\SnoozeUntil;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\DriftAlerts\Public\Enums\DriftPageTab;
use Modules\DriftAlerts\Public\Enums\DriftPageType;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\Forecasting\Public\Actions\CreateScenarioFromTemplate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final class DriftPage extends Component
{
    use DispatchesToast;

    private const int PAGE_SIZE = 26;

    // Both stay strings on the wire: they are #[Url] state, so anyone can put
    // anything in the query string, and a bookmarked value outside the enum
    // has to land on the default view rather than fail the request.
    #[Url(as: 'tab', except: DriftPageTab::DEFAULT)]
    public string $tab = DriftPageTab::DEFAULT;

    #[Url(as: 'type', except: DriftPageType::DEFAULT)]
    public string $type = DriftPageType::DEFAULT;

    // A page count, not a keyset cursor: the cursor replaced the list with the
    // next page, so page 2 showed 4 rows and the first 26 vanished with no way
    // back. Re-reading id DESC from the top keeps every row already shown.
    // Locked because it is a SQL LIMIT and only loadMore() may move it.
    #[Locked]
    public int $pageSize = self::PAGE_SIZE;

    public function setTab(string $tab): void
    {
        if (DriftPageTab::tryFrom($tab) === null) {
            return;
        }
        $this->tab = $tab;
        $this->resetPaging();
    }

    public function setType(string $type): void
    {
        if (DriftPageType::tryFrom($type) === null) {
            return;
        }
        $this->type = $type;
        $this->resetPaging();
    }

    private function activeTab(): DriftPageTab
    {
        return DriftPageTab::tryFrom($this->tab) ?? DriftPageTab::Open;
    }

    private function activeType(): DriftPageType
    {
        return DriftPageType::tryFrom($this->type) ?? DriftPageType::Drift;
    }

    // A derived anomaly id is a 63-bit integer, and every value crossing this
    // boundary goes through JSON, whose numbers are IEEE doubles — anything
    // past 2^53 comes back from the browser silently rounded. So the id
    // travels as a STRING and is only an int again on this side of the wire.
    private static function anomalyId(int|string $alertId): int
    {
        return is_numeric($alertId) ? (int) $alertId : 0;
    }

    public function loadMore(): void
    {
        $this->pageSize += self::PAGE_SIZE;
    }

    private function resetPaging(): void
    {
        $this->pageSize = self::PAGE_SIZE;
    }

    public function acknowledgeAnomaly(int|string $alertId, CurrentUser $currentUser, AcknowledgeAnomalyAlert $action): void
    {
        $this->acknowledgeAlert(self::anomalyId($alertId), $currentUser, $action);
    }

    public function snoozeAnomaly(int|string $alertId, string $untilIso, CurrentUser $currentUser, SnoozeAnomalyAlert $action, Clock $clock): void
    {
        $this->snoozeAlert(self::anomalyId($alertId), $untilIso, $currentUser, $action, $clock);
    }

    public function dismissAnomaly(int|string $alertId, CurrentUser $currentUser, DismissAnomalyAlert $action): void
    {
        if (! $this->apply(fn () => ($action)(self::anomalyId($alertId), $currentUser->user()))) {
            return;
        }
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed'));
    }

    public function markAnomalyExpected(int|string $alertId, CurrentUser $currentUser, DismissAnomalyAlertAsExpected $action): void
    {
        $ruleWritten = false;
        if (! $this->apply(function () use ($alertId, $currentUser, $action, &$ruleWritten): void {
            $ruleWritten = ($action)(self::anomalyId($alertId), $currentUser->user());
        })) {
            return;
        }

        if ($ruleWritten) {
            $this->toastWithUndo(
                Lang::get('drift-alerts::alerts.toasts.suppression_added'),
                undoAction: 'undoAnomalySuppression',
                undoPayload: (string) $alertId,
            );

            return;
        }

        // No rule could be written (unresolvable charge amount), so this toast
        // promises no mute and offers no Undo that would delete nothing.
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed_expected'));
    }

    public function undoAnomalySuppression(int|string $alertId, CurrentUser $currentUser, RemoveAnomalySuppressionRule $action): void
    {
        if (! $this->apply(fn () => $action->undoSuppression(self::anomalyId($alertId), $currentUser->user()))) {
            return;
        }
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

    private function acknowledgeAlert(int $alertId, CurrentUser $currentUser, callable $action): void
    {
        $write = function () use ($action, $alertId, $currentUser): void {
            $action($alertId, $currentUser->user());
        };

        if (! $this->apply($write)) {
            return;
        }
        $this->toast(Lang::get('drift-alerts::alerts.toasts.acknowledged'));
    }

    // A row the reader is looking at can already be gone — actioned in another
    // tab, swept, or replayed away by the paired device. False means nothing
    // was written, so the caller raises no success toast and the reader is
    // told calmly instead of being handed a 404 page.
    /**
     * @param  callable(): void  $write
     */
    private function apply(callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (NotFoundHttpException) {
            $this->toast(Lang::get('drift-alerts::alerts.toasts.gone'));

            return false;
        }
    }

    // A malformed or out-of-range date is dropped before it reaches an action.
    // Both actions refuse the same value themselves; this only keeps the UI
    // from raising on a stale popover.
    private function snoozeAlert(int $alertId, string $untilIso, CurrentUser $currentUser, callable $action, Clock $clock): void
    {
        $until = SafeDate::parseOrNull($untilIso);
        if ($until === null || SnoozeUntil::tryFrom($until, $clock->now()) === null) {
            return;
        }

        $write = function () use ($action, $alertId, $currentUser, $until): void {
            $action($alertId, $currentUser->user(), $until);
        };

        if (! $this->apply($write)) {
            return;
        }
        $this->toast(Lang::get('drift-alerts::alerts.toasts.snoozed'));
    }

    public function dismissAsCancelled(int $alertId, CurrentUser $currentUser, DismissDriftAlertAsCancelled $action): void
    {
        if (! $this->apply(fn () => ($action)($alertId, $currentUser->user()))) {
            return;
        }
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed_cancelled'));
    }

    public function modelCancelInForecast(
        int $alertId,
        CurrentUser $currentUser,
        CreateScenarioFromTemplate $action,
        Redirector $redirector,
    ): mixed {
        $newId = 0;
        if (! $this->apply(function () use ($alertId, $currentUser, $action, &$newId): void {
            $newId = $action->forDriftAlert($alertId, $currentUser->user());
        })) {
            return null;
        }

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

        $snoozeTargets = SnoozeWindow::targetsFrom($clock->now());

        if ($this->activeType() === DriftPageType::Anomaly) {
            // A growing window read from the top, not a cursor: following the
            // cursor replaced the list with the next page, so every press
            // dropped the rows already shown and offered no way back.
            $anomalyLookahead = $this->pageSize + 1;

            $anomalyRows = match ($this->activeTab()) {
                DriftPageTab::History => $anomalyQuery->historyForUser($user, null, null, $anomalyLookahead),
                DriftPageTab::Dismissed => $anomalyQuery->dismissedForUser($user, null, null, $anomalyLookahead),
                DriftPageTab::Open => $anomalyQuery->openForUser($user, null, null, $anomalyLookahead),
            };

            // Read one row long and rendered short, so the extra row IS the
            // evidence of more rather than a full page being read as one.
            $hasMoreAnomalies = count($anomalyRows) > $this->pageSize;
            $anomalyRows = array_slice($anomalyRows, 0, $this->pageSize);

            $view = $views->make('drift-alerts::livewire.drift-page', [
                'pageType' => DriftPageType::Anomaly,
                'pageName' => Lang::get($this->activeType()->screenNameKey()),
                'lifecycleTab' => $this->activeTab(),
                'anomalyRows' => $anomalyRows,
                'hasMoreAnomalies' => $hasMoreAnomalies,
                'hasMoreRows' => false,
                'hasMoreGrouped' => false,
                'snoozeTargets' => $snoozeTargets,
                'rows' => [],
                'grouped' => [],
                'seriesStates' => [],
                'impactBySeriesId' => [],
                'thresholdBySeriesId' => [],
                'pageSize' => $this->pageSize,
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => Lang::get($this->activeType()->screenNameKey()).' · Beatrax']);

            return $view;
        }

        // The Open tab renders the grouped projection, so the flat page it
        // once queried was discarded on every render. The window is read one
        // row long and rendered short, so the extra row IS the evidence of
        // more rather than a full page being read as one.
        $lookahead = $this->pageSize + 1;
        $rows = match ($this->activeTab()) {
            DriftPageTab::History => $query->historyForUser($user, null, $lookahead),
            DriftPageTab::Dismissed => $query->dismissedForUser($user, null, $lookahead),
            DriftPageTab::Open => [],
        };
        $hasMoreRows = count($rows) > $this->pageSize;
        $rows = array_slice($rows, 0, $this->pageSize);

        // Same lookahead as the flat lists: reading exactly pageSize series and
        // offering "Load more" whenever it came back full put the control under
        // an exactly-full page, where pressing it changed nothing.
        $grouped = $this->activeTab() === DriftPageTab::Open
            ? $query->groupedBySeriesForUser($user, $lookahead)
            : [];
        $hasMoreGrouped = count($grouped) > $this->pageSize;
        $grouped = array_slice($grouped, 0, $this->pageSize, true);

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

        $uniqueSeriesIds = array_values(array_unique($seriesIds));
        /** @var array<int, CancellationImpactDto> $impactBySeriesId */
        $impactBySeriesId = $uniqueSeriesIds === []
            ? []
            : $impact->forSeriesIds($uniqueSeriesIds, $user);

        // Only a grouped row carries a threshold editor, so only those ids are
        // read here — the value travels as a prop so the child mounts without a
        // query of its own.
        $groupedSeriesIds = array_keys($grouped);
        $thresholdBySeriesId = $groupedSeriesIds === []
            ? []
            : $query->seriesThresholdsForUser($user, $groupedSeriesIds);

        $view = $views->make('drift-alerts::livewire.drift-page', [
            'pageType' => DriftPageType::Drift,
            'pageName' => Lang::get($this->activeType()->screenNameKey()),
            'rows' => $rows,
            'lifecycleTab' => $this->activeTab(),
            'grouped' => $grouped,
            'snoozeTargets' => $snoozeTargets,
            'seriesStates' => $seriesStates,
            'impactBySeriesId' => $impactBySeriesId,
            'thresholdBySeriesId' => $thresholdBySeriesId,
            'anomalyRows' => [],
            'pageSize' => $this->pageSize,
            'hasMoreRows' => $hasMoreRows,
            'hasMoreGrouped' => $hasMoreGrouped,
            'hasMoreAnomalies' => false,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get($this->activeType()->screenNameKey()).' · Beatrax']);

        return $view;
    }
}
