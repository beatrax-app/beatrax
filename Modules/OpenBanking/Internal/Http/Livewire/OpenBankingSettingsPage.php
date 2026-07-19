<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\OpenBanking\Public\Events\OpenBankingConsentFailed;
use Modules\OpenBanking\Public\Services\OpenBankingConnectionQuery;
use Modules\OpenBanking\Public\Services\OpenBankingFetchService;
use Modules\OpenBanking\Public\Services\OpenBankingSecretsRepository;
use Psr\Log\LoggerInterface;
use Throwable;

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
 * WR-06: the genuine "the warning was shown" gate is the server-set,
 * `#[Locked]` `$warningShown` flag (flipped true only inside
 * `requestEnable()`), NOT the client-bound `$acknowledged` property.
 * `$acknowledged` is a forgeable `wire:model.live` value; `confirmWarning()`
 * therefore checks `$warningShown` first, so the real server-enforced gate
 * is `$warningShown` (set server-side) plus the external SCA dance —
 * `$acknowledged` is only the checkbox-level UX gate on top of it.
 *
 * D-16 Wave 3 review-and-fix gate (19-14) hardening: the session
 * acknowledgement is stored as an EPOCH TIMESTAMP, not a bare boolean, and
 * `enableOpenBanking()` rejects it once it is older than
 * `ACK_TTL_SECONDS`. Without this, a user who checks the box and confirms
 * the B2 warning but then abandons the wizard (closes the tab instead of
 * clicking "Cancel") would leave a live, indefinitely-valid authorization
 * token sitting in their session — later reachable directly by setting
 * the attacker-settable `$pendingConnectionId` property (see
 * `enableOpenBanking()`'s own docblock) to any of the user's own OTHER,
 * previously-disconnected connection rows, re-enabling one WITHOUT ever
 * repeating the loud-warning gate or a fresh consent dance for it.
 * `OpenBankingWizardModal::cancel()` additionally clears the flag
 * immediately on an explicit cancel, so the TTL is only ever the residual
 * (bounded) exposure window for a silently-abandoned tab.
 *
 * Service collaborators arrive as parameters on each action method — the
 * Livewire strict-rules ruleset forbids constructor-DI on Component
 * subclasses (verbatim convention from every other Livewire component in
 * this codebase).
 */
final class OpenBankingSettingsPage extends Component
{
    use WithFileUploads;

    /**
     * Generous enough to survive the full wizard dance (the user leaves
     * this tab, logs into their bank, completes 2FA, and returns) without
     * ever needing to re-acknowledge — but bounded, so an abandoned tab
     * cannot leave a standing authorization to enable OB indefinitely.
     */
    private const ACK_TTL_SECONDS = 1800;

    /**
     * The leaf format key the EXISTING ICS SourceAdapter consumes
     * (`Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter`, registered
     * in `IngestionServiceProvider`). ICS Cards's consumer portal only
     * ever exports monthly PDF statements — there is no CAMT.053/CSV ICS
     * adapter in this codebase — so Surface B7's guided drop pre-selects
     * this single format, matching `ConnectCardStep` (the onboarding
     * wizard's equivalent step) verbatim.
     */
    private const ICS_SOURCE_FORMAT = 'ics-pdf';

    // -------------------------------------------------------------------
    // Connection state (OpenBankingConnectionQuery::current() projection)
    // -------------------------------------------------------------------

    // WR-05: server-authoritative props — only ever assigned server-side
    // (mount()/refreshState()/enableOpenBanking()), never bound via
    // wire:model. #[Locked] blocks client-side tampering that could
    // re-enable / gate-spoof a connection.
    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public int $connectionId = 0;

    public string $institutionId = '';

    public string $bankDisplayName = '';

    /** One of 'off' | 'connected' | 'expiring' | 'expired'. */
    #[Locked]
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

    /**
     * WR-06: the REAL server-side "the B2 warning was actually shown" gate.
     * `$acknowledged` is a `wire:model.live` client property and is
     * therefore forgeable — a crafted request can set it true without ever
     * opening the modal. `$warningShown` is `#[Locked]` (client can never
     * set it) and is flipped true ONLY inside `requestEnable()`, i.e. only
     * once the server itself opened the B2 modal. `confirmWarning()` gates
     * on it first, so a direct `confirmWarning()` call that skips
     * `requestEnable()` is a no-op regardless of a forged `$acknowledged`.
     * Single-use: reset to false on confirm-success and on cancel/close.
     */
    #[Locked]
    public bool $warningShown = false;

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
    #[Locked]
    public ?int $pendingConnectionId = null;

    public string $flashMessage = '';

    /** One of '' | 'success' | 'error'. */
    public string $flashTone = '';

    // -------------------------------------------------------------------
    // B6: manual "Sync now" result flash (Req 9)
    // -------------------------------------------------------------------

    public string $syncFlashMessage = '';

    /** One of '' | 'success' | 'zero' | 'error'. */
    public string $syncFlashTone = '';

    /** Set only on a success flash carrying new rows — the "Review import →" target. */
    public ?int $syncReviewImportRunId = null;

    // -------------------------------------------------------------------
    // B7: guided ICS file-import affordance (Req 13, 19-15). Visually and
    // functionally separate from live OB — stores NO credentials, routes
    // to the EXISTING ics-pdf SourceAdapter via RunsImports::runFromUpload.
    // -------------------------------------------------------------------

    public ?TemporaryUploadedFile $icsStatement = null;

    public ?string $icsImportError = null;

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
        // WR-06: server-side proof the loud B2 modal was genuinely opened
        // here — the load-bearing gate confirmWarning() checks first.
        $this->warningShown = true;
        $this->showWarningModal = true;
    }

    public function cancelWarning(): void
    {
        $this->showWarningModal = false;
        $this->acknowledged = false;
        // WR-06: closing the warning without confirming revokes the
        // server-side "warning shown" proof — a later confirmWarning()
        // must go back through requestEnable() first.
        $this->warningShown = false;
    }

    /**
     * B2's "Enable open banking" confirm action. Gated FIRST on the
     * server-set `$this->warningShown` flag (WR-06): `$acknowledged` is a
     * client-bound `wire:model.live` property and is forgeable, so on its
     * own it proves nothing. `$warningShown` is `#[Locked]` and is set true
     * only inside `requestEnable()`, so a direct `confirmWarning()` that
     * never went through `requestEnable()` is a structural no-op here —
     * regardless of a crafted `$acknowledged=true`. The `$acknowledged`
     * check remains as the second, checkbox-level gate (the Blade template
     * also disables the button until it is ticked).
     *
     * Persists the acknowledgement to the session as an epoch timestamp
     * (not a bare boolean) so `enableOpenBanking()` can reject it once it
     * goes stale (`ACK_TTL_SECONDS`) — it survives the external SCA redirect
     * the wizard is about to start, but not indefinitely. `$warningShown`
     * is single-use: it is cleared here on success so a second
     * `confirmWarning()` cannot reuse the same "warning shown" proof.
     */
    public function confirmWarning(Session $session, Clock $clock): void
    {
        // WR-06: genuine server-side gate — the modal must have been opened
        // by requestEnable() on the server, not merely asserted by a forged
        // client property.
        if (! $this->warningShown) {
            return;
        }
        if ($this->acknowledged !== true) {
            return;
        }

        $session->put('open_banking_acknowledged', $clock->now()->getTimestamp());
        $this->showWarningModal = false;
        $this->acknowledged = false;
        // WR-06: single-use — consume the "warning shown" proof so it can
        // never authorize a second confirm without a fresh requestEnable().
        $this->warningShown = false;

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
        if (! $this->hasFreshAcknowledgement($session, $clock)) {
            return;
        }

        $userId = $currentUser->user()->id;
        $now = $clock->now()->toDateTimeString();

        // WR-09: single-live-connection model — enabling this row disables
        // every OTHER row for the same user (and blanks its consent, as
        // disconnect() does) so a stale prior-institution row can never
        // stay enabled=true invisibly and keep being picked up by the
        // daily-sync scheduler.
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
     *
     * D-16 Wave 3 review-and-fix gate (19-14) hardening — single-live-
     * session vs multi-row-connections honesty (19-10 deferred-items.md):
     * `open_banking_connections` has no unique constraint stopping a user
     * from accumulating one enabled row per institution over time (e.g.
     * linking a second bank without disconnecting the first — the secrets
     * file only ever holds ONE live session, so the earlier institution's
     * row is silently orphaned but stays `enabled=true` with a still-valid
     * `consent_expires_at`). The disconnect confirm copy promises
     * unconditionally that "Automatic syncing stops immediately" — so this
     * write is scoped to EVERY row belonging to the current user, not just
     * the ONE connection `OpenBankingConnectionQuery` currently resolves
     * and displays, otherwise an orphaned row from a different institution
     * would keep being picked up by the `open-banking.daily-sync`
     * scheduler after the user believes they have fully disconnected.
     */
    public function disconnect(
        OpenBankingSecretsRepository $secrets,
        DatabaseManager $db,
        Clock $clock,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $secrets->clear();

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

    // -------------------------------------------------------------------
    // B5: consent-expiry re-link flow (Req 7/8)
    // -------------------------------------------------------------------

    /**
     * `wire:click="reconnect"` on the B5 banner. Re-opens the wizard at
     * Step 4 (bank picker) — reusing the already-registered application —
     * with the previously-connected institution pre-selected, rather than
     * making the user re-choose a bank they already linked once. Never
     * touches `last_successful_sync_at`; consent status flips back to
     * "Connected" only once the re-link's callback updates
     * `consent_expires_at`, and the freshness signal itself advances only
     * on the NEXT actual successful fetch (Req 7).
     */
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

    /**
     * B5 banner body: "Your last successful sync was {relative time}." —
     * relative-only (unlike the panel's combined relative+absolute
     * display), matching the UI-SPEC copy exactly.
     */
    public function lastSuccessfulSyncRelative(): ?string
    {
        if ($this->lastSuccessfulSyncAtIso === null) {
            return null;
        }

        return CarbonImmutable::parse($this->lastSuccessfulSyncAtIso)->diffForHumans();
    }

    // -------------------------------------------------------------------
    // B6: manual "Sync now" action (D-13/Req 9)
    // -------------------------------------------------------------------

    /**
     * `wire:click="syncNow"` (UI-SPEC Surface B6). No-ops — no fetch
     * attempt, no timestamp write — when OB is off or consent has expired,
     * mirroring the same no-op rule the `open-banking.daily-sync`
     * scheduler entry enforces at its enumeration query and
     * `SyncOpenBankingAccountJob` re-checks defensively on pickup. Uses
     * `OpenBankingFetchService::preview()` (NOT `fetchAndConfirm()`) —
     * per that service's own docblock, "Sync now" routes the fetched rows
     * through the EXISTING consolidated import-preview page (Req 5) for
     * the user to review/confirm; this action never auto-commits a
     * ledger write itself.
     *
     * Two-timestamp accounting mirrors `SyncOpenBankingAccountJob::handle()`
     * exactly (RESEARCH.md Pitfall 5 — never-stale-as-fresh, Req 7):
     * `last_successful_sync_at` is written ONLY when the fetch/preview
     * itself succeeds (new rows or zero rows are both a genuine successful
     * fetch); a failed fetch updates ONLY `last_attempt_*`, leaving
     * `last_successful_sync_at` untouched. A 401/403 from Enable Banking
     * is detected the same way the job detects it (message-inspection —
     * no typed exception exists yet) and additionally dispatches
     * `OpenBankingConsentFailed` so the existing reconsent alert fires
     * immediately rather than waiting for the next scheduled tick.
     */
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
            $isConsentFailure = self::isConsentFailure($e);

            $db->connection()->table('open_banking_connections')
                ->where('id', $this->connectionId)
                ->where('user_id', $user->id)
                ->update([
                    // Deliberately NOT included: last_successful_sync_at —
                    // a failed attempt must never advance the freshness
                    // signal (RESEARCH.md Pitfall 5 / Req 7).
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
                ? 'Consent expired — reconnect.'
                : 'Enable Banking is temporarily unavailable. Try again shortly.';

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
            $this->syncFlashMessage = "{$newCount} new transactions found.";
            $this->syncReviewImportRunId = $preview->importRunId;

            return;
        }

        $this->syncFlashTone = 'zero';
        $this->syncFlashMessage = 'No new transactions.';
    }

    /**
     * No typed exception distinguishes a 401/403 consent failure from any
     * other Enable Banking error today — mirrors
     * `SyncOpenBankingAccountJob::isConsentFailure()` verbatim (both read
     * `EnableBankingHttpClient::mapErrorResponse()`'s embedded HTTP status
     * out of a generic `RuntimeException` message).
     */
    private static function isConsentFailure(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403');
    }

    // -------------------------------------------------------------------
    // B7: guided ICS file-import affordance (Req 13, UI-SPEC Surface B7)
    // -------------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // IN-03: max:1024 KB (1 MB) matches the "normally under 1 MB"
            // copy below; mimetypes checks the actual sniffed content type
            // rather than trusting the client-supplied extension alone.
            'icsStatement' => ['required', 'file', 'max:1024', 'mimetypes:application/pdf', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'icsStatement.required' => 'Drop the ICS statement you downloaded from Mijn ICS.',
            'icsStatement.max' => 'That file is too large. ICS PDF statements are normally under 1 MB each.',
            'icsStatement.extensions' => "That isn't a PDF. Mijn ICS only exports PDF statements.",
        ];
    }

    /**
     * `wire:click="importIcsStatement"` — the ONLY action this card
     * exposes. Routes the dropped file DIRECTLY through the existing ICS
     * `SourceAdapter` (`ics-pdf`) via `RunsImports::runFromUpload()`,
     * skipping the generic import wizard's source-picker entirely (the
     * format is pre-selected here, never chosen by the user). Lands in
     * the EXISTING consolidated import-preview page (Req 5/13 — reused
     * unchanged, no new preview UI) so the drop itself never auto-commits
     * a ledger write; the user still confirms on that page.
     *
     * Deliberately touches NOTHING in `OpenBankingSecretsRepository` or
     * `open_banking_connections` — this path stores no credentials and is
     * entirely independent of the OB connection state above it on the
     * page (T-19-15-01).
     */
    public function importIcsStatement(
        RunsImports $importer,
        CurrentUser $currentUser,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->icsImportError = null;
        $this->validate();

        if ($this->icsStatement === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->icsStatement->getRealPath();
        $originalFilename = self::sanitiseIcsFilename($this->icsStatement->getClientOriginalName());

        try {
            $result = $importer->runFromUpload($tmp, self::ICS_SOURCE_FORMAT, $user, $originalFilename);
        } catch (Throwable $e) {
            $logger->error('OpenBankingSettingsPage: guided ICS import preview failed.', [
                'source_format' => self::ICS_SOURCE_FORMAT,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->icsImportError = "Could not read {$originalFilename}. The full error is in /dev/logs.";

            return;
        }

        $this->redirectRoute('imports.preview', ['id' => $result->importRunId], navigate: false);
    }

    /**
     * Strips path-traversal characters and locks the extension to .pdf —
     * verbatim copy of `ConnectCardStep::sanitiseFilename()` (the
     * onboarding wizard's equivalent ICS step) since both feed the same
     * `ics-pdf` adapter.
     */
    private static function sanitiseIcsFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.pdf';
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

    /**
     * The B4 "Last attempt" row renders ONLY when the last attempt did not
     * succeed — `SyncOpenBankingAccountJob` writes `last_attempt_status`
     * as `'ok'` on success and `'consent_failed'`/`'error'` on failure
     * (never null once at least one attempt has run).
     */
    public function lastAttemptFailed(): bool
    {
        return $this->lastAttemptStatus !== null && $this->lastAttemptStatus !== 'ok';
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

    /**
     * True only when the session carries an `open_banking_acknowledged`
     * epoch timestamp AND that timestamp is within `ACK_TTL_SECONDS` of
     * now. Rejects a stale/leftover flag from an abandoned confirmWarning()
     * call (D-16 Wave 3 review hardening — see class docblock) as well as
     * any non-integer value a legacy/tampered session might carry.
     */
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
