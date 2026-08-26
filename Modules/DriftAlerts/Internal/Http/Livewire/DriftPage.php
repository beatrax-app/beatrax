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
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DriftAlerts\Public\Actions\AcknowledgeDriftAlert;
use Modules\DriftAlerts\Public\Actions\DismissDriftAlertAsCancelled;
use Modules\DriftAlerts\Public\Actions\SnoozeDriftAlert;
use Modules\DriftAlerts\Public\Dto\CancellationImpactDto;
use Modules\DriftAlerts\Public\Enums\DriftPageTab;
use Modules\DriftAlerts\Public\Enums\DriftPageType;
use Modules\DriftAlerts\Public\Services\CancellationImpactQuery;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\Forecasting\Public\Actions\CreateCancellationScenarioForAlert;

/**
 * @link ../../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final class DriftPage extends Component
{
    use DispatchesToast;

    private const int MAX_UNTIL_MONTHS = 6;

    // Both stay strings on the wire: they are #[Url] state, so anyone can put
    // anything in the query string, and a bookmarked value outside the enum
    // has to land on the default view rather than fail the request.
    #[Url(as: 'tab', except: DriftPageTab::DEFAULT)]
    public string $tab = DriftPageTab::DEFAULT;

    #[Url(as: 'type', except: DriftPageType::DEFAULT)]
    public string $type = DriftPageType::DEFAULT;

    public ?int $cursorId = null;

    public ?string $anomalyCursorDetectedAt = null;

    public ?string $anomalyCursorId = null;

    public function setTab(string $tab): void
    {
        if (DriftPageTab::tryFrom($tab) === null) {
            return;
        }
        $this->tab = $tab;
        $this->resetCursors();
    }

    public function setType(string $type): void
    {
        if (DriftPageType::tryFrom($type) === null) {
            return;
        }
        $this->type = $type;
        $this->resetCursors();
    }

    private function activeTab(): DriftPageTab
    {
        return DriftPageTab::tryFrom($this->tab) ?? DriftPageTab::Open;
    }

    private function activeType(): DriftPageType
    {
        return DriftPageType::tryFrom($this->type) ?? DriftPageType::Drift;
    }

    public function loadMoreAnomalies(string $detectedAt, int|string $alertId): void
    {
        $this->anomalyCursorDetectedAt = $detectedAt;
        $this->anomalyCursorId = (string) $alertId;
    }

    // A derived anomaly id is a 63-bit integer, and every value crossing this
    // boundary goes through JSON, whose numbers are IEEE doubles — anything
    // past 2^53 comes back from the browser silently rounded. So the id
    // travels as a STRING and is only an int again on this side of the wire.
    private static function anomalyId(int|string $alertId): int
    {
        return is_numeric($alertId) ? (int) $alertId : 0;
    }

    // Two nav items land here — "Drift Alerts" and "Unusual charges" — and the
    // page carried one name for both, so tapping the second arrived at a screen
    // headed with the first one's name. The type toggle already labels them
    // apart in every locale, so the page borrows the label the reader tapped.
    private function pageName(): string
    {
        return $this->activeType() === DriftPageType::Anomaly
            ? Lang::get('drift-alerts::alerts.type.anomaly')
            : Lang::get('drift-alerts::alerts.heading');
    }

    private function pageTitle(): string
    {
        return $this->activeType() === DriftPageType::Anomaly
            ? Lang::get('drift-alerts::alerts.type.anomaly')
            : Lang::get('drift-alerts::alerts.page_title');
    }

    private function resetCursors(): void
    {
        $this->cursorId = null;
        $this->anomalyCursorDetectedAt = null;
        $this->anomalyCursorId = null;
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
        ($action)(self::anomalyId($alertId), $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed'));
    }

    public function markAnomalyExpected(int|string $alertId, CurrentUser $currentUser, DismissAnomalyAlertAsExpected $action): void
    {
        $ruleWritten = ($action)(self::anomalyId($alertId), $currentUser->user());

        if ($ruleWritten) {
            $this->dispatch('toast', message: Lang::get('drift-alerts::alerts.toasts.suppression_added'), undo: 'undoAnomalySuppression', undoArg: (string) $alertId);

            return;
        }

        // No rule could be written (unresolvable charge amount), so this toast
        // promises no mute and offers no Undo that would delete nothing.
        $this->toast(Lang::get('drift-alerts::alerts.toasts.dismissed_expected'));
    }

    public function undoAnomalySuppression(int|string $alertId, CurrentUser $currentUser, RemoveAnomalySuppressionRule $action): void
    {
        $action->undoSuppression(self::anomalyId($alertId), $currentUser->user());
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
        $action($alertId, $currentUser->user());
        $this->toast(Lang::get('drift-alerts::alerts.toasts.acknowledged'));
    }

    // Bounds the accepted range to (now, now+6mo] so a tampered Livewire
    // payload cannot snooze an alert away with no audit trail. Shared by both
    // streams; SnoozeAnomalyAlert repeats the bound, SnoozeDriftAlert does not.
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

        $snoozeTargets = SnoozeWindow::targetsFrom($clock->now());

        if ($this->activeType() === DriftPageType::Anomaly) {
            $anomalyCursorId = $this->anomalyCursorId === null ? null : self::anomalyId($this->anomalyCursorId);

            $anomalyRows = match ($this->activeTab()) {
                DriftPageTab::History => $anomalyQuery->historyForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
                DriftPageTab::Dismissed => $anomalyQuery->dismissedForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
                DriftPageTab::Open => $anomalyQuery->openForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
            };

            $view = $views->make('drift-alerts::livewire.drift-page', [
                'pageType' => DriftPageType::Anomaly,
                'pageName' => $this->pageName(),
                'lifecycleTab' => $this->activeTab(),
                'anomalyRows' => $anomalyRows,
                'snoozeTargets' => $snoozeTargets,
                'rows' => [],
                'grouped' => [],
                'seriesStates' => [],
                'impactBySeriesId' => [],
                'thresholdBySeriesId' => [],
            ]);

            /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
            $view->extends('layouts.app', ['title' => $this->pageTitle().' · Beatrax']);

            return $view;
        }

        $rows = match ($this->activeTab()) {
            DriftPageTab::History => $query->historyForUser($user, $this->cursorId),
            DriftPageTab::Dismissed => $query->dismissedForUser($user, $this->cursorId),
            DriftPageTab::Open => $query->openForUser($user, $this->cursorId),
        };

        $grouped = $this->activeTab() === DriftPageTab::Open ? $query->groupedBySeriesForUser($user) : [];

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
            'pageName' => $this->pageName(),
            'rows' => $rows,
            'lifecycleTab' => $this->activeTab(),
            'grouped' => $grouped,
            'snoozeTargets' => $snoozeTargets,
            'seriesStates' => $seriesStates,
            'impactBySeriesId' => $impactBySeriesId,
            'thresholdBySeriesId' => $thresholdBySeriesId,
            'anomalyRows' => [],
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => $this->pageTitle().' · Beatrax']);

        return $view;
    }
}
