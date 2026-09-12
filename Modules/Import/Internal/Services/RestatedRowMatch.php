<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use stdClass;

// A bank that books a card payment at the terminal's price and restates it at
// the price it settled for sends both rows under one reference. The amount is
// hashed into the fingerprint and the reference is not, so the restating row
// hashed to nothing stored and landed beside the row it was restating.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#a-reference-the-ledger-already-holds
 */
final readonly class RestatedRowMatch
{
    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    // Null where the reference names nothing and where it names more than one
    // row. A stored row stating the same total is not a candidate either: the
    // exact lookup missed it over some other term, and no amount was restated.
    public function matching(CanonicalTransaction $tx, User $user): ?stdClass
    {
        $reference = $tx->sourceRef;

        if ($reference === null || $reference === '') {
            return null;
        }

        $candidates = $this->db->connection()
            ->table('transactions')
            // Filtered by user explicitly rather than through the model's
            // global scope, which falls through to no scope at all in a queue,
            // a console run or a test.
            ->where('user_id', $user->id)
            ->where('account_id', $tx->accountId)
            // Leads transactions_account_source_ref_idx, so the reference is
            // matched against one account's rows rather than by a walk over
            // the reader's whole history once per imported row.
            ->where('source_ref', $reference)
            // Every other term the fingerprint hashes still has to be equal,
            // for the reason the receipt band widens no date either: widening
            // the total and the day at once multiplies what a wrong match can
            // reach, and the restated row keeps the day the bank filed it on.
            ->where('posted_at', $tx->postedAt->toDateString())
            ->where('booked_at', $tx->bookedAt->toDateTimeString())
            ->where('counterparty_normalized', $tx->counterpartyNormalized)
            ->where('occurrence_ordinal', $tx->occurrenceOrdinal)
            // Case-normalised, which is what comparing a currency means in this
            // app; the digest hashes the code as spelled, so 'eur' and 'EUR'
            // are two digests for one currency.
            ->whereRaw('upper(currency) = ?', [mb_strtoupper($tx->currency)])
            ->where('amount_minor', '!=', $tx->amountMinor)
            // A second stored row under one reference makes that reference a
            // constant the bank fills in rather than a name for either row,
            // and the bound is the one the near-total lookup already answers
            // ambiguity by.
            ->limit(NearTotalMatch::CANDIDATE_LIMIT)
            ->get(NearTotalMatch::CONFLICT_COLUMNS);

        if ($candidates->count() > 1) {
            $this->logger->info('More than one stored row carries this source reference; restating none', [
                'account_id' => $tx->accountId,
                'posted_at' => $tx->postedAt->toDateString(),
                'source_format' => $tx->sourceFormat,
            ]);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
