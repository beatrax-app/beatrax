<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsureGooglePlayAccountAction;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Models\ImportRun;

// A card statement and a wallet export carry no IBAN of the reader's own, so
// the adapter emits a synthetic one and the wizard has to ask who it belongs
// to. Whether that question is still open is a fact about the run, not about
// the screen asking it.
/**
 * @link ../../../../.docs/features/import/architecture.md#preview-wizard-inline-account-naming
 */
final readonly class OwnAccountPrompt
{
    // AccountResolver scopes lookups by (iban, user_id), so one instance-wide
    // sentinel stays unambiguous.
    public const string ICS_OWN_IBAN = SyntheticIban::IcsCard->value;

    // CurrentUser is bound per resolve and arrives per call rather than through
    // the constructor, so a container that hands this out as a singleton cannot
    // hand out a user with it.
    public function __construct(
        private PreviewCache $cache,
        private DatabaseManager $db,
    ) {}

    public function needsIcsAccountName(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->needsOwnAccountNamed($importRunId, $currentUser, self::ICS_OWN_IBAN, SourceFormat::IcsPdf);
    }

    public function needsPaypalAccountName(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->needsOwnAccountNamed(
            $importRunId,
            $currentUser,
            EnsurePaypalAccountAction::PAYPAL_OWN_IBAN,
            SourceFormat::PaypalCsv,
        );
    }

    // Google Play publishes no statement export, so no source_format can ever
    // declare it: its receipts arrive only over transports it shares with the
    // other two, and the preview is its single witness.
    public function needsGooglePlayAccountName(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->needsOwnAccountNamed(
            $importRunId,
            $currentUser,
            EnsureGooglePlayAccountAction::GOOGLE_PLAY_OWN_IBAN,
        );
    }

    // What the parsed file says this synthetic account is denominated in. An
    // ICS card bills in euro and a wallet export names its own currency, so
    // the reader's reporting currency is not the answer to either.
    /**
     * @link ../../../../.docs/features/import/an-account-is-denominated-by-its-statement.md
     */
    public function statementCurrency(int $importRunId, CurrentUser $currentUser, string $ownIban): ?string
    {
        return $this->previewEntryFor($importRunId, $currentUser, $ownIban)?->statementCurrency;
    }

    // True on another reader's run as well as on a file that read nothing, and
    // for the same reason: a prompt raised by the run's format alone asked for
    // a durable account on the strength of a file the reader is about to be
    // told could not be read -- or was never theirs.
    public function hasNothingToName(int $importRunId, CurrentUser $currentUser): bool
    {
        return ! $this->ownsRun($importRunId, $currentUser)
            || $this->previewReadNothing($importRunId);
    }

    // Two witnesses, and the prompt closes only once THIS literal is claimed.
    // A statement export declares its provider in source_format, so drift in
    // the literal still raises the prompt; a receipt drop declares only the
    // transport it shares, so there the unknown-IBAN list is the only witness.
    private function needsOwnAccountNamed(
        int $importRunId,
        CurrentUser $currentUser,
        string $ownIban,
        ?SourceFormat $format = null,
    ): bool {
        $importRun = $this->ownedRun($importRunId, $currentUser);

        $declaredByFormat = $importRun !== null
            && $format !== null
            && $importRun->source_format === $format->value;

        $raisesThePrompt = ($declaredByFormat || $this->previewAsksToName($importRunId, $currentUser, $ownIban))
            && ! $this->previewReadNothing($importRunId);

        return $raisesThePrompt && ! $this->ownAccountExists($currentUser, $ownIban);
    }

    private function ownAccountExists(CurrentUser $currentUser, string $ownIban): bool
    {
        return $this->db->connection()
            ->table('accounts')
            ->where('user_id', $currentUser->user()->id)
            ->where('iban', $ownIban)
            ->exists();
    }

    private function previewAsksToName(int $importRunId, CurrentUser $currentUser, string $ownIban): bool
    {
        return $this->previewEntryFor($importRunId, $currentUser, $ownIban) instanceof UnknownIban;
    }

    private function previewEntryFor(int $importRunId, CurrentUser $currentUser, string $ownIban): ?UnknownIban
    {
        // A run that is not the caller's is read as a run with no preview: the
        // two are one refusal, and neither may say which it was.
        $preview = $this->ownsRun($importRunId, $currentUser)
            ? $this->cache->getPreview($importRunId)
            : null;

        if ($preview === null) {
            return null;
        }

        foreach ($preview->accountsToName as $unknown) {
            if ($unknown->iban === $ownIban) {
                return $unknown;
            }
        }

        return null;
    }

    // Private, and reached only once the run is known to be the caller's: the
    // preview cache key is not user-scoped, so a run id off the wire is
    // otherwise the whole of the access control on an in-flight import.
    private function previewReadNothing(int $importRunId): bool
    {
        $preview = $this->cache->getPreview($importRunId);

        return $preview !== null
            && $preview->fileFailureReason !== null
            && $preview->importableRows() === 0;
    }

    private function ownsRun(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->ownedRun($importRunId, $currentUser) instanceof ImportRun;
    }

    private function ownedRun(int $importRunId, CurrentUser $currentUser): ?ImportRun
    {
        /** @var ImportRun|null $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $currentUser->user()->id)
            ->first();

        return $importRun;
    }
}
