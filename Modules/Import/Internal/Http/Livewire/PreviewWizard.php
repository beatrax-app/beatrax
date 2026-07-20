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
 *     the user. Renders one naming prompt; saving inserts the synthetic
 *     ICS Account row and re-runs the importer so the rows preview is
 *     populated.
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

    public int $importRunId = 0;

    public string $accountName = '';

    public string $icsAccountName = '';

    public string $paypalAccountName = '';

    public bool $previewExpired = false;

    /**
     * Chain-resolution polling state. The wizard's
     * `wire:poll.2s="refreshChainResolutionStatus"` populates these
     * properties from the `chain_resolution_runs` audit table —
     * filtered by EXACT `user_id` match.
     *
     * Issue #1 + #8 lock: NEVER read `failed_jobs.payload` with a
     * substring `LIKE '%userId:N%'` pattern — user_id=1 would
     * falsely match user_id=11, leaking cross-user state. The
     * audit-table read uses the column directly, eliminating the
     * substring-attack surface entirely.
     *
     * State machine: null (pre-mount) → 'pending' (job queued) →
     * 'running' (worker handle() entered) → 'complete' (success;
     * auto-navigates to imports.results) / 'failed' (final retry
     * exhausted; user dismisses or opens Horizon).
     */
    public ?string $chainResolutionStatus = null;

    public int $chainResolutionLinkedCount = 0;

    public ?string $chainResolutionError = null;

    public function mount(int $id): void
    {
        $this->importRunId = $id;
    }

    /**
     * Listens for the rename popover's saved event and updates the
     * affected preview row's aliasFriendlyName inside the cache so
     * the next render shows the new name without re-running the
     * import pipeline. Out-of-bounds rowIndex silently no-ops via
     * PreviewCache::applyAliasInPlace returning false — a stale
     * dispatch from a previous wizard render never throws.
     */
    #[On('rename-counterparty:saved')]
    public function applyRenameInPlace(int $rowIndex, string $friendlyName, PreviewCache $cache): void
    {
        if ($this->importRunId <= 0) {
            return;
        }

        $cache->applyAliasInPlace($this->importRunId, $rowIndex, $friendlyName);
    }

    /**
     * `wire:poll.2s` target. Reads the latest `chain_resolution_runs`
     * row for the current user (ORDER BY id DESC LIMIT 1) and surfaces
     * its status / linked_count / last_error to the wizard view.
     *
     * Issue #1 + #8 fix: this method is the canonical safe path.
     * The forbidden substring shape (`failed_jobs.payload LIKE
     * '%userId:N%'`) is documented in this method's docblock so a
     * future refactor that re-introduces it trips the grep gate in
     * WizardChainResolutionStatusTest.
     *
     * On status === 'complete', the method redirects the user to
     * `imports.results` so the wizard auto-advances when the resolver
     * is done. The Livewire `redirect()` call halts further property
     * updates in the same tick.
     */
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
            $this->formatHintForReRun($importRun->source_format),
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
            $this->formatHintForReRun($importRun->source_format),
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

    /**
     * Resolves the bank-format hint required when re-running the
     * importer from inside a name-your-account flow. The pipeline
     * refuses to dispatch a CSV import without a hint at the
     * public-contract boundary, and the ImportRun row's
     * `source_format` is the only signal the re-run path has — the
     * user already declared the dialect when the original upload
     * landed. CAMT.053, MT940, PDF, PayPal CSV, and email files do
     * not need a hint.
     */
    private function formatHintForReRun(string $sourceFormat): ?BankCsvFormatHint
    {
        return match ($sourceFormat) {
            'asn-csv' => BankCsvFormatHint::Asn,
            'ing-csv' => BankCsvFormatHint::Ing,
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
