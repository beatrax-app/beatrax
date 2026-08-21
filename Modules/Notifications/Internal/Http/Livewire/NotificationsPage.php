<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Services\NotificationQuery;

final class NotificationsPage extends Component
{
    use DispatchesToast;

    private const TABS = ['unread', 'all', 'dismissed'];

    // setTab() ignores an unknown value and render() falls back to 'unread';
    // neither path throws.
    #[Url(as: 'tab', except: 'unread')]
    public string $tab = 'unread';

    // Opaque compound (created_at, id) cursor - a string cursor, since
    // the sha256 PK is not insertion-ordered.
    public ?string $cursor = null;

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }

        $this->tab = $tab;
        $this->cursor = null;
    }

    public function markRead(string $notificationId, CurrentUser $currentUser, MarkNotificationRead $action): void
    {
        $action($notificationId, $currentUser->user());
    }

    public function dismiss(string $notificationId, CurrentUser $currentUser, DismissNotification $action): void
    {
        $action($notificationId, $currentUser->user());

        // Reversible, so no confirmation gate — the project-wide convention.
        $this->dispatch('toast', message: Lang::get('notifications::inbox.toast.dismissed'), undo: 'undoDismiss', undoArg: $notificationId);
    }

    public function undoDismiss(string $notificationId, CurrentUser $currentUser, UndoDismissNotification $action): void
    {
        $action($notificationId, $currentUser->user());
        $this->toast(Lang::get('notifications::inbox.toast.restored'));
    }

    public function render(
        CurrentUser $currentUser,
        NotificationQuery $query,
        DeepLinkResolver $deepLinks,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        // Whitelist re-validated at render time too (a #[Url] value can
        // arrive from a bookmarked/hand-edited URL, not just setTab()).
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'unread';

        $rows = match ($tab) {
            'all' => $query->allForUser($user, $this->cursor),
            'dismissed' => $query->dismissedForUser($user, $this->cursor),
            default => $query->unreadForUser($user, $this->cursor),
        };

        $resolvedRows = [];
        foreach ($rows as $row) {
            $resolvedRows[] = $deepLinks->resolve($row, $user);
        }

        $view = $views->make('notifications::livewire.notifications-page', [
            'tab' => $tab,
            'rows' => $resolvedRows,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('notifications::inbox.page_title').' · Beatrax']);

        return $view;
    }
}
