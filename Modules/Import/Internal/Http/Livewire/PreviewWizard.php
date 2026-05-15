<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\DiscardImport;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Exceptions\PreviewExpiredException;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

/**
 * Step 2 of the wizard. Renders the preview table (with NEW / DUPLICATE /
 * ENRICHED / ERROR per row), prompts inline for any unfamiliar IBANs or
 * (on the first ICS upload) for a name for the user's ICS card account,
 * and exposes `confirm` / `discard` actions.
 *
 * Lookups always scope to the authenticated user (CurrentUser) so a forged
 * importRunId from another user produces a ModelNotFoundException via
 * `firstOrFail` rather than exposing the other user's import run.
 *
 * Three naming branches share the same Blade section:
 *
 *   - IBAN naming: triggered when an ASN source row references an IBAN
 *     the user hasn't yet linked to an Account. The pipeline surfaces
 *     those IBANs via `$preview->accountsToName`; the Blade iterates
 *     them.
 *   - ICS card naming: triggered when the import run's `source_format`
 *     is `'ics-pdf'` AND no Account with `kind='ics_card'` exists for
 *     the user. Renders one prompt with the UI-SPEC-locked copy; saving
 *     inserts the synthetic ICS Account row and re-runs the importer so
 *     the rows preview is populated.
 *   - PayPal naming: same shape as the ICS branch but keyed on
 *     `source_format = 'paypal-csv'` and `kind = 'paypal'`; saves the
 *     synthetic-IBAN Account with iban `'PAYPAL'`, default currency
 *     EUR, then re-runs the importer.
 */
final class PreviewWizard extends Component
{
    /**
     * Synthetic own-IBAN literal used for every ICS card import. The
     * IcsPdfAdapter emits the same literal, and the AccountResolver
     * scopes lookups by `(iban, user_id)` so a single instance-wide
     * literal is unambiguous regardless of the user.
     */
    private const ICS_OWN_IBAN = 'ICS-CARD';

    /**
     * Synthetic own-IBAN literal used for every PayPal import. Same
     * scoping shape as ICS_OWN_IBAN — the PaypalCsvAdapter emits this
     * literal and the AccountResolver scopes by `(iban, user_id)`.
     */
    private const PAYPAL_OWN_IBAN = 'PAYPAL';

    public int $importRunId = 0;

    public string $accountName = '';

    public string $icsAccountName = '';

    public string $paypalAccountName = '';

    public bool $previewExpired = false;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
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

        // Keep the property in sync with the argument so the error bag
        // surfaces next to the bound input on re-render. Validation
        // itself is delegated to AccountNamer: the service is the single
        // authoritative validator (trim + length bound + slug-body
        // guard), so a Livewire-side rules() declaration would either
        // duplicate that logic or drift from it.
        $this->accountName = $name;

        // Bound the action to the IBANs the wizard actually surfaced as
        // unknown for this preview. A crafted wire request that tries to
        // name an arbitrary IBAN gets rejected before it reaches the
        // namer. The downstream AccountNamer also user-scopes every write
        // — this check is defence-in-depth.
        $preview = $cache->getPreview($this->importRunId);
        $allowedIbans = $preview === null
            ? []
            : array_map(static fn ($unknown): string => $unknown->iban, $preview->accountsToName);
        if (! in_array($iban, $allowedIbans, true)) {
            $this->addError('accountName', 'This IBAN is not part of the current preview.');

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
        );

