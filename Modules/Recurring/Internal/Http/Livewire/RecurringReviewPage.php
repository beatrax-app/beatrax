<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\SnoozeWindow;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\SnoozeUntil;
use Modules\Recurring\Internal\Enums\ReviewTab;
use Modules\Recurring\Internal\StateMachines\SeriesRowVanishedException;
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

    public const int PAGE_SIZE = 26;

    // How many pages of PAGE_SIZE the reader has asked to see, not which page.
    // A cursor that moves forward would take the already-selected rows off
    // screen while leaving their ids in the bulk bar. Locked because it sizes
    // a LIMIT, and the wire could otherwise ask for every row the user owns.
    #[Locked]
    public int $pagesShown = 1;

    /**
     * @var array<int, int|string> selected series ids for the bulk-action bar. Values
     *                             arrive from HTML checkbox value="" attributes, which Livewire deserializes as
     *                             strings — the bulk handlers cast to int before dispatching
     */
    public array $selectedIds = [];

    public string $tab = ReviewTab::DEFAULT;

    // Each tab is a different set of rows in a different state, so a selection
    // carried across one mixes states into a batch the bulk action then applies
    // to whatever it happens to reach.
    public function setTab(string $tab): void
    {
        if (ReviewTab::tryFrom($tab) === null) {
            return;
        }
        $this->tab = $tab;
        $this->pagesShown = 1;
        $this->selectedIds = [];
    }

    public function loadMore(): void
    {
        $this->pagesShown++;
    }

    // The wire can send anything, so the queue the reader sees is resolved
    // from the enum rather than trusted: an unknown tab reads as the default
    // instead of selecting no query at all.
    private function activeTab(): ReviewTab
    {
        return ReviewTab::tryFrom($this->tab) ?? ReviewTab::Pending;
    }

    public function approve(int|string $seriesId, CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        $id = DerivedRowId::fromWire($seriesId);
        if (! $this->apply(fn () => ($action)($id, $currentUser->user()))) {
            return;
        }
        $this->toast(Lang::get('recurring::review.toast.approved'));
    }

    public function reject(int|string $seriesId, CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        $id = DerivedRowId::fromWire($seriesId);
        if (! $this->apply(fn () => ($action)($id, $currentUser->user()))) {
            return;
        }
        $this->toast(Lang::get('recurring::review.toast.rejected'));
    }

    // A stale popover's target is dropped here rather than raised on; the
    // action refuses the same value loudly for every other caller.
    public function snooze(int|string $seriesId, string $untilIso, CurrentUser $currentUser, SnoozeRecurringSeries $action, Clock $clock): void
    {
        $until = SafeDate::parseOrNull($untilIso);
        if ($until === null || SnoozeUntil::tryFrom($until, $clock->now()) === null) {
            return;
        }

        $id = DerivedRowId::fromWire($seriesId);
        if (! $this->apply(fn () => ($action)($id, $currentUser->user(), $until))) {
            return;
        }
        $this->toast(Lang::get('recurring::review.toast.snoozed'));
    }

    public function editName(int|string $seriesId, ?string $newName, CurrentUser $currentUser, EditRecurringSeriesName $action): void
    {
        $normalised = $newName !== null && trim($newName) === '' ? null : $newName;
        $id = DerivedRowId::fromWire($seriesId);
        if (! $this->apply(fn () => ($action)($id, $currentUser->user(), $normalised))) {
            return;
        }
        $this->toast(Lang::get('recurring::review.toast.renamed'));
    }

    public function unReject(int|string $seriesId, CurrentUser $currentUser, UnRejectRecurringSeries $action): void
    {
        $id = DerivedRowId::fromWire($seriesId);
        if (! $this->apply(fn () => ($action)($id, $currentUser->user()))) {
            return;
        }
        $this->toast(Lang::get('recurring::review.toast.un_rejected'));
    }

    public function bulkApprove(CurrentUser $currentUser, ApproveRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = $this->applyToSelection(fn (int $id) => ($action)($id, $user));
        $this->toast(Lang::get('recurring::review.toast.bulk_approved', ['count' => $applied]));
    }

    public function bulkReject(CurrentUser $currentUser, RejectRecurringSeries $action): void
    {
        $user = $currentUser->user();
        $applied = $this->applyToSelection(fn (int $id) => ($action)($id, $user));
        $this->toast(Lang::get('recurring::review.toast.bulk_rejected', ['count' => $applied]));
    }

    // A row the reader is looking at can already have moved — another tab, or a
    // detection sweep. False means nothing was written, so the caller raises no
    // toast and the re-render shows the row's real state instead of a 500.
    /**
     * @param  callable(): void  $write
     */
    private function apply(callable $write): bool
    {
        try {
            $write();

            return true;
        } catch (InvalidStateTransitionException|SeriesRowVanishedException|NotFoundHttpException) {
            return false;
        }
    }

    // Returns what it actually wrote. A batch that stopped on the first refusal
    // left the rows before it applied, the rows after it untouched, and told
    // the reader neither.
    /**
     * @param  callable(int): void  $write
     */
    private function applyToSelection(callable $write): int
    {
        $applied = 0;
        foreach ($this->selectedIds as $rawId) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            if ($this->apply(static fn () => $write($id))) {
                $applied++;
            }
        }
        $this->selectedIds = [];

        return $applied;
    }

    public function render(
        CurrentUser $currentUser,
        RecurringSeriesQuery $query,
        ViewFactory $views,
        Clock $clock,
    ): View {
        $user = $currentUser->user();
        $limit = self::PAGE_SIZE * max(1, $this->pagesShown);

        // One row past the window: the control only appears when there is
        // something behind it, and the extra row never reaches the list.
        $rows = match ($this->activeTab()) {
            ReviewTab::Rejected => $query->rejectedForUser($user, null, $limit + 1),
            ReviewTab::CadenceChanged => $query->cadenceChangedForUser($user, $limit + 1),
            ReviewTab::Pending => $query->pendingForUser($user, null, $limit + 1),
        };
        $hasMore = count($rows) > $limit;

        $view = $views->make('recurring::livewire.recurring-review-page', [
            'rows' => array_slice($rows, 0, $limit),
            'hasMore' => $hasMore,
            'reviewTab' => $this->activeTab(),
            'snoozeTargets' => SnoozeWindow::targetsFrom($clock->now()),
            'today' => $clock->now(),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('recurring::review.title').' · Beatrax']);

        return $view;
    }
}
