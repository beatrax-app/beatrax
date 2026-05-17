<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Public\Actions\DismissDiscoveredSender;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
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

    /**
     * Manually trigger an incremental scan for one inbox.
     *
     * Wired to the "Scan now" button on every connected-inbox row.
     * Dispatches IncrementalScanJob via the injected Bus contract;
     * the job's ShouldBeUnique constraint deduplicates a rapid double-
     * click into a single queued job (lock held until handle() returns).
     *
     * Cross-user 404 invariant: the inbox lookup scopes through
     * InboxQuery::findForUser which returns null for a foreign id;
     * the action raises Symfony NotFoundHttpException so a forged
     * inboxId in the wire payload cannot dispatch a job for another
     * user's inbox.
     *
     * No-op guard: when the inbox is already in 'backfilling' or
     * 'scanning' state, the action emits a toast ("Scan already in
     * progress.") and returns without dispatching — the Blade also
     * renders the button as disabled in those states so the no-op
     * path is only ever reached via wire payload forgery.
     */
    public function scanNow(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        Dispatcher $bus,
    ): void {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException('Inbox not found.');
        }
        if (in_array($health->status, ['backfilling', 'scanning'], strict: true)) {
            $this->dispatch('toast', message: 'Scan already in progress.');

            return;
        }
        $bus->dispatch(new IncrementalScanJob($inboxId));
        $this->dispatch('toast', message: 'Scan started.');
    }

    /**
     * Kick off the per-inbox OAuth consent re-grant flow for a row
     * stuck in needs_reauth state.
     *
     * Cross-user 404 invariant: same shape as scanNow — InboxQuery::
     * findForUser returns null for a foreign id which raises Symfony
     * NotFoundHttpException.
     *
     * The redirect target is `/oauth/connect/{provider}?inbox_id={id}` —
     * the existing OAuthConnectController reads the inbox_id query
     * parameter (Plan 06) to scope the consent flow to the existing
     * inbox row (preserves inbox_messages + .eml blobs + cursor; D-115).
     */
    public function reconnect(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        UrlGenerator $urls,
    ): mixed {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException('Inbox not found.');
        }

        $target = $urls->route('oauth.connect', ['provider' => $health->provider])
            .'?inbox_id='.$inboxId;

        // Livewire 3+ honours $this->redirect($url) for client-side
        // navigation (matches the openWizard() pattern above which uses
        // $this->redirectRoute(...)). Plain RedirectResponse return is
        // not picked up by the Livewire wire:click protocol.
        return $this->redirect($target);
    }

    /**
     * Promote a discovered_senders candidate into the user's
     * known_senders allow-list and transition the discovered row to
     * state='added'.
     *
     * Wired to each "Add" chip on the discovered-senders panel. The
     * PromoteDiscoveredSender action is fully idempotent: a row already
     * promoted or dismissed is a silent no-op. Cross-user 404 lives
     * inside the action (the panel's render-time DiscoveredSenderQuery
     * call already scopes to the current user, but the explicit
     * cross-user guard in the action defends against wire-payload
     * forgery).
     */
    public function promoteSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        PromoteDiscoveredSender $promote,
    ): void {
        ($promote)($discoveredSenderId, $currentUser->user());
        $this->dispatch('toast', message: 'Sender added.');
    }

    /**
     * Dismiss a discovered_senders candidate. Mirror of promoteSender —
     * idempotent + cross-user 404 inside the action surface.
     */
    public function dismissSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        DismissDiscoveredSender $dismiss,
    ): void {
        ($dismiss)($discoveredSenderId, $currentUser->user());
        $this->dispatch('toast', message: 'Sender dismissed.');
    }

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
        DiscoveredSenderQuery $discoveredQuery,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $inboxes = $inboxQuery->forCurrentUser($user);

        // Skip the discovered-senders query when the user has no
        // connected inboxes — the discovered_senders panel cannot
        // surface anything without an inbox to attach to, and the
        // join would always return zero rows. Saves one query per
        // empty-state render AND every 2s wire:poll cycle if the
        // user lingers on the empty-state hero.
        $discoveredCandidates = $inboxes === []
            ? []
            : $discoveredQuery->candidatesForUser($user);

        $view = $views->make('email-scan::livewire.inboxes-page', [
            'inboxes' => $inboxes,
            'discoveredCandidates' => $discoveredCandidates,
            'openBackfillForInboxId' => $this->openBackfillForInboxId,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Inboxes · diederik']);

        return $view;
    }
}
