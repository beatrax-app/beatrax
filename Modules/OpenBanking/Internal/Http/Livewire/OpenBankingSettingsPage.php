<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;

/**
 * `/settings/open-banking` trust surface (19-11, UI-SPEC Surface B): the
 * off-by-default toggle gated behind the loud third-party-data warning
 * (Req 4, server-enforced), the always-visible transparency panel +
 * disconnect (Req 6), and the consent-expiry banner + re-link flow (Req
 * 7/8). This is the page that finally mounts `OpenBankingWizardModal`
 * (19-06) — unreachable from any surface until this plan.
 *
 * Req 4's server-side proof (RESEARCH.md Pitfall 7): the interaction
 * contract is `requestEnable()` (opens the B2 modal, never flips state) ->
 * `confirmWarning()` (persists the acknowledgement to the SESSION, not
 * just this component's `$acknowledged` property) -> the wizard -> the
 * external SCA redirect -> `OpenBankingCallbackController`'s server
 * redirect back here with a `open_banking_connected` flash. That final
 * hop mounts a BRAND NEW component instance (the browser left this page
 * entirely for the bank's consent screen), so the acknowledgement cannot
 * live on a Livewire component property — it MUST survive in the session.
 * `enableOpenBanking()` is the ONE method that ever sets
 * `open_banking_connections.enabled = true`, and it independently
 * re-validates the session flag every time it runs — a direct
 * `Livewire::test(...)->call('enableOpenBanking')` bypassing the wizard
 * sequencing entirely is a structural no-op without that flag.
 *
 * Service collaborators arrive as parameters on each action method — the
 * Livewire strict-rules ruleset forbids constructor-DI on Component
 * subclasses (verbatim convention from every other Livewire component in
 * this codebase).
 */
final class OpenBankingSettingsPage extends Component
{
    // -------------------------------------------------------------------
    // Connection state (OpenBankingConnectionQuery::current() projection)
    // -------------------------------------------------------------------

    public bool $enabled = false;

    public int $connectionId = 0;

    public string $institutionId = '';

    public string $bankDisplayName = '';

    /** One of 'off' | 'connected' | 'expiring' | 'expired'. */
    public string $consentStatus = 'off';

    public ?string $consentExpiresAtIso = null;

    public ?string $lastSuccessfulSyncAtIso = null;

    public ?string $lastAttemptAtIso = null;

    public ?string $lastAttemptStatus = null;

    public string $aggregator = 'Enable Banking';

    public string $whatsFetched = '';

    // -------------------------------------------------------------------
    // B2: loud third-party-data warning modal
    // -------------------------------------------------------------------

    public bool $showWarningModal = false;

    public bool $acknowledged = false;

    // -------------------------------------------------------------------
    // Disconnect confirm (shared by the ON->OFF toggle click AND the B4
    // "Disconnect" button — one action, two entry points, UI-SPEC)
    // -------------------------------------------------------------------

    public bool $showDisconnectModal = false;

    // -------------------------------------------------------------------
    // Redirect-flash handoff from OpenBankingCallbackController
    // -------------------------------------------------------------------

    /**
     * The connection id `OpenBankingCallbackController` just created/
     * updated, read from the `open_banking_connected` flash. Non-null
     * only for the one request immediately following the SCA redirect
     * back to this page.
     */
    public ?int $pendingConnectionId = null;

    public string $flashMessage = '';

    /** One of '' | 'success' | 'error'. */
    public string $flashTone = '';

    public function mount(
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
        Session $session,
        DatabaseManager $db,
        Clock $clock,
    ): void {
        $this->consumeRedirectFlashes($session);
        $this->refreshState($currentUser, $query);

        // No-op unless the redirect flash carried a pending connection id
        // AND the session's acknowledgement flag is set (see class
        // docblock) — reuses the same gated sink a direct test call hits.
        $this->enableOpenBanking($db, $clock, $session, $currentUser, $query);
    }

    // -------------------------------------------------------------------
    // B1: toggle + loud-warning gate
    // -------------------------------------------------------------------

    /**
     * Routes the single toggle control to the correct flow: OFF ->
     * requestEnable() (opens B2, UI-SPEC "does NOT flip the toggle
     * directly"); ON -> the shared disconnect confirm (UI-SPEC: "turning
     * the toggle off and disconnecting are the same backend action").
     */
    public function toggleClicked(): void
    {
        if ($this->enabled) {
            $this->startDisconnect();

            return;
        }

        $this->requestEnable();
    }

