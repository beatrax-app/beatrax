<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Internal\Exceptions\EnableBankingApiException;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\FormatsConnectionTimestamps;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\ManagesGuidedIcsImport;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingFetchService;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Throwable;

final class OpenBankingSettingsPage extends Component
{
    use FormatsConnectionTimestamps;
    use HoldsFlashMessage;
    use ManagesGuidedIcsImport;
    use WithFileUploads;

    // 2 hours covers a first-time application registration plus SCA, without
    // letting an abandoned tab leave a standing authorization in the session.
    private const ACK_TTL_SECONDS = 7200;

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public int $connectionId = 0;

    public string $institutionId = '';

    public string $bankDisplayName = '';

    /** @var 'off'|'connected'|'expiring'|'expired' */
    #[Locked]
    public string $consentStatus = 'off';

    public ?string $consentExpiresAtIso = null;

    public ?string $lastSuccessfulSyncAtIso = null;

    public ?string $lastAttemptAtIso = null;

    public ?string $lastAttemptStatus = null;

    public string $aggregator = 'Enable Banking';

    public string $whatsFetched = '';

    public bool $showWarningModal = false;

    public bool $acknowledged = false;

    #[Locked]
    public bool $warningShown = false;

    public bool $showDisconnectModal = false;

    #[Locked]
    public ?int $pendingConnectionId = null;

    #[Locked]
    public bool $needsReconfirm = false;

    /** @var ''|'success'|'error' */
    public string $flashTone = '';

    public string $syncFlashMessage = '';

    /** @var ''|'success'|'zero'|'error' */
    public string $syncFlashTone = '';

    public ?int $syncReviewImportRunId = null;

    public function mount(
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
        Session $session,
        DatabaseManager $db,
        Clock $clock,
    ): void {
        $this->consumeRedirectFlashes($session);
        $this->refreshState($currentUser, $query);
        $this->enableOpenBanking($db, $clock, $session, $currentUser, $query);

        // enableOpenBanking() clears pendingConnectionId only on success, so a
        // still-set id here means the SCA callback landed on a lapsed ack.
        if ($this->pendingConnectionId !== null && ! $this->hasFreshAcknowledgement($session, $clock)) {
            $this->needsReconfirm = true;
        }
    }

    public function toggleClicked(): void
    {
        if ($this->enabled) {
            $this->startDisconnect();

            return;
        }

        $this->requestEnable();
    }

    // Deliberately does not touch $this->enabled or any DB state — the
    // toggle must visually stay off until wizard completion.
    public function requestEnable(): void
    {
        if ($this->enabled) {
            return;
        }

        $this->acknowledged = false;
        $this->warningShown = true;
        $this->showWarningModal = true;
    }

    public function cancelWarning(): void
    {
        $this->showWarningModal = false;
        $this->acknowledged = false;
        $this->warningShown = false;
    }

    public function confirmWarning(Session $session, Clock $clock): void
    {
        if (! $this->warningShown) {
            return;
        }
        if ($this->acknowledged !== true) {
            return;
        }

        $session->put('open_banking_acknowledged', $clock->now()->getTimestamp());
        $this->showWarningModal = false;
        $this->acknowledged = false;
        $this->warningShown = false;

        $this->dispatch('open-banking-wizard:open');
    }

    public function enableOpenBanking(
        DatabaseManager $db,
        Clock $clock,
        Session $session,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        if ($this->pendingConnectionId === null) {
            return;
        }
        if (! $this->hasFreshAcknowledgement($session, $clock)) {
            return;
        }

        $userId = $currentUser->user()->id;
        $now = $clock->now()->toDateTimeString();

        // Single-live-connection model: a stale prior-institution row must
        // never keep being picked up by the daily-sync scheduler.
        $db->connection()->table('open_banking_connections')
            ->where('user_id', $userId)
            ->where('id', '!=', $this->pendingConnectionId)
            ->update([
                'enabled' => false,
                'consent_expires_at' => null,
                'updated_at' => $now,
            ]);

        $db->connection()->table('open_banking_connections')
            ->where('id', $this->pendingConnectionId)
            ->where('user_id', $userId)
            ->update([
                'enabled' => true,
                'updated_at' => $now,
            ]);

        $session->forget('open_banking_acknowledged');
        $this->pendingConnectionId = null;
        $this->refreshState($currentUser, $query);
    }

    public function reconfirmEnable(
        DatabaseManager $db,
        Clock $clock,
        Session $session,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        if ($this->pendingConnectionId === null) {
            $this->needsReconfirm = false;

            return;
        }

        $session->put('open_banking_acknowledged', $clock->now()->getTimestamp());

        $this->enableOpenBanking($db, $clock, $session, $currentUser, $query);
        $this->needsReconfirm = false;
    }

    public function startDisconnect(): void
    {
        if (! $this->enabled) {
            return;
        }
        $this->showDisconnectModal = true;
    }

    public function cancelDisconnect(): void
    {
        $this->showDisconnectModal = false;
    }

