<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\EnsurePaypalAccountAction;
use Modules\Ingestion\Public\Enums\SourceFormat;
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
    // The literal IcsPdfAdapter emits. AccountResolver scopes lookups by
    // (iban, user_id), so one instance-wide literal stays unambiguous.
    public const string ICS_OWN_IBAN = 'ICS-CARD';

    // CurrentUser is bound per resolve and arrives per call rather than through
    // the constructor, so a container that hands this out as a singleton cannot
    // hand out a user with it.
    public function __construct(
        private PreviewCache $cache,
        private DatabaseManager $db,
    ) {}

    public function needsIcsAccountName(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->needsOwnAccountNamed($importRunId, $currentUser, SourceFormat::IcsPdf, self::ICS_OWN_IBAN);
    }

    public function needsPaypalAccountName(int $importRunId, CurrentUser $currentUser): bool
    {
        return $this->needsOwnAccountNamed(
            $importRunId,
            $currentUser,
            SourceFormat::PaypalCsv,
            EnsurePaypalAccountAction::PAYPAL_OWN_IBAN,
        );
    }

    // Whether THIS literal is claimed, not whether any account of the kind
    // exists: an account on some other IBAN suppressed the prompt, and the
    // generic namer then had to validate ICS-CARD or PAYPAL as a real IBAN,
    // which neither can ever be. Drift in the literal still raises the prompt.
    private function needsOwnAccountNamed(
        int $importRunId,
        CurrentUser $currentUser,
        SourceFormat $format,
        string $ownIban,
    ): bool {
        $user = $currentUser->user();

        /** @var ImportRun|null $importRun */
        $importRun = ImportRun::query()
            ->where('id', $importRunId)
            ->where('user_id', $user->id)
            ->first();

        $raisesThePrompt = $importRun !== null
            && $importRun->source_format === $format->value
            && ! $this->previewReadNothing($importRunId);

        return $raisesThePrompt && ! $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $ownIban)
            ->exists();
    }

    // A run that read nothing has no account to name: the two synthetic-IBAN
    // prompts fire on source_format alone, so they asked for a durable account
    // on the strength of a file the reader is about to be told could not be
    // read at all. Rows that DID read still need theirs, hence the row count.
    public function previewReadNothing(int $importRunId): bool
    {
        $preview = $this->cache->getPreview($importRunId);

        return $preview !== null
            && $preview->fileFailureReason !== null
            && $preview->importableRows() === 0;
    }
}
