<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Psr\Log\LoggerInterface;
use stdClass;

// A receipt states the total the reader was quoted and the statement what
// settled, and the two round one purchase at different moments. Only the total
// is widened here: every other term the fingerprint hashes still has to agree
// exactly, and the date window is deliberately none.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#a-total-inside-the-band
 */
final readonly class NearTotalMatch
{
    // Half of Chains' PaypalFundingResolver::AMOUNT_BAND_PERCENT, deliberately:
    // that band proposes a link the reader can reject, this one claims two
    // records are one event. It buys rounding, never a tip — a band wide enough
    // for a tip is wide enough to merge two lunches.
    public const int BAND_PERCENT = 1;

    // One more than one is the whole answer: a second row inside the band makes
    // the receipt ambiguous and a third cannot make it more so.
    private const int CANDIDATE_LIMIT = 2;

    // The row shape FingerprintStage::detectConflicts() reads. Both its lookups
    // select this one list, so the near-total arm cannot hand the stage a row
    // missing a column the exact arm always has.
    /** @var list<string> */
    public const array CONFLICT_COLUMNS = [
        'id',
        'source_ref',
        'source_format',
        'counterparty_name',
        'description',
        'currency',
        'amount_minor',
    ];

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    // A proportion rather than a count of minor units: a floor of five would be
    // five cents in EUR and five whole yen in JPY, which is not the same
    // tolerance at all.
    public static function bandMinorFor(int $amountMinor): int
    {
        return intdiv(abs($amountMinor) * self::BAND_PERCENT, 100);
    }

    // Null where nothing is inside the band AND where two rows are: matching
    // either of two candidates is worse than matching neither, because the one
    // not chosen stays in the ledger holding the other's receipt.
    public function matching(CanonicalTransaction $tx, User $user): ?stdClass
    {
        $band = self::bandMinorFor($tx->amountMinor);

        $candidates = $this->db->connection()
            ->table('transactions')
            // The first three lead transactions_user_counterparty_posted_idx,
            // so the band is applied to a merchant's rows on a day rather than
            // to the reader's whole history.
            ->where('user_id', $user->id)
            ->where('counterparty_normalized', $tx->counterpartyNormalized)
            ->where('posted_at', $tx->postedAt->toDateString())
            ->where('account_id', $tx->accountId)
            ->where('booked_at', $tx->bookedAt->toDateTimeString())
            ->where('occurrence_ordinal', $tx->occurrenceOrdinal)
            // Case-normalised, which is what comparing a currency means in
            // this app; the fingerprint hashes the code as spelled, so 'eur'
            // and 'EUR' are two digests for one currency.
            ->whereRaw('upper(currency) = ?', [mb_strtoupper($tx->currency)])
            // Signed bounds, so the band never straddles zero: it is a fraction
            // of the figure itself, and a debit cannot reach a credit.
            ->whereBetween('amount_minor', [$tx->amountMinor - $band, $tx->amountMinor + $band])
            ->limit(self::CANDIDATE_LIMIT)
            ->get(self::CONFLICT_COLUMNS);

        if ($candidates->count() > 1) {
            $this->logger->info('Receipt total sits inside the band of more than one transaction; matching none', [
                'account_id' => $tx->accountId,
                'posted_at' => $tx->postedAt->toDateString(),
                'band_minor' => $band,
                'source_format' => $tx->sourceFormat,
            ]);
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }
}