    public function disconnect(
        OpenBankingSecretsRepository $secrets,
        DatabaseManager $db,
        Clock $clock,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $secrets->clear();

        // Every row, not just the displayed one: an orphaned row from a previous
        // institution would keep syncing after the user believes they are off.
        $db->connection()->table('open_banking_connections')
            ->where('user_id', $currentUser->user()->id)
            ->update([
                'enabled' => false,
                'consent_expires_at' => null,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        $this->showDisconnectModal = false;
        $this->refreshState($currentUser, $query);
    }

    public function reconnect(): void
    {
        if ($this->connectionId <= 0 || $this->institutionId === '') {
            return;
        }

        [$bankChoice, $otherInstitutionId] = self::wizardChoiceFor($this->institutionId);

        $this->dispatch(
            'open-banking-wizard:open',
            startStep: 4,
            bankChoice: $bankChoice,
            otherInstitutionId: $otherInstitutionId,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function wizardChoiceFor(string $institutionId): array
    {
        return match ($institutionId) {
            'ASNBNL21' => ['asn', ''],
            'SNSBNL21' => ['sns', ''],
            default => ['other', $institutionId],
        };
    }

    public function syncNow(
        OpenBankingFetchService $fetchService,
        DatabaseManager $db,
        Clock $clock,
        Dispatcher $events,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $this->syncFlashMessage = '';
        $this->syncFlashTone = '';
        $this->syncReviewImportRunId = null;

        if (! $this->enabled || $this->consentStatus === 'expired') {
            return;
        }

        $user = $currentUser->user();
        $now = $clock->now()->toDateTimeString();

        try {
            $preview = $fetchService->preview($this->connectionId, $user);
        } catch (Throwable $e) {
            $isConsentFailure = EnableBankingApiException::consentFailureWithin($e);

            $db->connection()->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->where('user_id', $user->id)
                ->update([
                    // Deliberately omits last_successful_sync_at: a failed
                    // attempt must never advance the freshness signal.
                    'last_attempt_at' => $now,
                    'last_attempt_status' => $isConsentFailure ? 'consent_failed' : 'error',
                    'updated_at' => $now,
                ]);

            if ($isConsentFailure) {
                $events->dispatch(new OpenBankingConsentFailed(
                    connectionId: $this->connectionId,
                    userId: $user->id,
                    reason: substr($e->getMessage(), 0, 500),
                ));
            }

            $this->refreshState($currentUser, $query);
            $this->syncFlashTone = 'error';
            $this->syncFlashMessage = $isConsentFailure
                ? Lang::get('openbanking::messages.sync.consent_expired')
                : Lang::get('openbanking::messages.sync.unavailable');

            return;
        }

        $db->connection()->table('open_banking_connections')
            ->where('id', $this->connectionId)
            ->where('user_id', $user->id)
            ->update([
                'last_successful_sync_at' => $now,
                'last_attempt_at' => $now,
                'last_attempt_status' => 'ok',
                'updated_at' => $now,
            ]);

        $this->refreshState($currentUser, $query);

        $newCount = count(array_filter(
            $preview->rows,
            static fn (PreviewRowDto $row): bool => $row->status === 'new',
        ));

        if ($newCount > 0) {
            $this->syncFlashTone = 'success';
            $this->syncFlashMessage = Lang::choice('openbanking::messages.sync.new_found', $newCount);
            $this->syncReviewImportRunId = $preview->importRunId;

            return;
        }

        $this->syncFlashTone = 'zero';
        $this->syncFlashMessage = Lang::get('openbanking::messages.sync.none');
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('openbanking::livewire.open-banking-settings-page');

        // layouts.app is a @yield('content') layout: without this extends()
        // the shell rendered an empty <main> and a tab titled just "Beatrax".
        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('openbanking::messages.page.heading').' · Beatrax']);

        return $view;
    }

    private function refreshState(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        $view = $query->current($currentUser->user()->id);

        if ($view === null) {
            $this->enabled = false;
            $this->connectionId = 0;
            $this->institutionId = '';
            $this->bankDisplayName = '';
            $this->consentStatus = 'off';
            $this->consentExpiresAtIso = null;
            $this->lastSuccessfulSyncAtIso = null;
            $this->lastAttemptAtIso = null;
            $this->lastAttemptStatus = null;
            $this->whatsFetched = '';

            return;
        }

        $this->enabled = $view->enabled;
        $this->connectionId = $view->connectionId;
        $this->institutionId = $view->institutionId;
        $this->bankDisplayName = $view->bankDisplayName;
        $this->consentStatus = $view->enabled ? $view->consentStatus : 'off';
        $this->consentExpiresAtIso = $view->consentExpiresAt?->toIso8601String();
        $this->lastSuccessfulSyncAtIso = $view->lastSuccessfulSyncAt?->toIso8601String();
        $this->lastAttemptAtIso = $view->lastAttemptAt?->toIso8601String();
        $this->lastAttemptStatus = $view->lastAttemptStatus;
        $this->aggregator = $view->aggregator;
        $this->whatsFetched = $view->whatsFetched;
    }

    private function hasFreshAcknowledgement(Session $session, Clock $clock): bool
    {
        $ackAt = $session->get('open_banking_acknowledged');
        if (! is_int($ackAt)) {
            return false;
        }

        $age = $clock->now()->getTimestamp() - $ackAt;

        return $age >= 0 && $age <= self::ACK_TTL_SECONDS;
    }

    private function consumeRedirectFlashes(Session $session): void
    {
        $connectedRaw = $session->pull('open_banking_connected');
        if (is_numeric($connectedRaw)) {
            $this->pendingConnectionId = (int) $connectedRaw;
        }

        $failed = $session->pull('open_banking_failed');
        if (is_string($failed) && $failed !== '') {
            $this->flashMessage = $failed;
            $this->flashTone = 'error';
        }

        $canceled = $session->pull('open_banking_canceled');
        if (is_string($canceled) && $canceled !== '') {
            $this->flashMessage = $canceled;
            $this->flashTone = 'error';
        }
    }
}
