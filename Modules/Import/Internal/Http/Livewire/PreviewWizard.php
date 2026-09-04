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
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Internal\Exceptions\PreviewCacheCorruptedException;
use Modules\Import\Internal\Exceptions\PreviewExpiredException;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Services\OwnAccountPrompt;
use Modules\Import\Internal\Services\RemoteFetchPath;
use Modules\Import\Internal\Services\StandInAccountName;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Import\Public\Services\AccountDenomination;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\ImportRunStatus;
use Modules\Ledger\Public\Services\AccountSlugResolver;

/**
 * @link ../../../../../.docs/features/import/architecture.md#preview-wizard-inline-account-naming
 */
final class PreviewWizard extends Component
{
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

    public string $googlePlayAccountName = '';

    public bool $previewExpired = false;

    // Separate from previewExpired because the two are different answers: an
    // evicted entry is gone and a malformed one is present and will not
    // decode, and telling the reader it expired names a cause that is not it.
    public bool $previewUnreadable = false;

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
        // it reaches the namer, which user-scopes its writes anyway. The
        // matched entry also carries the denomination, so that comes off the
        // parsed file rather than off the wire.
        $named = self::previewEntryFor($cache->getPreview($this->importRunId), $iban);
        if (! $named instanceof UnknownIban) {
            $this->addError('accountName', Lang::get('import::preview.errors.iban_not_in_preview'));

            return;
        }

        $user = $currentUser->user();

        try {
            ($namer)($iban, $name, $user, $named->statementCurrency);
        } catch (InvalidAccountNameException $e) {
            $this->addError('accountName', $e->getMessage());

            return;
        }

        $this->reReadTheSource($importer, $user);

