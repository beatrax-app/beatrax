<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Duration;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\OpenBanking\Internal\Dto\OpenBankingSyncOutcome;
use Modules\OpenBanking\Internal\Enums\BankChoice;
use Modules\OpenBanking\Internal\Enums\ConsentStatus;
use Modules\OpenBanking\Internal\Enums\CuratedInstitution;
use Modules\OpenBanking\Internal\Enums\WizardStep;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\FormatsConnectionTimestamps;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\ManagesGuidedIcsImport;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;
use Modules\OpenBanking\Internal\Services\OpenBankingSyncRunner;

final class OpenBankingSettingsPage extends Component
{
    use FormatsConnectionTimestamps;
    use HoldsFlashMessage;
    use ManagesGuidedIcsImport;
    use WithFileUploads;

    // 2 hours covers a first-time application registration plus SCA, without
    // letting an abandoned tab leave a standing authorization in the session.
    // How long an acknowledgement stays fresh before the page asks again.
    private static function ackTtlSeconds(): int
    {
        return 2 * Duration::Hour->seconds();
    }

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public int $connectionId = 0;

    #[Locked]
    public string $institutionId = '';

    public string $bankDisplayName = '';

    #[Locked]
    public string $consentStatus = ConsentStatus::Off->value;

    // The four neighbours of the consent status, written only by
    // refreshState() from the connection row and read by the transparency
    // panel through a date parser. Locked for the same reason it is.
    #[Locked]
    public ?string $consentExpiresAtIso = null;

    #[Locked]
    public ?string $lastSuccessfulSyncAtIso = null;

    #[Locked]
    public ?string $lastAttemptAtIso = null;

    #[Locked]
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

    /** @var ''|'success'|'zero'|'busy'|'error' */
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
        if (! $this->acknowledged) {
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
        $pendingConnectionId = $this->pendingConnectionId;

        // One transaction, because the two halves are one invariant. Standing
        // the stand-down half alone leaves every row disabled with its consent
        // blanked -- single-live-connection satisfied by having none live.
        $db->connection()->transaction(function () use ($db, $userId, $pendingConnectionId, $now): void {
            $db->connection()->table('open_banking_connections')
                ->where('user_id', $userId)
                ->where('id', '!=', $pendingConnectionId)
                ->update([
                    'enabled' => false,
                    'consent_expires_at' => null,
                    'updated_at' => $now,
                ]);

            $db->connection()->table('open_banking_connections')
                ->where('id', $pendingConnectionId)
                ->where('user_id', $userId)
                ->update([
                    'enabled' => true,
                    'updated_at' => $now,
                ]);
        });

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
            startStep: WizardStep::Bank->value,
            bankChoice: $bankChoice,
            otherInstitutionId: $otherInstitutionId,
        );
    }

    // A curated bank selects its own radio; anything else is the free-text
    // path, which carries the id the reader originally typed.
    /**
     * @return array{0: string, 1: string}
     */
    private static function wizardChoiceFor(string $institutionId): array
    {
        $curated = CuratedInstitution::tryFrom($institutionId);

        return $curated === null
            ? [BankChoice::Other->value, $institutionId]
            : [$curated->choice()->value, ''];
    }

    public function syncNow(
        OpenBankingSyncRunner $runner,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $this->syncFlashMessage = '';
        $this->syncFlashTone = '';
        $this->syncReviewImportRunId = null;

        if (! $this->enabled || ConsentStatus::from($this->consentStatus)->needsReconnect()) {
            return;
        }

        $outcome = $runner->runPreview($this->connectionId, $currentUser->user());

        if ($outcome->status === null) {
            $this->syncFlashTone = 'busy';
            $this->syncFlashMessage = Lang::get('openbanking::messages.sync.in_progress');

            return;
        }

        $this->refreshState($currentUser, $query);

        if ($outcome->failure !== null) {
            $this->syncFlashTone = 'error';
            $this->syncFlashMessage = $outcome->isConsentFailure()
                ? Lang::get('openbanking::messages.sync.consent_expired')
                : Lang::get('openbanking::messages.sync.unavailable');
        }

        $preview = $outcome->preview;

        if ($outcome->failure !== null || $preview === null) {
            return;
        }

        $this->flashPreviewOutcome($outcome, $preview);
    }

    // Ahead of every count, because a press that filed nothing is not a quiet
    // week and the sentence for a quiet week is what it used to get. The review
    // link is the only place each row says which stage refused it.
    private function flashPreviewOutcome(OpenBankingSyncOutcome $outcome, ImportPreviewResult $preview): void
    {
        $newCount = count(array_filter(
            $preview->rows,
            static fn (PreviewRowDto $row): bool => $row->status === PreviewRowStatus::NewRow,
        ));

        // Truncation is said before the count too, and in the error tone: rows
        // did arrive, but the reader is looking at part of a window and nothing
        // has been recorded as read.
        [$this->syncFlashTone, $this->syncFlashMessage, $linked] = match (true) {
            $outcome->filedNothing() => ['error', Lang::get('openbanking::messages.sync.none_importable'), true],
            $outcome->isTruncated() => ['error', Lang::get('openbanking::messages.sync.truncated'), $newCount > 0],
            $newCount > 0 => ['success', Lang::choice('openbanking::messages.sync.new_found', $newCount), true],
            default => ['zero', Lang::get('openbanking::messages.sync.none'), false],
        };

        $this->syncReviewImportRunId = $linked ? $preview->importRunId : null;
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
            $this->consentStatus = ConsentStatus::Off->value;
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
        $this->consentStatus = ($view->enabled ? $view->consentStatus : ConsentStatus::Off)->value;
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

        return $age >= 0 && $age <= self::ackTtlSeconds();
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
