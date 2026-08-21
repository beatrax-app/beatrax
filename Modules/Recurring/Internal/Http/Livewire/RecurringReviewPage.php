<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Recurring\Internal\Enums\ReviewTab;
use Modules\Recurring\Public\Actions\ApproveRecurringSeries;
use Modules\Recurring\Public\Actions\EditRecurringSeriesName;
use Modules\Recurring\Public\Actions\RejectRecurringSeries;
use Modules\Recurring\Public\Actions\SnoozeRecurringSeries;
use Modules\Recurring\Public\Actions\UnRejectRecurringSeries;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RecurringReviewPage extends Component
{
    use DispatchesToast;

    public ?int $cursorId = null;

    /**
     * @var array<int, int|string> selected series ids for the bulk-action bar. Values
     *                             arrive from HTML checkbox value="" attributes, which Livewire deserializes as
     *                             strings — the bulk handlers cast to int before dispatching
     */
    public array $selectedIds = [];

    public string $tab = ReviewTab::DEFAULT;

    public function setTab(string $tab): void
    {
        if (ReviewTab::tryFrom($tab) === null) {
            return;
        }
        $this->tab = $tab;
        $this->cursorId = null;
    }

    // The wire can send anything, so the queue the reader sees is resolved
    // from the enum rather than trusted: an unknown tab reads as the default
    // instead of selecting no query at all.
    private function activeTab(): ReviewTab
    {
        return ReviewTab::tryFrom($this->tab) ?? ReviewTab::Pending;
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
                // Deliberately empty: the id belongs to another user, and a bulk
                // action reports what it applied rather than failing the batch.
            }
        }
        $this->selectedIds = [];
        $this->toastWithUndo(Lang::get('recurring::review.toast.bulk_approved', ['count' => $applied]), undoAction: 'bulkUndo', undoPayload: null);
    }

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
                // Deliberately empty: the id belongs to another user, and a bulk
                // action reports what it applied rather than failing the batch.
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

        $rows = match ($this->activeTab()) {
            ReviewTab::Rejected => $query->rejectedForUser($user, $this->cursorId),
            ReviewTab::CadenceChanged => $query->cadenceChangedForUser($user),
            ReviewTab::Pending => $query->pendingForUser($user, $this->cursorId),
        };

        $snoozeTargets = SnoozeWindow::targetsFrom($clock->now());

        $view = $views->make('recurring::livewire.recurring-review-page', [
            'rows' => $rows,
            'reviewTab' => $this->activeTab(),
            'snoozeTargets' => $snoozeTargets,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('recurring::review.title').' · Beatrax']);

        return $view;
    }
}
