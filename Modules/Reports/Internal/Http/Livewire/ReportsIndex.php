<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Services\UserPreferenceWriter;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Public\Actions\DeleteReport;
use Modules\Reports\Public\Actions\TogglePin;
use Modules\Reports\Public\Services\SavedReportsQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportsIndex extends Component
{
    use HoldsFlashMessage;

    public string $view = 'cards';

    public ?int $confirmingDeleteId = null;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        // Materialise the user's persisted view-mode preference. The row
        // may not exist yet (the foundation table is created lazily on
        // first preference write), in which case the locked default
        // `cards` applies — mirrors CounterpartyIndex::mount() verbatim.
        $existing = $db->connection()->table('user_preferences')
            ->where('user_id', $currentUser->id())
            ->value('reports_index_view');

        if (is_string($existing) && $existing !== '') {
            $this->view = $existing;
        }
    }

    // Switches the view mode and persists the choice through the shared
    // preference writer, which is also where the op-log capture lives — so
    // the setting travels instead of stopping at the device that set it.
    public function setView(string $view, CurrentUser $currentUser, UserPreferenceWriter $preferences): void
    {
        if (! in_array($view, ['cards', 'list'], true)) {
            return;
        }

        $this->view = $view;

        $preferences->write($currentUser->id(), ['reports_index_view' => $view]);
    }

    public function confirmDelete(int $reportId): void
    {
        $this->confirmingDeleteId = $reportId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // DeleteReport throws NotFoundHttpException on a foreign/missing id,
    // caught here so a tampered/stale Livewire payload renders a calm
    // flash instead of a 500; the action's own user-scoped lookup is the
    // actual security boundary.
    public function deleteReport(int $reportId, CurrentUser $currentUser, DeleteReport $delete): void
    {
        try {
            $delete->delete($currentUser->user(), $reportId);
        } catch (NotFoundHttpException) {
            $this->confirmingDeleteId = null;
            $this->flashMessage = Lang::get('reports::index.flash.not_found');

            return;
        }

        $this->confirmingDeleteId = null;
        $this->flashMessage = Lang::get('reports::index.flash.deleted');
    }

    // TogglePin enforces the 3-pin cap in the write-service layer; a 4th
    // pin attempt throws InvalidArgumentException, surfaced here verbatim
    // as the flash message. A foreign/missing id throws
    // NotFoundHttpException, caught the same way deleteReport() catches it.
    public function togglePin(int $reportId, CurrentUser $currentUser, TogglePin $togglePin): void
    {
        try {
            $togglePin->toggle($currentUser->user(), $reportId);
        } catch (NotFoundHttpException) {
            $this->flashMessage = Lang::get('reports::index.flash.not_found');

            return;
        } catch (InvalidArgumentException $e) {
            $this->flashMessage = $e->getMessage();

            return;
        }

        $this->flashMessage = '';
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    public function render(CurrentUser $currentUser, SavedReportsQuery $query, ViewFactory $views): View
    {
        $user = $currentUser->user();
        $rows = $query->forUser($user);

        $view = $views->make('reports::livewire.reports-index', [
            'rows' => $rows,
            'activeView' => $this->view,
            'pinnedCount' => $rows->filter(static fn ($row): bool => $row->pinned)->count(),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('reports::index.page_title')]);

        return $view;
    }
}