        $this->accountName = '';
    }

    public function saveIcsAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        OwnAccountPrompt $prompt,
        AccountSlugResolver $slugs,
        AccountDenomination $denomination,
    ): void {
        $this->resetErrorBag('icsAccountName');

        // The same guard the prompt is drawn behind, on the write side: the
        // form is gone by the next render, and a submit already in flight
        // would otherwise still land the account.
        if ($prompt->hasNothingToName($this->importRunId, $currentUser)) {
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
            'iban' => OwnAccountPrompt::ICS_OWN_IBAN,
            'default_currency' => $denomination->forStatement(
                $prompt->statementCurrency($this->importRunId, $currentUser, OwnAccountPrompt::ICS_OWN_IBAN),
            ),
        ]);

        $this->reReadTheSource($importer, $user);

        $this->icsAccountName = '';
    }

    public function savePaypalAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        OwnAccountPrompt $prompt,
        EnsurePaypalAccountAction $ensurePaypal,
    ): void {
        $this->resetErrorBag('paypalAccountName');

        if ($prompt->hasNothingToName($this->importRunId, $currentUser)) {
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

        ($ensurePaypal)(
            $user,
            nameOverride: $trimmed,
            statementCurrency: $prompt->statementCurrency($this->importRunId, $currentUser, EnsurePaypalAccountAction::PAYPAL_OWN_IBAN),
        );

        $this->reReadTheSource($importer, $user);

        $this->paypalAccountName = '';
    }

    public function saveGooglePlayAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
        OwnAccountPrompt $prompt,
        EnsureGooglePlayAccountAction $ensureGooglePlay,
    ): void {
        $this->resetErrorBag('googlePlayAccountName');

        if ($prompt->hasNothingToName($this->importRunId, $currentUser)) {
            return;
        }

        try {
            $trimmed = AccountNamer::validateName($this->googlePlayAccountName);
        } catch (InvalidAccountNameException $e) {
            $this->addError('googlePlayAccountName', $e->getMessage());

            return;
        }

        $this->googlePlayAccountName = $trimmed;

        $user = $currentUser->user();

        ($ensureGooglePlay)(
            $user,
            nameOverride: $trimmed,
            statementCurrency: $prompt->statementCurrency($this->importRunId, $currentUser, EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN),
        );

        $this->reReadTheSource($importer, $user);

        $this->googlePlayAccountName = '';
    }

    // A bank-fetched window has no file to re-read, and the named account is
    // already written: the still-open window picks it up on the next sync.
    private function reReadTheSource(RunsImports $importer, User $user): void
    {
        /** @var ImportRun $importRun */
        $importRun = ImportRun::query()
            ->where('id', $this->importRunId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (RemoteFetchPath::isRemote($importRun->raw_file_path)) {
            return;
        }

        $importer->runFromUpload(
            $importRun->raw_file_path,
            $importRun->source_format,
            $user,
            basename($importRun->raw_file_path),
            $this->formatHintForReRun($importRun->source_format),
        );
    }

    // The pipeline refuses a hint-less CSV import, and on a re-run the stored
    // source_format is the only record of the dialect the user declared.
    private function formatHintForReRun(string $sourceFormat): ?BankCsvFormatHint
    {
        return match ($sourceFormat) {
            CsvPresetRegistry::ASN => BankCsvFormatHint::Asn,
            default => null,
        };
    }

    public function confirm(
        ConfirmsImports $confirmer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
        PreviewCache $cache,
        OwnAccountPrompt $prompt,
    ): void {
        if ($this->confirmationIsBlocked($currentUser, $cache, $prompt)) {
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

    // Returning here keeps the reader on the wizard with the prompt they still
    // have to answer, rather than on ConfirmImport's exception, which enforces
    // the same rule for every caller. A missing preview is a different case
    // and stays expired.
    private function confirmationIsBlocked(CurrentUser $currentUser, PreviewCache $cache, OwnAccountPrompt $prompt): bool
    {
        try {
            if ($prompt->needsIcsAccountName($this->importRunId, $currentUser)
                || $prompt->needsPaypalAccountName($this->importRunId, $currentUser)
                || $prompt->needsGooglePlayAccountName($this->importRunId, $currentUser)) {
                return true;
            }

            return $cache->head($this->importRunId)?->confirmRefusal() !== null;
        } catch (PreviewCacheCorruptedException) {
            // Nothing to confirm out of an entry that will not decode, and the
            // reader stays on the wizard where the line above says so.
            $this->previewUnreadable = true;

            return true;
        }
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
        StandInAccountName $standInNames,
        OwnAccountPrompt $prompt,
    ): View {
        $this->assertOwnedRun($currentUser);

        // One try around every read that reaches the preview cache, not just
        // the head: the row page and all three own-account prompts open the
        // same entry, so a malformed one stops each of them in turn.
        try {
            $head = $cache->head($this->importRunId);
            $preview = $head === null
                ? null
                : PreviewCache::resultFrom($head, $cache->rows($this->importRunId, 0, $this->visibleRows));
            $needsIcsAccountName = $prompt->needsIcsAccountName($this->importRunId, $currentUser);
            $needsPaypalAccountName = $prompt->needsPaypalAccountName($this->importRunId, $currentUser);
            $needsGooglePlayAccountName = $prompt->needsGooglePlayAccountName($this->importRunId, $currentUser);
        } catch (PreviewCacheCorruptedException) {
            $this->previewUnreadable = true;
            $preview = null;
            $needsIcsAccountName = false;
            $needsPaypalAccountName = false;
            $needsGooglePlayAccountName = false;
        }

        return $views->make('import::livewire.preview-wizard', [
            'preview' => $preview,
            'previewExpired' => $this->previewExpired,
            'previewUnreadable' => $this->previewUnreadable,
            'alreadyImported' => $this->alreadyImported($db),
            'needsIcsAccountName' => $needsIcsAccountName,
            'needsPaypalAccountName' => $needsPaypalAccountName,
            'needsGooglePlayAccountName' => $needsGooglePlayAccountName,
            'importableRowCount' => self::importableRowCount($preview),
            'failedRowCount' => self::failedRowCount($preview),
            'standInAccountNames' => self::standInAccountNames($preview, $standInNames),
        ]);
    }

    // The window grows rather than paging: a reader scanning a statement for
    // one row loses their place if the rows above it are taken away.
    public function showMoreRows(): void
    {
        $this->visibleRows += self::ROW_PAGE;
    }

    private static function previewEntryFor(?ImportPreviewResult $preview, string $iban): ?UnknownIban
    {
        foreach ($preview->accountsToName ?? [] as $unknown) {
            if ($unknown->iban === $iban) {
                return $unknown;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function standInAccountNames(?ImportPreviewResult $preview, StandInAccountName $standInNames): array
    {
        $named = [];
        foreach ($preview->accountsToName ?? [] as $unknown) {
            $name = $standInNames->for($unknown->iban);
            if ($name !== null) {
                $named[$unknown->iban] = $name;
            }
        }

        return $named;
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
