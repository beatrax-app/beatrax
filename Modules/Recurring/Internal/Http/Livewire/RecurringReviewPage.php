<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Service collaborators arrive as parameters on action methods and
// render() — constructor injection is banned on Livewire Component
// subclasses by phpstan-strict-rules; the pattern mirrors the
// chains-side ChainReviewQueue shape.
final class RecurringReviewPage extends Component
{
    use DispatchesToast;

    // Previous page's tail recurring_series.id, null on the first page.
    // Cursor pagination keyed on id matches the rest of the Public read API.
    public ?int $cursorId = null;

    /**
     * @var array<int, int|string> selected series ids for the bulk-action bar. Values
     *                             arrive from HTML checkbox value="" attributes, which Livewire deserializes as
     *                             strings — the bulk handlers cast to int before dispatching
     */
    public array $selectedIds = [];

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
        $this->toastWithUndo(Lang::get('recurring::review.toast.approved'), undoAction: 'reject', undoPayload: $seriesId);
    }

    public function reject(int $seriesId, CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->toastWithUndo(Lang::get('recurring::review.toast.rejected'), undoAction: 'unReject', undoPayload: $seriesId);
    }

    public function snooze(int $seriesId, string $untilIso, CurrentUser $currentUser, SnoozeRecurringSeries $action): void
    {
        $until = CarbonImmutable::parse($untilIso);
        ($action)($seriesId, $currentUser->user(), $until);
        $this->toastWithUndo(Lang::get('recurring::review.toast.snoozed'), undoAction: 'approve', undoPayload: $seriesId);
    }

    public function editName(int $seriesId, ?string $newName, CurrentUser $currentUser, EditRecurringSeriesName $action): void
    {
        $normalised = $newName !== null && trim($newName) === '' ? null : $newName;
        ($action)($seriesId, $currentUser->user(), $normalised);
        $this->toastWithUndo(Lang::get('recurring::review.toast.renamed'), undoAction: 'editName', undoPayload: $seriesId);
    }

    public function unReject(int $seriesId, CurrentUser $currentUser, UnRejectRecurringSeries $action): void
    {
        ($action)($seriesId, $currentUser->user());
        $this->toastWithUndo(Lang::get('recurring::review.toast.un_rejected'), undoAction: 'reject', undoPayload: $seriesId);
    }

    // Foreign-user ids are skipped silently — the underlying Public Action
    // raises NotFoundHttpException for cross-user lookups and the loop
    // swallows it so a partially-poisoned select does not break the batch;
    // the toast records only the successfully-applied count.
    public function bulkApprove(CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = 0;
        foreach ($this->selectedIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            try {
                ($action)($id, $user);
                $applied++;
            } catch (NotFoundHttpException) {
                // Deliberately empty: the id belongs to another user, and a
                // bulk action reports how many rows it applied to rather than
                // failing the whole batch on one it was never allowed to see.
            }
        }
        $this->selectedIds = [];
        $this->toastWithUndo(Lang::get('recurring::review.toast.bulk_approved', ['count' => $applied]), undoAction: 'bulkUndo', undoPayload: null);
    }

    // Same shape as bulkApprove() but calls RejectRecurringSeries;
    // foreign-user ids are skipped silently.
    public function bulkReject(CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = 0;
        foreach ($this->selectedIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            try {
                ($action)($id, $user);
                $applied++;
            } catch (NotFoundHttpException) {
                // Deliberately empty: the id belongs to another user, and a
                // bulk action reports how many rows it applied to rather than
                // failing the whole batch on one it was never allowed to see.
            }
        }
        $this->selectedIds = [];
        $this->toastWithUndo(Lang::get('recurring::review.toast.bulk_rejected', ['count' => $applied]), undoAction: 'bulkUndo', undoPayload: null);
    }

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();

        $rows = match ($this->tab) {
            'rejected' => $query->rejectedForUser($user, $this->cursorId),
            'cadence_changed' => $query->cadenceChangedForUser($user),
            default => $query->pendingForUser($user, $this->cursorId),
        };

        // Snooze targets are domain timestamps computed server-side
        // off the injected clock, not Blade-time `now()` calls. This
        // keeps `CarbonImmutable::setTestNow()` deterministic for the
        // test suite and routes timing through the DI-only seam.
        $now = $clock->now();
        $snoozeTargets = [
            '1w' => $now->addWeek()->toIso8601String(),
            '1m' => $now->addMonth()->toIso8601String(),
            '3m' => $now->addMonths(3)->toIso8601String(),
        ];

        $view = $views->make('recurring::livewire.recurring-review-page', [
            'rows' => $rows,
            'tab' => $this->tab,
            'snoozeTargets' => $snoozeTargets,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('recurring::review.title').' · beatrax']);

        return $view;
    }
}
