<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Internal\Exceptions\PreviewExpiredException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;

/**
 * @link ../../../../../.docs/features/import/architecture.md#preview-wizard-inline-account-naming
 */
final class PreviewWizard extends Component
{
    // The literal IcsPdfAdapter emits. AccountResolver scopes lookups by
    // (iban, user_id), so one instance-wide literal stays unambiguous.
    private const ICS_OWN_IBAN = 'ICS-CARD';

    // Locked because a Livewire property is client-mutable between requests:
    // unlocked, a mount()-only ownership check is bypassed by setting this to
    // another user's sequential, guessable run id and re-rendering.
    #[Locked]
    public int $importRunId = 0;

    public string $accountName = '';

    public string $icsAccountName = '';

    public string $paypalAccountName = '';

    public bool $previewExpired = false;

    public ?JobRunStatus $chainResolutionStatus = null;

    public int $chainResolutionLinkedCount = 0;

    public function mount(int $id, CurrentUser $currentUser): void
    {
        $this->importRunId = $id;
        $this->assertOwnedRun($currentUser);
    }

    // Re-run on every request that touches the preview. The preview cache key
    // is not user-scoped, so without this a foreign run id would expose
    // another user's in-flight import. Foreign ids get 404, not 403.
    private function assertOwnedRun(CurrentUser $currentUser): void
    {
        ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $currentUser->user()->id)
            ->firstOrFail();
    }

    // Writes into the cache so the next render shows the new name without
    // re-running the pipeline. An out-of-bounds rowIndex no-ops.
    #[On('rename-counterparty:saved')]
    public function applyRenameInPlace(int $rowIndex, string $friendlyName, PreviewCache $cache, CurrentUser $currentUser): void
    {
        if ($this->importRunId <= 0) {
            return;
        }

        $this->assertOwnedRun($currentUser);

        $cache->applyAliasInPlace($this->importRunId, $rowIndex, $friendlyName);
    }

    // Never reintroduce a failed_jobs.payload LIKE '%userId:N%' lookup here:
    // it leaks cross-user state, and WizardChainResolutionStatusTest greps
    // this file to keep it out.
    public function refreshChainResolutionStatus(
        DatabaseManager $db,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        $user = $currentUser->user();

        $row = $db->connection()->table('chain_resolution_runs')
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(1)
            ->first(['status', 'linked_count']);

        if ($row === null) {
            $this->chainResolutionStatus = null;
            $this->chainResolutionLinkedCount = 0;

            return;
        }

        $this->chainResolutionStatus = is_scalar($row->status)
            ? JobRunStatus::tryFrom((string) $row->status)
            : null;
        $this->chainResolutionLinkedCount = is_numeric($row->linked_count) ? (int) $row->linked_count : 0;

        if ($this->chainResolutionStatus === JobRunStatus::Complete && $this->importRunId > 0) {
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

        // AccountNamer is the single authoritative validator; a Livewire
        // rules() declaration here would duplicate it and drift.
        $this->accountName = $name;

        // A crafted wire request naming an arbitrary IBAN is rejected before
        // it reaches the namer, which user-scopes its writes anyway.
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

    public function saveIcsAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        AccountSlugResolver $slugs,
        BaseCurrency $baseCurrency,
    ): void {
        $this->resetErrorBag('icsAccountName');

        try {
            $trimmed = AccountNamer::validateName($this->icsAccountName);
        } catch (InvalidAccountNameException $e) {
            $this->addError('icsAccountName', $e->getMessage());

            return;
        }

        $this->icsAccountName = $trimmed;

        $user = $currentUser->user();

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $slugs->resolveUnique($user->id, $trimmed),
            'kind' => AccountKind::IcsCard->value,
            'iban' => self::ICS_OWN_IBAN,
            'default_currency' => $baseCurrency->code(),
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

    public function savePaypalAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        EnsurePaypalAccountAction $ensurePaypal,
    ): void {
        $this->resetErrorBag('paypalAccountName');

        try {
            $trimmed = AccountNamer::validateName($this->paypalAccountName);
        } catch (InvalidAccountNameException $e) {
            $this->addError('paypalAccountName', $e->getMessage());

            return;
        }

        $this->paypalAccountName = $trimmed;

        $user = $currentUser->user();

        ($ensurePaypal)($user, nameOverride: $trimmed);

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

    // The pipeline refuses a hint-less CSV import, and on a re-run the stored
    // source_format is the only record of the dialect the user declared.
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
        if ($this->confirmationIsBlocked($currentUser, $cache, $db)) {
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

    // The blade's @disabled on Confirm is a DOM guard and devtools defeats it;
    // this is the one that counts. Nothing importable is blocked too, because
    // confirming it wrote a confirmed run whose own summary reported zero of
    // everything. A missing preview is a different case and stays expired.
    private function confirmationIsBlocked(CurrentUser $currentUser, PreviewCache $cache, DatabaseManager $db): bool
    {
        if ($this->needsIcsAccountName($currentUser, $db)
            || $this->needsPaypalAccountName($currentUser, $db)) {
            return true;
        }

        $preview = $cache->getPreview($this->importRunId);
        if ($preview === null) {
            return false;
        }

        return count($preview->accountsToName) > 0 || self::importableRowCount($preview) === 0;
    }

    public function discard(
        DiscardImport $discarder,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
        ($discarder)($this->importRunId, $currentUser->user());

        $this->redirect(Destination::Imports->urlFrom($urls), navigate: false);
    }

    public function render(
        ViewFactory $views,
        PreviewCache $cache,
        CurrentUser $currentUser,
        DatabaseManager $db,
    ): View {
        $this->assertOwnedRun($currentUser);

        $preview = $cache->getPreview($this->importRunId);
        $needsIcsAccountName = $this->needsIcsAccountName($currentUser, $db);
        $needsPaypalAccountName = $this->needsPaypalAccountName($currentUser, $db);

        return $views->make('import::livewire.preview-wizard', [
            'preview' => $preview,
            'previewExpired' => $this->previewExpired,
            'needsIcsAccountName' => $needsIcsAccountName,
            'needsPaypalAccountName' => $needsPaypalAccountName,
            'importableRowCount' => self::importableRowCount($preview),
            'failedRowCount' => self::failedRowCount($preview),
        ]);
    }

    // Anchored on source_format rather than the unknown-IBAN list, so drift in
    // the synthetic IBAN literal still raises the prompt.
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

    // What confirming would actually write. A row that failed is not one of
    // them, and neither is the file-level failure, which is not a row at all.
    private static function importableRowCount(?ImportPreviewResult $preview): int
    {
        if ($preview === null) {
            return 0;
        }

        return count($preview->rows) - self::failedRowCount($preview);
    }

    private static function failedRowCount(?ImportPreviewResult $preview): int
    {
        if ($preview === null) {
            return 0;
        }

        $failed = 0;
        foreach ($preview->rows as $row) {
            if ($row->status === PreviewRowStatus::Error) {
                $failed++;
            }
        }

        return $failed;
    }
}
