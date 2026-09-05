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
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\OpenBanking\Internal\Dto\OpenBankingConnectionView;
use Modules\OpenBanking\Internal\Enums\WizardStep;
use Modules\OpenBanking\Internal\Exceptions\OpenBankingCredentialsException;
use Modules\OpenBanking\Internal\Http\Livewire\Concerns\ManagesGuidedIcsImport;
use Modules\OpenBanking\Internal\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

final class OpenBankingSettingsPage extends Component
{
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

    // The connection ids this reader holds session material for, in row order.
    // One card is mounted per id, so nothing on this page has to pick which of
    // two connected banks it speaks for.
    /** @var list<int> */
    #[Locked]
    public array $connectionIds = [];

    public string $connectedBanks = '';

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

    // The credentials live in one file this screen neither writes nor owns, so
    // an unreadable one is a state the page reports rather than a fault it
    // raises. Every read of it reaches the reader through refreshState().
    public bool $credentialsUnreadable = false;

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

        // Only the row the reader just consented to. The banks already
        // connected keep their own consent and their own schedule: each holds
        // its own secret, so enabling one says nothing about the others.
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
        $secrets->clear($currentUser->user()->id);

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

    // Straight to the bank picker: the application is already registered, and
    // the third-party warning was acknowledged when the first bank was linked.
    public function connectAnotherBank(): void
    {
        $this->dispatch('open-banking-wizard:open', startStep: WizardStep::Bank->value);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('openbanking::livewire.open-banking-settings-page');

        // layouts.app is a @yield('content') layout: without this extends()
        // the shell rendered an empty <main> and a tab titled just "Beatrax".
        $view->extends('layouts.app', ['title' => Lang::get('openbanking::messages.page.heading').Brand::TITLE_SUFFIX]);

        return $view;
    }

    private function refreshState(CurrentUser $currentUser, OpenBankingConnectionQuery $query): void
    {
        try {
            $views = $query->forUser($currentUser->user()->id);
        } catch (OpenBankingCredentialsException) {
            $this->credentialsUnreadable = true;
            $views = [];
        }

        $this->connectionIds = array_map(
            static fn (OpenBankingConnectionView $view): int => $view->connectionId,
            $views,
        );
        $this->connectedBanks = implode(', ', array_map(
            static fn (OpenBankingConnectionView $view): string => $view->bankDisplayName,
            $views,
        ));
        $this->enabled = array_any($views, static fn (OpenBankingConnectionView $view): bool => $view->enabled);
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
