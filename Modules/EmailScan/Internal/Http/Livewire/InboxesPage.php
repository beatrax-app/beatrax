<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Public\Actions\DismissDiscoveredSender;
use Modules\EmailScan\Public\Actions\PromoteDiscoveredSender;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

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
     * When set, the Blade layer auto-dispatches a `backfill-window:open`
     * event scoped to this inbox id so the backfill window modal opens
     * immediately after the OAuth callback redirect lands. Populated
     * from the single-use `open_backfill_modal` session flash that the
     * OAuth callback controller writes after a successful re-consent.
     */
    public ?int $openBackfillForInboxId = null;

    /**
     * One-shot oauth_canceled flash carried from the OAuth callback
     * controller through mount(). Pulled out of the session here
     * rather than read via the session() global helper inside the
     * Blade view — the DI-only invariant applies to Blade too, so the
     * value flows in as a render prop instead of a facade lookup.
     */
    public ?string $oauthCanceledMessage = null;

    /**
     * One-shot oauth_failed flash carried from the OAuth callback
     * controller through mount() — same DI-only rationale as
     * $oauthCanceledMessage above.
     */
    public ?string $oauthFailedMessage = null;

    /**
     * Inbox id pulled from the `?reconnect={id}` query parameter. When
     * present AND the inbox belongs to the current user AND its
     * provider is one of 'gmail' / 'microsoft', mount() auto-dispatches
     * `oauth-client-wizard:open` so the modal opens against that inbox
     * — the OAuth dance writes the refreshed token back to the
     * existing inbox row instead of inserting a new one. A foreign id
     * or a missing row is silently ignored (404-not-403 contract).
     */
    #[Url(as: 'reconnect', except: '')]
    public ?int $reconnectInboxId = null;

    public function mount(
        Request $request,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        LoggerInterface $logger,
    ): void {
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

            // Every session flash consumed here uses pull() (single-use)
            // so a subsequent wire:poll tick or back-button revisit does
            // not re-fire the backfill-window modal or repaint the
            // canceled / failed warning banner.
            $candidate = $session->pull('open_backfill_modal');
            if (is_int($candidate) && $candidate > 0) {
                $this->openBackfillForInboxId = $candidate;
            } elseif (is_numeric($candidate)) {
                $this->openBackfillForInboxId = (int) $candidate;
            }
            if ($this->openBackfillForInboxId !== null) {
                $this->dispatch('backfill-window:open', inboxId: $this->openBackfillForInboxId);
            }

            $canceled = $session->pull('oauth_canceled');
            if (is_string($canceled) && $canceled !== '') {
                $this->oauthCanceledMessage = $canceled;
            }
            $failed = $session->pull('oauth_failed');
            if (is_string($failed) && $failed !== '') {
                $this->oauthFailedMessage = $failed;
            }
        }

        // `?reconnect={id}` query-param hand-off: the SystemAlertsBanner
        // Reconnect link routes here with the inbox id. Auto-dispatch
        // the modal-open event so the user lands directly inside the
        // re-consent flow rather than having to re-click "Reconnect"
        // from the inbox row. Cross-user / missing-row ids are silently
        // ignored — InboxQuery::findForUser scopes by current user and
        // returns null for a foreign id (404-not-403). The lookup miss
        // is logged at `info` so an operator monitoring `/dev/logs` can
        // see attempted reconnects against non-existent or foreign
        // inbox ids; the failure stays silent for the user.
        $reconnectId = $this->reconnectInboxId;
        if ($reconnectId !== null && $reconnectId > 0) {
            $user = $currentUser->user();
            $inbox = $inboxQuery->findForUser($reconnectId, $user);
            if ($inbox !== null && in_array($inbox->provider, ['gmail', 'microsoft'], strict: true)) {
                $this->dispatch(
                    'oauth-client-wizard:open',
                    provider: $inbox->provider,
                    inboxId: $inbox->inboxId,
                );
            } elseif ($inbox === null) {
                $logger->info('InboxesPage: ?reconnect query-param resolved to no inbox for the current user.', [
                    'user_id' => $user->id,
                    'reconnect_inbox_id' => $reconnectId,
                ]);
            }
        }
    }

    /**
     * Acknowledge the user's active oauth_reconsent_required alert for
     * a given inbox whenever the OAuth wizard modal signals that a re-
     * consent dance has been kicked off. The acknowledge is per-user
     * scoped via the system_alerts.user_id column so a forged event
     * from the browser cannot clear another user's alert.
     *
     * The actual token refresh happens server-side in
     * OAuthCallbackController — this listener clears the banner row
     * optimistically; if the refresh fails the listener's upstream
     * (RaiseReconsentAlertOnTokenFailure) re-raises a fresh row on the
     * next IncrementalScanJob, so the system self-heals.
     */
    #[On('oauth-client-wizard:reconsented')]
    public function acknowledgeReconnect(
        int $inboxId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        AcknowledgeSystemAlert $acknowledge,
    ): void {
        if ($inboxId <= 0) {
            return;
        }
        $user = $currentUser->user();

        $matches = $this->activeReconsentAlertIds($db, $user->id, $inboxId);
        foreach ($matches as $alertId) {
            try {
                ($acknowledge)($alertId, $user);
            } catch (NotFoundHttpException) {
                // Cross-row race — the alert was acknowledged between
                // the lookup and the action call. Silent no-op so the
                // listener stays idempotent.
            }
        }
    }

    /**
     * Resolve the active oauth_reconsent_required alert ids for the
     * given user + inbox. Prefers SQLite's `json_extract` against the
     * metadata column; falls through to a LIKE form when the
     * JSON1-extension query throws on an older runtime.
     *
     * @return list<int>
     */
    private function activeReconsentAlertIds(DatabaseManager $db, int $userId, int $inboxId): array
    {
        $base = $db->connection()->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', 'oauth_reconsent_required')
            ->whereNull('acknowledged_at');

        try {
            $rows = (clone $base)
                ->whereRaw("json_extract(metadata, '$.inbox_id') = ?", [$inboxId])
                ->pluck('id')
                ->all();
        } catch (Throwable) {
            // SQLite LIKE has no character classes; anchor the
            // trailing boundary by OR-ing the comma + closing-brace
            // variants so `inbox_id=1` does not falsely match
            // `inbox_id=10`, `inbox_id=11`, etc.
            $withComma = '%"inbox_id":'.$inboxId.',%';
            $withBrace = '%"inbox_id":'.$inboxId.'}%';
            $rows = (clone $base)
                ->where(static function (Builder $q) use ($withComma, $withBrace): void {
                    $q->where('metadata', 'like', $withComma)
                        ->orWhere('metadata', 'like', $withBrace);
                })
                ->pluck('id')
                ->all();
        }

        $ids = [];
        foreach ($rows as $row) {
            if (is_numeric($row)) {
                $ids[] = (int) $row;
            }
        }

        return $ids;
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
     * parameter to scope the consent flow to the existing inbox row.
     * That preserves the inbox's accumulated `inbox_messages` rows, its
     * stored `.eml` blobs, and the provider-side cursor so a re-consent
     * resumes scanning where it left off rather than backfilling from
     * scratch.
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

        // Dispatch the modal's own open event rather than dispatching
        // `modal-show` directly. The modal listens for this event,
        // sets its `$provider` property (so the body branch renders
        // the right variant and the `<flux:modal>` mounts under the
        // provider-suffixed name), then itself dispatches `modal-show`
        // against the now-correct name. Dispatching `modal-show` from
        // here would target a name that does not exist in the DOM for
        // any provider other than the default-rendered one, so the
        // button click would silently no-op.
        $this->dispatch('oauth-client-wizard:open', provider: $provider);

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
            'oauthCanceledMessage' => $this->oauthCanceledMessage,
            'oauthFailedMessage' => $this->oauthFailedMessage,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Inboxes · beatrax']);

        return $view;
    }
}