        $this->accountName = '';
    }

    /**
     * Creates the user's first ICS card Account (synthetic IBAN
     * `'ICS-CARD'`, kind `'ics_card'`, EUR-settled) and re-runs the
     * importer so the rows preview is populated against the newly-named
     * account. Mirrors the IBAN-naming flow's shape but cannot use
     * `NamesAccounts` end-to-end because the synthetic IBAN does not
     * satisfy the structural guard `AccountNamer` enforces for real
     * IBANs. The name-validation half is shared with the IBAN path via
     * `AccountNamer::validateName()` so the 1..80 character bound and
     * the slug-body guard stay in lock step across both flows.
     *
     * Locked Blade copy this action drives (source of truth lives in the
     * preview-wizard.blade.php partial; pinned here so grep on this file
     * surfaces the user-visible text alongside the action):
     *
     *   - Heading:  "Name your ICS card account."
     *   - Helper:   "This is the first time you've imported ICS data.
     *                Give this card a name so it shows up consistently
     *                across the app."
     *   - Input:    "Account name" / placeholder "e.g. ICS card"
     *   - Button:   "Save name"
     */
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
            'kind' => 'ics_card',
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
        );

        $this->icsAccountName = '';
    }

    /**
     * Creates the user's first PayPal Account (synthetic IBAN
     * `'PAYPAL'`, kind `'paypal'`, EUR-settled) and re-runs the
     * importer so the rows preview is populated against the newly-named
     * account. Mirrors `saveIcsAccountName()` step-by-step, swapping
     * the synthetic IBAN + kind + slug-suffix tokens; the
     * name-validation half is shared with the IBAN path via
     * `AccountNamer::validateName()` so the 1..80 character bound and
     * the slug-body guard stay in lock step across all flows.
     *
     * Locked Blade copy this action drives (source of truth lives in
     * the preview-wizard.blade.php partial; pinned here so grep on this
     * file surfaces the user-visible text alongside the action):
     *
     *   - Heading: "Name your PayPal account."
     *   - Helper:  "This is the first time you've imported PayPal data.
     *               Give this wallet a name so it shows up consistently
     *               across the app."
     *   - Input:   "Account name" / placeholder "e.g. PayPal"
     *   - Button:  "Save name"
     */
    public function savePaypalAccountName(
        RunsImports $importer,
        CurrentUser $currentUser,
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

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $slugBody.'-paypal',
            'kind' => 'paypal',
            'iban' => self::PAYPAL_OWN_IBAN,
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
        );

        $this->paypalAccountName = '';
    }

    public function confirm(
        ConfirmsImports $confirmer,
        CurrentUser $currentUser,
        UrlGenerator $urls,
    ): void {
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

    /**
     * Returns true when this preview belongs to an ICS PDF import run
     * AND the current user does not yet own an Account row with
     * `kind='ics_card'`. The wizard renders the locked
     * Name-your-ICS-card-account prompt in place of the rows table when
     * this predicate fires, then re-runs the importer after the row is
     * persisted so the rows preview catches up with the named account.
     *
     * Anchors on `source_format` rather than on the unknown-IBAN list so
     * a future statement variant where the synthetic IBAN drifts (e.g.
     * `'ICS-CARD-PRIMARY'`) still triggers the prompt by virtue of the
     * declared source format.
     *
     * The Account presence check uses the raw query builder (via injected
     * `DatabaseManager`) rather than the Eloquent Builder so the
     * project's phpstan-strict-rules `staticMethod.dynamicCall` rule
     * doesn't flag the call site — same convention as the dashboard
     * queries under `Modules/Ledger/Public/Services/`.
     */
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
            ->where('kind', 'ics_card')
            ->count();

        return $icsAccountCount === 0;
    }

    /**
     * Returns true when this preview belongs to a PayPal CSV import
     * run AND the current user does not yet own an Account row with
     * `kind='paypal'`. The wizard renders the locked Name-your-PayPal-
     * account prompt in place of the rows table when this predicate
     * fires, then re-runs the importer after the row is persisted so
     * the rows preview catches up with the named account.
     *
     * Anchors on `source_format` rather than on the unknown-IBAN list
     * so a future synthetic-IBAN drift (e.g. `'PAYPAL-PRIMARY'`) still
     * triggers the prompt by virtue of the declared source format.
     *
     * The Account presence check uses the raw query builder (via
     * injected `DatabaseManager`) rather than the Eloquent Builder so
     * the project's phpstan-strict-rules `staticMethod.dynamicCall`
     * rule doesn't flag the call site — same convention as
     * `needsIcsAccountName()` above and the dashboard queries under
     * `Modules/Ledger/Public/Services/`.
     */
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
            ->where('kind', 'paypal')
            ->count();

        return $paypalAccountCount === 0;
    }
}
