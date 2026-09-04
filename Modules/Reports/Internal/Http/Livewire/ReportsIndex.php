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
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Reports\Internal\Actions\DeleteReport;
use Modules\Reports\Internal\Actions\TogglePin;
use Modules\Reports\Internal\Services\SavedReportsQuery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ReportsIndex extends Component
{
    use HoldsFlashMessage;

    public string $view = 'cards';

    public ?int $confirmingDeleteId = null;

    public function mount(CurrentUser $currentUser, DatabaseManager $db): void
    {
        // The row may not exist yet, since the preferences table is created
        // lazily on the first write; the locked `cards` default applies then.
        $existing = $db->connection()->table('user_preferences')
            ->where('user_id', $currentUser->id())
            ->value('reports_index_view');

        if (is_string($existing) && $existing !== '') {
            $this->view = $existing;
        }
    }

    // Persisted through the shared preference writer, which is where the op-log
    // capture lives, so the setting travels off the device that set it.
    public function setView(string $view, CurrentUser $currentUser, UserPreferenceWriter $preferences): void
    {
        if (! in_array($view, ['cards', 'list'], true)) {
            return;
        }

        $this->view = $view;

        $preferences->write($currentUser->id(), ['reports_index_view' => $view]);
    }

    public function confirmDelete(int|string $reportId): void
    {
        $reportId = DerivedRowId::fromWire($reportId);

        $this->confirmingDeleteId = $reportId;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    // The action's own user-scoped lookup is the security boundary; catching its
    // NotFoundHttpException only spares a stale payload a 500.
    public function deleteReport(int|string $reportId, CurrentUser $currentUser, DeleteReport $delete): void
    {
        try {
            $delete->delete($currentUser->user(), DerivedRowId::fromWire($reportId));
        } catch (NotFoundHttpException) {
            $this->confirmingDeleteId = null;
            $this->flashMessage = Lang::get('reports::index.flash.not_found');

            return;
        }

        $this->confirmingDeleteId = null;
        $this->flashMessage = Lang::get('reports::index.flash.deleted');
    }

    // TogglePin enforces the 3-pin cap, and its InvalidArgumentException message
    // is surfaced verbatim as the flash.
    public function togglePin(int|string $reportId, CurrentUser $currentUser, TogglePin $togglePin): void
    {
        $reportId = DerivedRowId::fromWire($reportId);

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
