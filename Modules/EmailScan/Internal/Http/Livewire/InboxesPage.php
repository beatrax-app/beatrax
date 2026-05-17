<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `/inboxes` page Livewire SFC.
 *
 * Renders the empty-state hero when the user has no connected inboxes
 * and the table-driven layout once at least one inbox exists. The
 * Connect-Gmail and Connect-Microsoft-365 buttons share a single
 * openWizard() action method that branches on the supplied $provider.
 * Action methods take their collaborators as parameters — constructor
 * injection is banned on Livewire components by the strict-rules
 * plugin.
 *
 * The backfill window modal SFC + the inline row actions
 * (Scan-Now, Reconnect, Edit window) + the discovered-senders panel
 * are wired in later plans; this SFC ships the page shell, the
 * Connect triggers, and the post-callback backfill-modal auto-open
 * hook.
 */
final class InboxesPage extends Component
{
    /**
     * When set, the Blade layer auto-dispatches a `modal-show` event
     * scoped to this inbox id so the backfill window modal opens
     * immediately after the OAuth callback redirect lands. The
     * actual modal SFC ships in a later plan; Plan 03 only carries
     * the flash value through render-time.
     */
    public ?int $openBackfillForInboxId = null;

    public function mount(Request $request, CurrentUser $currentUser): void
    {
        // The OAuth callback redirects with a session flash carrying
        // the freshly-connected inbox id. Pick it up and dispatch the
        // backfill-window:open event so the modal opens immediately
        // on the next render. Cross-user safety is enforced upstream —
        // the callback controller writes the flash inside a
        // transaction scoped to the current user.
        //
        // A direct Livewire test harness boots the component without
        // a bound session, so guard the read with hasSession() to
        // keep the page mountable in both contexts.
        if ($request->hasSession()) {
            $session = $request->session();
            if ($session->has('open_backfill_modal')) {
                $candidate = $session->get('open_backfill_modal');
                if (is_int($candidate) && $candidate > 0) {
                    $this->openBackfillForInboxId = $candidate;
                } elseif (is_numeric($candidate)) {
                    $this->openBackfillForInboxId = (int) $candidate;
                }
                if ($this->openBackfillForInboxId !== null) {
                    $this->dispatch('backfill-window:open', inboxId: $this->openBackfillForInboxId);
                }
            }
        }

        // Touch the parameters so PHPStan does not complain about
        // unused arguments — the contract is the boot-time DI surface.
        unset($currentUser);
    }

    /**
     * Open the backfill window modal scoped to a specific inbox
     * row. Wired to the inline [Edit] link on every connected-
     * inbox row in the table; dispatches a Livewire event that the
     * BackfillWindowModal SFC listens for via #[On(...)].
     *
     * Cross-user 404 invariant: the inbox lookup scopes to the
     * current user via InboxQuery::findForUser, which returns null
     * for a foreign id. A foreign id returns the same response
     * shape as a missing inbox (Symfony NotFoundHttpException),
     * never leaking the existence of another user's row.
     */
    public function editWindow(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
    ): void {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException('Inbox not found.');
        }
        $this->dispatch(
            'backfill-window:open',
            inboxId: $inboxId,
            currentWindow: $health->backfillWindowMonths,
        );
    }

    /**
     * Polling target for the backfill progress strip. The Blade
     * binds `wire:poll.2s="refreshBackfillProgress"`; the method
     * is intentionally a no-op because Livewire re-renders the
     * component on every poll tick, which re-queries InboxQuery
     * for the live backfill_progress payload.
     */
    public function refreshBackfillProgress(): void {}

    public function openWizard(
        string $provider,
        OAuthSecretsRepository $secrets,
    ): mixed {
        if (! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            return null;
        }

        if ($secrets->hasProviderClient($provider)) {
            return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
        }

        $this->dispatch('modal-show', name: 'oauth-client-wizard-'.$provider);

        return null;
    }

    public function render(
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $inboxes = $inboxQuery->forCurrentUser($user);

        $view = $views->make('email-scan::livewire.inboxes-page', [
            'inboxes' => $inboxes,
            'openBackfillForInboxId' => $this->openBackfillForInboxId,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Inboxes · diederik']);

        return $view;
    }
}
