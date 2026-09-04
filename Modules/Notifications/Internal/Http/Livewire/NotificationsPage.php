<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Navigation\NavBadgeEvents;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Internal\Enums\NotificationTab;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Services\NotificationQuery;

final class NotificationsPage extends Component
{
    use DispatchesToast;

    // setTab() ignores an unknown value and render() falls back to the
    // default; neither path throws.
    #[Url(as: 'tab', except: NotificationTab::DEFAULT)]
    public string $tab = NotificationTab::DEFAULT;

    // Opaque compound (created_at, id) cursor - a string cursor, since
    // the sha256 PK is not insertion-ordered.
    public ?string $cursor = null;

    public function setTab(string $tab): void
    {
        if (NotificationTab::tryFrom($tab) === null) {
            return;
        }

        $this->tab = $tab;
        $this->cursor = null;
    }

    // The wire can send anything, and a #[Url] value can arrive from a
    // bookmarked or hand-edited address bar, so the slice the reader sees is
    // resolved from the enum rather than trusted.
    private function activeTab(): NotificationTab
    {
        return NotificationTab::tryFrom($this->tab) ?? NotificationTab::Unread;
    }

    // All three change what the rail's unread badge counts, and the rail is
    // mounted by the layout, which a component update never re-renders.
    public function markRead(string $notificationId, CurrentUser $currentUser, MarkNotificationRead $action): void
    {
        $action($notificationId, $currentUser->user());
        $this->dispatch(NavBadgeEvents::REFRESH);
    }

    public function dismiss(string $notificationId, CurrentUser $currentUser, DismissNotification $action): void
    {
        $action($notificationId, $currentUser->user());
        $this->dispatch(NavBadgeEvents::REFRESH);

        $this->toastWithUndo(
            Lang::get('notifications::inbox.toast.dismissed'),
            undoAction: 'undoDismiss',
            undoPayload: $notificationId,
        );
    }

    public function undoDismiss(string $notificationId, CurrentUser $currentUser, UndoDismissNotification $action): void
    {
        $action($notificationId, $currentUser->user());
        $this->dispatch(NavBadgeEvents::REFRESH);
        $this->toast(Lang::get('notifications::inbox.toast.restored'));
    }

    public function render(
        CurrentUser $currentUser,
        NotificationQuery $query,
        DeepLinkResolver $deepLinks,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $activeTab = $this->activeTab();

        $page = match ($activeTab) {
            NotificationTab::All => $query->allForUser($user, $this->cursor),
            NotificationTab::Dismissed => $query->dismissedForUser($user, $this->cursor),
            NotificationTab::Unread => $query->unreadForUser($user, $this->cursor),
        };

        $resolvedRows = [];
        foreach ($page['rows'] as $row) {
            $resolvedRows[] = $deepLinks->resolve($row, $user);
        }

        $view = $views->make('notifications::livewire.notifications-page', [
            // Not 'tab': Livewire hands the view every public property, and
            // the string one on this component would shadow it.
            'activeTab' => $activeTab,
            'rows' => $resolvedRows,
            'nextCursor' => $page['nextCursor'],
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('notifications::inbox.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }
}
