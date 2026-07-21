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
 * @link ../../../../../.docs/features/open-banking/architecture.md
 */
final class OpenBankingSettingsPage extends Component
{
    use WithFileUploads;

    // 2 hours: must comfortably survive not just the wizard dance but a
    // first-time Enable Banking application registration. Still bounded, so
    // an abandoned tab cannot leave a standing authorization indefinitely —
    // a lapse surfaces the visible re-confirm CTA instead of a silent no-op.
    private const ACK_TTL_SECONDS = 7200;

    // The leaf format key the existing ICS SourceAdapter consumes. ICS
    // Cards's consumer portal only ever exports monthly PDF statements, so
    // the guided drop pre-selects this single format.
    private const ICS_SOURCE_FORMAT = 'ics-pdf';

    // -------------------------------------------------------------------
    // Connection state (OpenBankingConnectionQuery::current() projection)
    // -------------------------------------------------------------------

    // Server-authoritative props — only ever assigned server-side
    // (mount()/refreshState()/enableOpenBanking()), never bound via
    // wire:model. #[Locked] blocks client-side tampering that could
    // re-enable / gate-spoof a connection.
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

    // -------------------------------------------------------------------
    // B2: loud third-party-data warning modal
    // -------------------------------------------------------------------

    public bool $showWarningModal = false;

    public bool $acknowledged = false;

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
     */
    #[Locked]
    public bool $warningShown = false;

    // -------------------------------------------------------------------
    // Disconnect confirm (shared by the ON->OFF toggle click AND the B4
    // "Disconnect" button — one action, two entry points)
    // -------------------------------------------------------------------

    public bool $showDisconnectModal = false;

    // -------------------------------------------------------------------
    // Redirect-flash handoff from OpenBankingCallbackController
    // -------------------------------------------------------------------

    // Non-null only for the one request immediately following the SCA
    // redirect back to this page.
    #[Locked]
    public ?int $pendingConnectionId = null;

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
     */
    #[Locked]
    public bool $needsReconfirm = false;

    public string $flashMessage = '';

    /** @var ''|'success'|'error' */
    public string $flashTone = '';

    // -------------------------------------------------------------------
    // B6: manual "Sync now" result flash
    // -------------------------------------------------------------------

    public string $syncFlashMessage = '';

    /** @var ''|'success'|'zero'|'error' */
    public string $syncFlashTone = '';

    public ?int $syncReviewImportRunId = null;

    // -------------------------------------------------------------------
    // B7: guided ICS file-import — separate from live OB, stores no
    // credentials, routes to the existing ics-pdf SourceAdapter.
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
        $this->enableOpenBanking($db, $clock, $session, $currentUser, $query);

        // The enable above no-ops (leaving pendingConnectionId set, since it
        // clears it only on success) when the SCA callback brought back a
        // real connection but the ack had lapsed. Surface a visible
        // re-confirm CTA rather than a silent disabled connector.
        if ($this->pendingConnectionId !== null && ! $this->hasFreshAcknowledgement($session, $clock)) {
            $this->needsReconfirm = true;
        }
    }

    // -------------------------------------------------------------------
    // B1: toggle + loud-warning gate
    // -------------------------------------------------------------------

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

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
     */
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

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
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

        // Single-live-connection model: enabling this row disables every
        // other row for the same user (and blanks its consent) so a stale
        // prior-institution row can never keep being picked up by the
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

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
     */
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

    // -------------------------------------------------------------------
    // Disconnect
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
     * @link ../../../../../.docs/features/open-banking/architecture.md
     */
    public function disconnect(
        OpenBankingSecretsRepository $secrets,
        DatabaseManager $db,
        Clock $clock,
        CurrentUser $currentUser,
        OpenBankingConnectionQuery $query,
    ): void {
        $secrets->clear();

        // Scoped to EVERY row belonging to the user, not just the one
        // connection currently displayed — the disconnect copy promises
        // unconditionally that syncing stops immediately, so an orphaned
        // row from a different institution must not keep syncing.
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
    // B5: consent-expiry re-link flow
    // -------------------------------------------------------------------

    // Re-opens the wizard at Step 4 (bank picker), reusing the already-
    // registered application, with the previously-connected institution
    // pre-selected.
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

    public function lastSuccessfulSyncRelative(): ?string
    {
        if ($this->lastSuccessfulSyncAtIso === null) {
            return null;
        }

        return CarbonImmutable::parse($this->lastSuccessfulSyncAtIso)->diffForHumans();
    }

    // -------------------------------------------------------------------
    // B6: manual "Sync now" action
    // -------------------------------------------------------------------

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
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
                    // Deliberately not included: last_successful_sync_at —
                    // a failed attempt must never advance the freshness
                    // signal.
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

    // No typed exception distinguishes a 401/403 consent failure from any
    // other Enable Banking error yet — the HTTP client embeds the status in
    // a generic RuntimeException message, so inspecting it is the narrowest
    // available signal.
    private static function isConsentFailure(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'HTTP 401') || str_contains($message, 'HTTP 403');
    }

    // -------------------------------------------------------------------
    // B7: guided ICS file-import affordance
    // -------------------------------------------------------------------

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // mimetypes checks the actual sniffed content type rather than
            // trusting the client-supplied extension alone.
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
     * @link ../../../../../.docs/features/open-banking/architecture.md
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

    // Strips path-traversal characters and locks the extension to .pdf,
    // since both feed the same ics-pdf adapter.
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

    public function lastSuccessfulSyncDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastSuccessfulSyncAtIso);
    }

    public function lastAttemptDisplay(): ?string
    {
        return self::relativeAndAbsolute($this->lastAttemptAtIso);
    }

    // Renders only when the last attempt did not succeed —
    // last_attempt_status is 'ok' on success, 'consent_failed'/'error' on
    // failure, never null once at least one attempt has run.
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
        // Consent status only carries meaning while OB is actually enabled
        // — a disconnected/never-finalized row never renders as "expired".
        $this->consentStatus = $view->enabled ? $view->consentStatus : 'off';
        $this->consentExpiresAtIso = $view->consentExpiresAt?->toIso8601String();
        $this->lastSuccessfulSyncAtIso = $view->lastSuccessfulSyncAt?->toIso8601String();
        $this->lastAttemptAtIso = $view->lastAttemptAt?->toIso8601String();
        $this->lastAttemptStatus = $view->lastAttemptStatus;
        $this->aggregator = $view->aggregator;
        $this->whatsFetched = $view->whatsFetched;
    }

    /**
     * @link ../../../../../.docs/features/open-banking/architecture.md
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