    /**
     * Opens the B2 loud-warning modal. Deliberately does NOT touch
     * `$this->enabled` or any DB state — UI-SPEC's interaction contract
     * requires the toggle to visually stay off until wizard completion.
     */
    public function requestEnable(): void
    {
        if ($this->enabled) {
            return;
        }

        $this->acknowledged = false;
        $this->showWarningModal = true;
    }

    public function cancelWarning(): void
    {
        $this->showWarningModal = false;
        $this->acknowledged = false;
    }

    /**
     * B2's "Enable open banking" confirm action. Gated on
     * `$this->acknowledged === true` — the Blade template already disables
     * the button until the checkbox is checked, but this server-side check
     * is not decorative: it is the FIRST of two independent gates (the
     * second, load-bearing one is `enableOpenBanking()`'s own re-check —
     * see class docblock). Persists the acknowledgement to the session so
     * it survives the external SCA redirect the wizard is about to start.
     */
    public function confirmWarning(Session $session): void
    {
        if ($this->acknowledged !== true) {
            return;
        }

        $session->put('open_banking_acknowledged', true);
        $this->showWarningModal = false;
        $this->acknowledged = false;

        $this->dispatch('open-banking-wizard:open');
    }

    /**
     * THE sink that flips `open_banking_connections.enabled = true`
     * (Req 4 — RESEARCH.md Pitfall 7's named method). Independently
     * re-validates the session-persisted acknowledgement flag on every
     * call — never trusts that a caller only reaches this method after
     * the B2 modal's client-side sequencing ran. Scoped to the current
     * user's own connection row (T-19-11-04-adjacent: `pendingConnectionId`
     * is an attacker-settable Livewire property; the `user_id` predicate
     * is what actually prevents a cross-user enable).
     */
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
        if ($session->get('open_banking_acknowledged') !== true) {
            return;
        }

        $db->connection()->table('open_banking_connections')
            ->where('id', $this->pendingConnectionId)
            ->where('user_id', $currentUser->user()->id)
            ->update([
                'enabled' => true,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        $session->forget('open_banking_acknowledged');
        $this->pendingConnectionId = null;
        $this->refreshState($currentUser, $query);
    }

    // -------------------------------------------------------------------
    // Disconnect (Task 2 completes the transparency-panel entry point;
    // the toggle-off entry point is wired here in Task 1)
    // -------------------------------------------------------------------

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

    /**
     * Shared by BOTH entry points (the ON->OFF toggle click and the B4
     * "Disconnect" button) — one action, two callers, per UI-SPEC's
     * "avoids a confusing dual-control" note. Clears the on-disk secrets
     * entry (credentials + session/consent) and flips `enabled=false` +
     * blanks the local consent-expiry column so this connection can never
     * read as still-live (T-19-11-03).
     */
    public function disconnect(
        OpenBankingSecretsRepository $secrets,
        DatabaseManager $db,
        Clock $clock,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $secrets->clear();

        if ($this->connectionId > 0) {
            $db->connection()->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->where('user_id', $currentUser->user()->id)
                ->update([
                    'enabled' => false,
                    'consent_expires_at' => null,
                    'updated_at' => $clock->now()->toDateTimeString(),
                ]);
        }

        $this->showDisconnectModal = false;
        $this->refreshState($currentUser, $query);
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('openbanking::livewire.open-banking-settings-page');
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    /**
     * Human-readable "Last successful sync" display per UI-SPEC B4:
     * "2 hours ago · 19 Jul 2026, 14:03" — relative + absolute, never a
     * bare label (Copywriting Contract: "never a bare 'Synced' label
     * without the accompanying relative/absolute timestamp").
     */
    public function lastSuccessfulSyncDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastSuccessfulSyncAtIso);
    }

    public function lastAttemptDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastAttemptAtIso);
    }

    private static function relativeAndAbsolute(?string $iso): ?string
    {
        if ($iso === null) {
            return null;
        }

        $dt = CarbonImmutable::parse($iso);

        return $dt->diffForHumans().' · '.$dt->format('d M Y, H:i');
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
        // Consent status only carries meaning while OB is actually
        // enabled — a disconnected/never-finalized row never renders as
        // "expired" (that would look like a live-but-broken connection).
        $this->consentStatus = $view->enabled ? $view->consentStatus : 'off';
        $this->consentExpiresAtIso = $view->consentExpiresAt?->toIso8601String();
        $this->lastSuccessfulSyncAtIso = $view->lastSuccessfulSyncAt?->toIso8601String();
        $this->lastAttemptAtIso = $view->lastAttemptAt?->toIso8601String();
        $this->lastAttemptStatus = $view->lastAttemptStatus;
        $this->aggregator = $view->aggregator;
        $this->whatsFetched = $view->whatsFetched;
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
