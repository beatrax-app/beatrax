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
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ImportRunStatus;
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

    private const int ROW_PAGE = 100;

    // Locked because a Livewire property is client-mutable between requests:
    // unlocked, a mount()-only ownership check is bypassed by setting this to
    // another user's sequential, guessable run id and re-rendering.
    #[Locked]
    public int $importRunId = 0;

    // How many rows the table draws. A 7 MB statement is 27,777 of them, and
    // drawn whole they were a 46.8 MB document -- more than the phone's webview
    // will accept as one page, on the screen the phone is most used for.
    // Locked, so the window only ever grows through showMoreRows().
    #[Locked]
    public int $visibleRows = self::ROW_PAGE;

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
        PreviewCache $cache,
        AccountSlugResolver $slugs,
        BaseCurrency $baseCurrency,
    ): void {
        $this->resetErrorBag('icsAccountName');

        // The same guard the prompt is drawn behind, on the write side: the
        // form is gone by the next render, and a submit already in flight
        // would otherwise still land the account.
        if ($this->previewReadNothing($cache)) {
            return;
        }

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
        PreviewCache $cache,
        EnsurePaypalAccountAction $ensurePaypal,
    ): void {
        $this->resetErrorBag('paypalAccountName');

        if ($this->previewReadNothing($cache)) {
            return;
        }

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
        if ($this->needsIcsAccountName($currentUser, $cache, $db)
            || $this->needsPaypalAccountName($currentUser, $cache, $db)) {
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
        CsvPresetRegistry $presets,
    ): View {
        $this->assertOwnedRun($currentUser);

        $head = $cache->head($this->importRunId);
        $preview = $head === null
            ? null
            : PreviewCache::resultFrom($head, $cache->rows($this->importRunId, 0, $this->visibleRows));
        $needsIcsAccountName = $this->needsIcsAccountName($currentUser, $cache, $db);
        $needsPaypalAccountName = $this->needsPaypalAccountName($currentUser, $cache, $db);

        return $views->make('import::livewire.preview-wizard', [
            'preview' => $preview,
            'previewExpired' => $this->previewExpired,
            'alreadyImported' => $this->alreadyImported($db),
            'needsIcsAccountName' => $needsIcsAccountName,
            'needsPaypalAccountName' => $needsPaypalAccountName,
            'importableRowCount' => self::importableRowCount($preview),
            'failedRowCount' => self::failedRowCount($preview),
            'presetIssuedIdentifiers' => self::presetIssuedIdentifiers($preview, $presets),
        ]);
    }

    // The window grows rather than paging: a reader scanning a statement for
    // one row loses their place if the rows above it are taken away.
    public function showMoreRows(): void
    {
        $this->visibleRows += self::ROW_PAGE;
    }

    /**
     * @return list<string>
     */
    private static function presetIssuedIdentifiers(?ImportPreviewResult $preview, CsvPresetRegistry $presets): array
    {
        if ($preview === null) {
            return [];
        }

        $issued = [];
        foreach ($preview->accountsToName as $unknown) {
            if ($presets->issuesOwnAccountIdentifier($unknown->iban)) {
                $issued[] = $unknown->iban;
            }
        }

        return $issued;
    }

    // A confirmed run has no preview left, indistinguishable from an expired one
    // until the status is read: RunImport short-circuits a SHA it has already
    // landed, so "expired, re-upload" sends that reader round again. Raw builder
    // rather than ImportRun::query()->exists(), which trips staticMethod.dynamicCall.
    private function alreadyImported(DatabaseManager $db): bool
    {
        return $db->connection()
            ->table('import_runs')
            ->where('id', $this->importRunId)
            ->where('status', ImportRunStatus::Confirmed->value)
            ->exists();
    }

    // A run that read nothing has no account to name: the two synthetic-IBAN
    // prompts fire on source_format alone, so they asked for a durable account
    // on the strength of a file the reader is about to be told could not be
    // read at all. Rows that DID read still need theirs, hence the row count.
    private function previewReadNothing(PreviewCache $cache): bool
    {
        $preview = $cache->getPreview($this->importRunId);

        return $preview !== null
            && $preview->fileFailureReason !== null
            && self::importableRowCount($preview) === 0;
    }

    // Anchored on source_format rather than the unknown-IBAN list, so drift in
    // the synthetic IBAN literal still raises the prompt.
    // The card and the wallet ask the same question of the same three things,
    // and differ only in which format raises it and which own-IBAN literal
    // answers it.
    private function needsIcsAccountName(CurrentUser $currentUser, PreviewCache $cache, DatabaseManager $db): bool
    {
        return $this->needsOwnAccountNamed(SourceFormat::IcsPdf, self::ICS_OWN_IBAN, $currentUser, $cache, $db);
    }

    private function needsPaypalAccountName(CurrentUser $currentUser, PreviewCache $cache, DatabaseManager $db): bool
    {
        return $this->needsOwnAccountNamed(
            SourceFormat::PaypalCsv,
            EnsurePaypalAccountAction::PAYPAL_OWN_IBAN,
            $currentUser,
            $cache,
            $db,
        );
    }

    // Whether THIS literal is claimed, not whether any account of the kind
    // exists: an account on some other IBAN suppressed the prompt, and the
    // generic namer then had to validate ICS-CARD or PAYPAL as a real IBAN,
    // which neither can ever be. Drift in the literal still raises the prompt.
    private function needsOwnAccountNamed(
        SourceFormat $format,
        string $ownIban,
        CurrentUser $currentUser,
        PreviewCache $cache,
        DatabaseManager $db,
    ): bool {
        $user = $currentUser->user();

        /** @var ImportRun|null $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->first();

        $raisesThePrompt = $importRun !== null
            && $importRun->source_format === $format->value
            && ! $this->previewReadNothing($cache);

        return $raisesThePrompt && ! $db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $ownIban)
            ->exists();
    }

    // What confirming would actually write. A row that failed is not one of
    // them, and neither is the file-level failure, which is not a row at all.
    private static function importableRowCount(?ImportPreviewResult $preview): int
    {
        return $preview?->importableRows() ?? 0;
    }

    private static function failedRowCount(?ImportPreviewResult $preview): int
    {
        return $preview?->errorRows() ?? 0;
    }
}
