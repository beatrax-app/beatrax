<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;

/**
 * @link ../../../../../.docs/features/import/architecture.md#preview-wizard-inline-account-naming
 */
final class PreviewWizard extends Component
{
    // Same literal the IcsPdfAdapter emits; AccountResolver scopes
    // lookups by (iban, user_id) so one instance-wide literal is
    // unambiguous regardless of the user.
    private const ICS_OWN_IBAN = 'ICS-CARD';

    public int $importRunId = 0;

    public string $accountName = '';

    public string $icsAccountName = '';

    public string $paypalAccountName = '';

    public bool $previewExpired = false;

    // Chain-resolution polling state, populated by
    // refreshChainResolutionStatus() from chain_resolution_runs
    // filtered by EXACT user_id (never a LIKE substring — see that
    // method). null (pre-mount) -> pending -> running -> complete/failed.
    public ?string $chainResolutionStatus = null;

    public int $chainResolutionLinkedCount = 0;

    public ?string $chainResolutionError = null;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
    }

    // Updates the affected preview row's aliasFriendlyName in the cache
    // so the next render shows the new name without re-running the
    // pipeline. Out-of-bounds rowIndex silently no-ops (returns false)
    // — a stale dispatch from a previous wizard render never throws.
    #[On('rename-counterparty:saved')]
    public function applyRenameInPlace(int $rowIndex, string $friendlyName, PreviewCache $cache): void
    {
        if ($this->importRunId <= 0) {
            return;
        }

        $cache->applyAliasInPlace($this->importRunId, $rowIndex, $friendlyName);
    }

    // wire:poll.2s target: reads the latest chain_resolution_runs row
    // and surfaces status/linked_count/last_error. NEVER reintroduce a
    // `failed_jobs.payload LIKE '%userId:N%'` lookup here (leaks cross-
    // user state) — WizardChainResolutionStatusTest greps for this.
    public function refreshChainResolutionStatus(
        DatabaseManager $db,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        // Exact user_id equality match, never a LIKE substring — a
        // substring match here would let user_id=1 match user_id=11.
        $row = $db->connection()->table('chain_resolution_runs')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(1)
            ->first(['status', 'linked_count', 'last_error']);

        if ($row === null) {
            $this->chainResolutionStatus = null;
            $this->chainResolutionLinkedCount = 0;
            $this->chainResolutionError = null;

            return;
        }

        $status = is_scalar($row->status) ? (string) $row->status : '';
        $this->chainResolutionStatus = $status === '' ? null : $status;
        $this->chainResolutionLinkedCount = is_numeric($row->linked_count) ? (int) $row->linked_count : 0;
        $lastError = is_string($row->last_error) ? $row->last_error : null;
        $this->chainResolutionError = $lastError === null ? null : substr($lastError, 0, 200);

        if ($this->chainResolutionStatus === 'complete' && $this->importRunId > 0) {
            $this->redirect(
                $urls->route('imports.results', ['id' => $this->importRunId]),
                navigate: false,
            );
        }
    }

    public function nameAccount(
        string $iban,
        string $name,
        NamesAccounts $namer,
        RunsImports $importer,
        CurrentUser $currentUser,
        PreviewCache $cache,
    ): void {
        $this->resetErrorBag('accountName');

        // Keep the property in sync so the error bag surfaces next to
        // the bound input on re-render. Validation itself is delegated
        // to AccountNamer — the single authoritative validator — so a
        // Livewire-side rules() declaration would duplicate or drift.
        $this->accountName = $name;

        // Bound to the IBANs this preview actually surfaced as unknown;
        // a crafted wire request naming an arbitrary IBAN is rejected
        // here before it reaches the namer (defence-in-depth — AccountNamer
        // also user-scopes every write).
        $preview = $cache->getPreview($this->importRunId);
        $allowedIbans = $preview === null
            ? []
            : array_map(static fn ($unknown): string => $unknown->iban, $preview->accountsToName);
        if (! in_array($iban, $allowedIbans, true)) {
            $this->addError('accountName', Lang::get('import::preview.errors.iban_not_in_preview'));

            return;
        }

        $user = $currentUser->user();

        try {
            ($namer)($iban, $name, $user);
        } catch (InvalidAccountNameException $e) {
            $this->addError('accountName', $e->getMessage());

            return;
        }

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $importer->runFromUpload(
            $importRun->raw_file_path,
            $importRun->source_format,
            $user,
            basename($importRun->raw_file_path),
            $this->formatHintForReRun($importRun->source_format),
        );

        $this->accountName = '';
    }

    // See architecture.md#preview-wizard-inline-account-naming for the
    // ICS naming branch + locked Blade copy this drives.
    public function saveIcsAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
    ): void {
        $this->resetErrorBag('icsAccountName');

        try {
            [$trimmed, $slugBody] = AccountNamer::validateName($this->icsAccountName);
        } catch (InvalidAccountNameException $e) {
            $this->addError('icsAccountName', $e->getMessage());

            return;
        }

        $this->icsAccountName = $trimmed;

        $user = $currentUser->user();

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $slugBody.'-ics-card',
            'kind' => AccountKind::IcsCard->value,
            'iban' => self::ICS_OWN_IBAN,
            'default_currency' => 'EUR',
        ]);

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $importer->runFromUpload(
            $importRun->raw_file_path,
            $importRun->source_format,
            $user,
            basename($importRun->raw_file_path),
            $this->formatHintForReRun($importRun->source_format),
        );

        $this->icsAccountName = '';
    }

    // Mirrors saveIcsAccountName() with the PayPal synthetic IBAN/kind;
    // see architecture.md#preview-wizard-inline-account-naming for the
    // locked Blade copy this drives.
    public function savePaypalAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        EnsurePaypalAccountAction $ensurePaypal,
    ): void {
        $this->resetErrorBag('paypalAccountName');

        try {
            [$trimmed, $slugBody] = AccountNamer::validateName($this->paypalAccountName);
        } catch (InvalidAccountNameException $e) {
            $this->addError('paypalAccountName', $e->getMessage());

            return;
        }

        $this->paypalAccountName = $trimmed;

        $user = $currentUser->user();

        ($ensurePaypal)($user, nameOverride: $trimmed, slugBodyOverride: $slugBody);

        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $importer->runFromUpload(
            $importRun->raw_file_path,
            $importRun->source_format,
            $user,
            basename($importRun->raw_file_path),
            $this->formatHintForReRun($importRun->source_format),
        );

        $this->paypalAccountName = '';
    }

    // The pipeline refuses a hint-less CSV import at the public-contract
    // boundary; the ImportRun's source_format is the only signal a
    // re-run has, since the user already declared the dialect on the
    // original upload. Every other format needs no hint.
    private function formatHintForReRun(string $sourceFormat): ?BankCsvFormatHint
    {
        return match ($sourceFormat) {
            SourceFormat::AsnCsv->value => BankCsvFormatHint::Asn,
            SourceFormat::IngCsv->value => BankCsvFormatHint::Ing,
            default => null,
        };
    }

    public function confirm(
        ConfirmsImports $confirmer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
        PreviewCache $cache,
        DatabaseManager $db,
    ): void {
        // Defense-in-depth: the Confirm button is disabled client-side
        // via `@disabled` while accounts still need naming, but that
        // DOM guard can be bypassed via devtools — refuse server-side
        // too when any naming precondition is still unmet.
        if ($this->needsIcsAccountName($currentUser, $db)
            || $this->needsPaypalAccountName($currentUser, $db)) {
            return;
        }
        $preview = $cache->getPreview($this->importRunId);
        if ($preview !== null && count($preview->accountsToName) > 0) {
            return;
        }

        try {
            ($confirmer)($this->importRunId, $currentUser->user());
        } catch (PreviewExpiredException) {
            $this->previewExpired = true;

            return;
        }

        $this->redirect(
            $urls->route('imports.results', ['id' => $this->importRunId]),
            navigate: false,
        );
    }

    public function discard(
        DiscardImport $discarder,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        ($discarder)($this->importRunId, $currentUser->user());

        $this->redirect($urls->route('imports.new'), navigate: false);
    }

    public function render(
        ViewFactory $views,
        PreviewCache $cache,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): View {
        $preview = $cache->getPreview($this->importRunId);
        $needsIcsAccountName = $this->needsIcsAccountName($currentUser, $db);
        $needsPaypalAccountName = $this->needsPaypalAccountName($currentUser, $db);

        return $views->make('import::livewire.preview-wizard', [
            'preview' => $preview,
            'previewExpired' => $this->previewExpired,
            'needsIcsAccountName' => $needsIcsAccountName,
            'needsPaypalAccountName' => $needsPaypalAccountName,
        ]);
    }

    // Anchors on source_format (not the unknown-IBAN list) so a future
    // synthetic-IBAN drift still triggers the prompt. Raw query builder
    // keeps phpstan-strict-rules' staticMethod.dynamicCall quiet, same
    // convention as the dashboard queries under Ledger/Public/Services.
    private function needsIcsAccountName(CurrentUser $currentUser, DatabaseManager $db): bool
    {
        $user = $currentUser->user();

        /** @var ImportRun|null $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->first();

        if ($importRun === null) {
            return false;
        }

        if ($importRun->source_format !== 'ics-pdf') {
            return false;
        }

        $icsAccountCount = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('kind', AccountKind::IcsCard->value)
            ->count();

        return $icsAccountCount === 0;
    }

    // Mirrors needsIcsAccountName() for the PayPal branch (kind='paypal',
    // source_format='paypal-csv').
    private function needsPaypalAccountName(CurrentUser $currentUser, DatabaseManager $db): bool
    {
        $user = $currentUser->user();

        /** @var ImportRun|null $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->first();

        if ($importRun === null) {
            return false;
        }

        if ($importRun->source_format !== 'paypal-csv') {
            return false;
        }

        $paypalAccountCount = $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('kind', AccountKind::Paypal->value)
            ->count();

        return $paypalAccountCount === 0;
    }
}
