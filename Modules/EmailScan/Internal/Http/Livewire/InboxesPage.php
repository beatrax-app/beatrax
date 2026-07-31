<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Http\Livewire;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
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
use Modules\EmailScan\Public\Enums\InboxScanStatus;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\DiscoveredSenderQuery;
use Modules\EmailScan\Public\Services\InboxQuery;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * @link ../../../../../.docs/features/email-scan/architecture.md
 */
final class InboxesPage extends Component
{
    private const INBOX_NOT_FOUND = 'Inbox not found.';

    // Populated from the single-use open_backfill_modal session flash
    // the OAuth callback controller writes; when set, the Blade layer
    // auto-dispatches backfill-window:open scoped to this inbox id.
    public ?int $openBackfillForInboxId = null;

    // One-shot oauth_canceled flash carried from the OAuth callback
    // controller through mount(), read here (not via the session()
    // global helper in Blade) so DI-only applies to the render prop too.
    public ?string $oauthCanceledMessage = null;

    // One-shot oauth_failed flash — same rationale as
    // $oauthCanceledMessage above.
    public ?string $oauthFailedMessage = null;

    // Inbox id from the ?reconnect={id} query parameter; when it
    // resolves to a gmail/microsoft inbox owned by the current user,
    // mount() auto-dispatches oauth-client-wizard:open against it. A
    // foreign or missing id is silently ignored (404-not-403 contract).
    #[Url(as: 'reconnect', except: '')]
    public ?int $reconnectInboxId = null;

    public function mount(
        Request $request,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        LoggerInterface $logger,
    ): void {
        // The OAuth callback redirects with a session flash carrying
        // the freshly-connected inbox id; pick it up and dispatch
        // backfill-window:open. hasSession() guards a direct Livewire
        // test harness that boots without a bound session.
        if ($request->hasSession()) {
            $this->consumeOAuthFlashes($request->session());
        }

        $this->handleReconnectQueryParam($currentUser, $inboxQuery, $logger);
    }

    // Every flash below uses pull() (single-use) so a later wire:poll
    // tick or back-button revisit doesn't re-fire the modal or repaint
    // a stale canceled/failed banner.
    private function consumeOAuthFlashes(Session $session): void
    {
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

    // ?reconnect={id} hand-off: the SystemAlertsBanner Reconnect link
    // routes here, auto-dispatching the modal-open event. Cross-user or
    // missing ids are silently ignored (404-not-403) but logged at info
    // so an operator can see the attempt.
    private function handleReconnectQueryParam(
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        LoggerInterface $logger,
    ): void {
        $reconnectId = $this->reconnectInboxId;
        if ($reconnectId === null || $reconnectId <= 0) {
            return;
        }

        $user = $currentUser->user();
        $inbox = $inboxQuery->findForUser($reconnectId, $user);
        if ($inbox !== null && MailProvider::tryFrom($inbox->provider) !== null) {
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

    // Optimistically acknowledges the user's active
    // oauth_reconsent_required alert for an inbox when the OAuth
    // wizard signals a re-consent dance started; if the refresh later
    // fails, RaiseReconsentAlertOnTokenFailure re-raises a fresh alert.
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
                // Cross-row race: already acknowledged between the
                // lookup and the call. Silent no-op keeps this idempotent.
            }
        }
    }

    // Resolves the active oauth_reconsent_required alert ids for the
    // given user + inbox, preferring SQLite's json_extract and falling
    // through to a LIKE form when the JSON1-extension query throws.
    /**
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

    // Opens the backfill window modal scoped to a specific inbox row
    // (wired to the inline Edit link) by dispatching the event
    // BackfillWindowModal listens for via #[On(...)].
    public function editWindow(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
    ): void {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException(self::INBOX_NOT_FOUND);
        }
        $this->dispatch(
            'backfill-window:open',
            inboxId: $inboxId,
            currentWindow: $health->backfillWindowMonths,
        );
    }

    // Polling target for the backfill progress strip
    // (wire:poll.2s="refreshBackfillProgress"); intentionally a no-op
    // because Livewire's re-render on every tick already re-queries
    // InboxQuery for the live backfill_progress payload.
    public function refreshBackfillProgress(): void {}

    // Manually triggers an incremental scan (the "Scan now" button),
    // dispatching IncrementalScanJob whose ShouldBeUnique constraint
    // deduplicates a rapid double-click into one queued job. No-ops
    // with a toast when the inbox is already backfilling/scanning.
    public function scanNow(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        Dispatcher $bus,
    ): void {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException(self::INBOX_NOT_FOUND);
        }
        if (in_array($health->status, [InboxScanStatus::Backfilling->value, InboxScanStatus::Scanning->value], strict: true)) {
            $this->dispatch('toast', message: 'Scan already in progress.');

            return;
        }
        $bus->dispatch(new IncrementalScanJob($inboxId));
        $this->dispatch('toast', message: 'Scan started.');
    }

    // Kicks off the per-inbox OAuth consent re-grant flow for a row
    // stuck in needs_reauth, redirecting to
    // /oauth/connect/{provider}?inbox_id={id} so OAuthConnectController
    // resumes the existing row instead of backfilling from scratch.
    public function reconnect(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        UrlGenerator $urls,
    ): mixed {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            throw new NotFoundHttpException(self::INBOX_NOT_FOUND);
        }

        $target = $urls->route('oauth.connect', ['provider' => $health->provider])
            .'?inbox_id='.$inboxId;

        // Livewire 3+ honours $this->redirect($url) for client-side
        // navigation (matches the openWizard() pattern above which uses
        // $this->redirectRoute(...)). Plain RedirectResponse return is
        // not picked up by the Livewire wire:click protocol.
        return $this->redirect($target);
    }

    // Promotes a discovered_senders candidate into known_senders and
    // transitions the row to state='added' (wired to the "Add" chip).
    // PromoteDiscoveredSender is fully idempotent — an already
    // promoted/dismissed row is a silent no-op.
    public function promoteSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        PromoteDiscoveredSender $promote,
    ): void {
        ($promote)($discoveredSenderId, $currentUser->user());
        $this->dispatch('toast', message: 'Sender added.');
    }

    // Dismisses a discovered_senders candidate. Mirror of
    // promoteSender — idempotent + cross-user 404 inside the action.
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
        if (MailProvider::tryFrom($provider) === null) {
            return null;
        }

        if ($secrets->hasProviderClient($provider)) {
            return $this->redirectRoute('oauth.connect', ['provider' => $provider]);
        }

        // Dispatches the modal's own open event rather than
        // modal-show directly: the modal sets its $provider property
        // and mounts under the provider-suffixed name before itself
        // dispatching modal-show against that now-correct name.
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

        // Skips the discovered-senders query when the user has no
        // connected inboxes: the join would always return zero rows,
        // saving a query on every empty-state render and wire:poll tick.
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
