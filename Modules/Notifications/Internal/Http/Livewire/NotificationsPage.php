<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Notifications\Internal\Support\DeepLinkResolver;
use Modules\Notifications\Public\Actions\DismissNotification;
use Modules\Notifications\Public\Actions\MarkNotificationRead;
use Modules\Notifications\Public\Actions\UndoDismissNotification;
use Modules\Notifications\Public\Services\NotificationQuery;

/**
 * `/notifications` — the unified notification center (Req 2, D-01). ONE
 * lifecycle dimension (Unread / All / Dismissed, D-04) — D-02 is explicit
 * that this surface must NOT grow a second Level-1 type switch like
 * `DriftPage`'s; `/drift` stays the separate charges-that-changed surface.
 *
 * NO constructor DI — `Component` subclasses are banned from it by
 * phpstan-strict-rules. Every collaborator arrives as a parameter on the
 * action methods and `render()`, exactly like `DriftPage`.
 *
 * Every action re-resolves the user from `CurrentUser` and passes it to
 * the Public action — the notification id arriving from the Livewire
 * payload is NEVER treated as an authorization boundary (the sha256 PK is
 * matched only in a `WHERE` dedup clause; every Public action carries its
 * own explicit `user_id` scope, T-18-48).
 *
 * D-25 / Req 14: every row's deep link is re-validated at RENDER time via
 * `DeepLinkResolver` — never cached from write time — so a target deleted
 * after delivery degrades to a disabled, explained link instead of a 404.
 */
final class NotificationsPage extends Component
{
    private const TABS = ['unread', 'all', 'dismissed'];

    /**
     * Active tab. Three values: unread (default) / all / dismissed.
     * Whitelist-validated (D-04) — a tampered value falls back to
     * 'unread' rather than throwing.
     */
    #[Url(as: 'tab', except: 'unread')]
    public string $tab = 'unread';

    /**
     * Opaque compound `(created_at, id)` cursor (D-05's string PK is not
     * insertion-ordered) — a STRING cursor, unlike `DriftPage`'s `?int`.
     */
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

        // D-10: reversible, no confirmation gate. The undo toast mirrors
        // the DriftAlerts/Anomaly dismiss-with-undo convention.
        $this->dispatch('toast', message: 'Dismissed — Undo', undo: 'undoDismiss', undoArg: $notificationId);
    }

    public function undoDismiss(string $notificationId, CurrentUser $currentUser, UndoDismissNotification $action): void
    {
        $action($notificationId, $currentUser->user());
        $this->dispatch('toast', message: 'Restored');
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
        $view->extends('layouts.app', ['title' => 'Notifications · beatrax']);

        return $view;
    }
}
