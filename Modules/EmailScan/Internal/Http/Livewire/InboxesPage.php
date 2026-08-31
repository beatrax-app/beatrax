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
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\OAuthAlertKind;
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
use Modules\EmailScan\Public\Services\InboxScanSchedule;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class InboxesPage extends Component
{
    use DispatchesToast;

    public ?int $openBackfillForInboxId = null;

    // Carried through mount() rather than read via the session() global in
    // Blade, so the DI-only rule covers the render props too.
    public ?string $oauthCanceledMessage = null;

    public ?string $oauthFailedMessage = null;

    #[Url(as: 'reconnect', except: null)]
    public ?int $reconnectInboxId = null;

    // The five schedule entries that drive this screen are Schedule::call()
    // closures, so no device manifest carries them and no phone ever scans a
    // mailbox. The copy branches here rather than averaging the two platforms.
    #[Locked]
    public bool $onPhone = false;

    public function mount(
        Request $request,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
        LoggerInterface $logger,
    ): void {
        // The seam that decides whether a scan runs here, rather than the
        // platform it is derived from: the copy and the controls below both
        // mean "no scan runs on this device", which is this and not "is a
        // phone". A phone that gains the scan retires both by one line.
        $this->onPhone = ! InboxScanSchedule::runsOnThisDevice();

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
            ->where('kind', OAuthAlertKind::ReconsentRequired->value)
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

    // The ownership read stays; what changes is the answer when it comes back
    // empty. This page polls, so the row a second tab retired is still on
    // screen here — a toast and a re-render, never a 404 over the list.
    public function editWindow(
        int $inboxId,
        CurrentUser $currentUser,
        InboxQuery $inboxQuery,
    ): void {
        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);
        if ($health === null) {
            $this->toastGone();

            return;
        }
        $this->dispatch(
            'backfill-window:open',
            inboxId: $inboxId,
            currentWindow: $health->backfillWindowMonths,
        );
    }

    private function toastGone(): void
    {
        $this->toast(Lang::get('core::errors.no_longer_here'));
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
        // Asked before anything is dispatched: the job moves last_scan_at on
        // its way to failing, so a tap here overwrote the desktop's real "3h
        // ago" with "22s ago" and left the row in Error advising a reconnect
        // that cannot help, under a banner saying this device does not scan.
        if (! InboxScanSchedule::runsOnThisDevice()) {
            $this->toast(Lang::get('email-scan::inboxes.intro_phone'));

            return;
        }

        $user = $currentUser->user();
        $health = $inboxQuery->findForUser($inboxId, $user);

        if ($health === null) {
            $this->toastGone();

            return;
        }

        // The job exits on its first status read for a revoked grant, so
        // dispatching here would report a scan that never runs; a scan already
        // under way needs no second one.
        $refusal = match (true) {
            in_array($health->status, [InboxScanStatus::Backfilling->value, InboxScanStatus::Scanning->value], strict: true) => 'email-scan::inboxes.toast.scan_in_progress',
            $health->status === InboxScanStatus::NeedsReauth->value => 'email-scan::inboxes.toast.reconnect_first',
            default => null,
        };

        if ($refusal !== null) {
            $this->toast(Lang::get($refusal));

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
            $this->toastGone();

            return null;
        }

        $target = $urls->route('oauth.connect', [
            'provider' => $health->provider,
            'inbox_id' => $inboxId,
        ]);

        // A returned RedirectResponse is not picked up by the wire:click
        // protocol; $this->redirect() is.
        return $this->redirect($target);
    }

    public function disconnect(
        int $inboxId,
        CurrentUser $currentUser,
        DisconnectInbox $disconnect,
    ): void {
        try {
            ($disconnect)($inboxId, $currentUser->user());
        } catch (NotFoundHttpException) {
            $this->toastGone();
        }
    }

    // PromoteDiscoveredSender is idempotent — an already promoted or
    // dismissed row is a silent no-op. A row that is gone outright is not,
    // and reporting it as added would be a claim about work never done.
    public function promoteSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        PromoteDiscoveredSender $promote,
    ): void {
        try {
            ($promote)($discoveredSenderId, $currentUser->user());
        } catch (NotFoundHttpException) {
            $this->toastGone();

            return;
        }
        $this->toast(Lang::get('email-scan::inboxes.toast.sender_added'));
    }

    public function dismissSender(
        int $discoveredSenderId,
        CurrentUser $currentUser,
        DismissDiscoveredSender $dismiss,
    ): void {
        try {
            ($dismiss)($discoveredSenderId, $currentUser->user());
        } catch (NotFoundHttpException) {
            $this->toastGone();

            return;
        }
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
            'onPhone' => $this->onPhone,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('email-scan::inboxes.heading').' · Beatrax']);

        return $view;
    }
}
