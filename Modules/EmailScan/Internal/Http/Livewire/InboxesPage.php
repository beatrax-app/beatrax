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
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\EmailScan\Internal\Jobs\IncrementalScanJob;
use Modules\EmailScan\Public\Actions\DisconnectInbox;
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

final class InboxesPage extends Component
{
    use DispatchesToast;

    private const INBOX_NOT_FOUND = 'Inbox not found.';

    public ?int $openBackfillForInboxId = null;

    // Carried through mount() rather than read via the session() global in
    // Blade, so the DI-only rule covers the render props too.
    public ?string $oauthCanceledMessage = null;

    public ?string $oauthFailedMessage = null;

    #[Url(as: 'reconnect', except: '')]
    public ?int $reconnectInboxId = null;

    public function mount(
        Request $request,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        LoggerInterface $logger,
    ): void {
        // hasSession() guards a direct Livewire test harness that boots
        // without a bound session.
        if ($request->hasSession()) {
            $this->consumeOAuthFlashes($request->session());
        }

        $this->handleReconnectQueryParam($currentUser, $inboxQuery, $logger);
    }

    // pull() is single-use: a later wire:poll tick or back-button revisit
    // must not re-fire the modal or repaint a stale banner.
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

    // Cross-user or missing ids are silently ignored (404-not-403) but
    // logged at info so an operator can still see the attempt.
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

    // Optimistic: if the refresh later fails, RaiseReconsentAlertOnTokenFailure
    // raises a fresh alert.
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
                // The id list and this call are separate statements, so a
                // concurrent acknowledge from another surface can retire the
                // row in between — the alert is already in the wanted state.
            }
        }
    }

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
            // SQLite LIKE has no character classes, so the trailing boundary
            // is OR-ed as comma-or-brace: `inbox_id=1` must not match
            // `inbox_id=10`.
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

    // Intentionally a no-op: Livewire's re-render on each wire:poll tick
    // already re-queries InboxQuery for the live backfill_progress payload.
    public function refreshBackfillProgress(): void {}

    // IncrementalScanJob's ShouldBeUnique collapses a rapid double-click
    // into one queued job.
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
            $this->toast(Lang::get('email-scan::inboxes.toast.scan_in_progress'));

            return;
        }
        $bus->dispatch(new IncrementalScanJob($inboxId));
        $this->toast(Lang::get('email-scan::inboxes.toast.scan_started'));
    }

    // ?inbox_id={id} is what makes OAuthConnectController resume the
    // existing row instead of connecting a second inbox.
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

        // A returned RedirectResponse is not picked up by the wire:click
        // protocol; $this->redirect() is.
        return $this->redirect($target);
    }

    public function disconnect(
        int $inboxId,
        CurrentUser $currentUser,
        DisconnectInbox $disconnect,
    ): void {
        ($disconnect)($inboxId, $currentUser->user());
    }

    // PromoteDiscoveredSender is idempotent — an already promoted or
    // dismissed row is a silent no-op.
    public function promoteSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        PromoteDiscoveredSender $promote,
    ): void {
        ($promote)($discoveredSenderId, $currentUser->user());
        $this->toast(Lang::get('email-scan::inboxes.toast.sender_added'));
    }

    public function dismissSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        DismissDiscoveredSender $dismiss,
    ): void {
        ($dismiss)($discoveredSenderId, $currentUser->user());
        $this->toast(Lang::get('email-scan::inboxes.toast.sender_dismissed'));
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

        // Not modal-show directly: the modal has to set $provider and mount
        // under the provider-suffixed name before it can be shown.
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

        // With no inboxes the join always returns zero rows — skipping it
        // saves a query on every empty-state render and wire:poll tick.
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
        $view->extends('layouts.app', ['title' => Lang::get('email-scan::inboxes.heading').' · Beatrax']);

        return $view;
    }
}
