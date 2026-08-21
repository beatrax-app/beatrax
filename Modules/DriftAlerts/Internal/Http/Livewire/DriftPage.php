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
 * @link ../../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final class DriftPage extends Component
{
    use DispatchesToast;

    private const int MAX_UNTIL_MONTHS = 6;

    #[Url(as: 'tab', except: 'open')]
    public string $tab = 'open';

    #[Url(as: 'type', except: 'drift')]
    public string $type = 'drift';

    public ?int $cursorId = null;

    public ?string $anomalyCursorDetectedAt = null;

    public ?string $anomalyCursorId = null;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['open', 'history', 'dismissed'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->resetCursors();
    }

    public function setType(string $type): void
    {
        if (! in_array($type, ['drift', 'anomaly'], true)) {
            return;
        }
        $this->type = $type;
        $this->resetCursors();
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

        $now = $clock->now();
        $snoozeTargets = [
            '1w' => $now->addWeek()->toIso8601String(),
            '1m' => $now->addMonth()->toIso8601String(),
            '3m' => $now->addMonths(3)->toIso8601String(),
        ];

        if ($this->type === 'anomaly') {
            $anomalyCursorId = $this->anomalyCursorId === null ? null : self::anomalyId($this->anomalyCursorId);

            $anomalyRows = match ($this->tab) {
                'history' => $anomalyQuery->historyForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
                'dismissed' => $anomalyQuery->dismissedForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
                default => $anomalyQuery->openForUser($user, $this->anomalyCursorDetectedAt, $anomalyCursorId),
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

        // Batched: forSeriesIds() collapses what would be one SELECT per row.
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
