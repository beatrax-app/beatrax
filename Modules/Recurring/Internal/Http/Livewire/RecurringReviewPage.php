<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/recurring/review` page — the dedicated review queue for pending
 * (and cadence-changed) recurring suggestions, with a Rejected tab for
 * the un-reject affordance. Each row carries Approve / Reject /
 * Snooze / Edit-name actions that dispatch a 10s Undo toast on
 * success.
 *
 * Service collaborators arrive as parameters on action methods +
 * `render()`. Constructor injection is banned on Livewire `Component`
 * subclasses by phpstan-strict-rules; the pattern mirrors the chains-
 * side `ChainReviewQueue` shape.
 */
final class RecurringReviewPage extends Component
{
    /**
     * Cursor: the previous page's tail `recurring_series.id`. Null =
     * first page. Cursor pagination keyed on id matches the rest of
     * the Public read API.
     */
    public ?int $cursorId = null;

    /**
     * Selected series ids for the bulk-action bar — full wiring lands
     * in Plan 05. The Blade view renders checkboxes alongside each
     * row so the per-row markup already exposes the seam.
     *
     * @var array<int, int>
     */
    public array $selectedIds = [];

    /**
     * Current visible tab. Tabs: pending | rejected | cadence_changed.
     */
    public string $tab = 'pending';

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['pending', 'rejected', 'cadence_changed'], true)) {
            return;
        }
        $this->tab = $tab;
        $this->cursorId = null;
    }

    public function approve(int $seriesId, CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Approved', undoAction: 'reject', undoPayload: $seriesId);
    }

    public function reject(int $seriesId, CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Rejected', undoAction: 'unReject', undoPayload: $seriesId);
    }

    public function snooze(int $seriesId, string $untilIso, CurrentUser $currentUser, SnoozeRecurringSeries $action): void
    {
        $until = CarbonImmutable::parse($untilIso);
        ($action)($seriesId, $currentUser->user(), $until);
        $this->dispatch('toast', message: 'Snoozed', undoAction: 'approve', undoPayload: $seriesId);
    }

    public function editName(int $seriesId, ?string $newName, CurrentUser $currentUser, EditRecurringSeriesName $action): void
    {
        $normalised = $newName !== null && trim($newName) === '' ? null : $newName;
        ($action)($seriesId, $currentUser->user(), $normalised);
        $this->dispatch('toast', message: 'Renamed', undoAction: 'editName', undoPayload: $seriesId);
    }

    public function unReject(int $seriesId, CurrentUser $currentUser, UnRejectRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->dispatch('toast', message: 'Un-rejected', undoAction: 'reject', undoPayload: $seriesId);
    }

    /**
     * Bulk-approve every series in `$selectedIds`. Foreign-user ids
     * are skipped silently — the underlying Public Action raises
     * `NotFoundHttpException` for cross-user lookups and the loop
     * swallows it so a partially-poisoned select does not break the
     * batch. The toast records only the successfully-applied count.
     */
    public function bulkApprove(CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = 0;
        foreach ($this->selectedIds as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                ($action)($id, $user);
                $applied++;
            } catch (NotFoundHttpException) {
                // Foreign-user or stale id — skip.
            }
        }
        $this->selectedIds = [];
        $this->dispatch('toast', message: $applied.' approved', undoAction: 'bulkUndo', undoPayload: null);
    }

    /**
     * Bulk-reject — same shape as bulkApprove but calls
     * `RejectRecurringSeries`. Foreign-user ids are skipped silently.
     */
    public function bulkReject(CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = 0;
        foreach ($this->selectedIds as $id) {
            if ($id <= 0) {
                continue;
            }
            try {
                ($action)($id, $user);
                $applied++;
            } catch (NotFoundHttpException) {
                // Foreign-user or stale id — skip.
            }
        }
        $this->selectedIds = [];
        $this->dispatch('toast', message: $applied.' rejected', undoAction: 'bulkUndo', undoPayload: null);
    }

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $rows = match ($this->tab) {
            'rejected' => $query->rejectedForUser($user, $this->cursorId),
            'cadence_changed' => $query->cadenceChangedForUser($user),
            default => $query->pendingForUser($user, $this->cursorId),
        };

        $view = $views->make('recurring::livewire.recurring-review-page', [
            'rows' => $rows,
            'tab' => $this->tab,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Review recurring · diederik']);

        return $view;
    }
}
