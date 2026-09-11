<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Listeners;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Events\PeerRowsApplied;
use stdClass;

// The fingerprint is composed OVER seven columns of the row it keys, and one
// announcement is not one op: each column takes its own HLC tick, so a merge
// can seat the digest from one device beside values from the other. The row
// then fails to match its own re-import and the statement lands twice.
/**
 * @link ../../../../.docs/features/sync/architecture.md#one-announcement-is-not-one-op
 */
final readonly class RederiveFingerprintOnMergedRows
{
    use CoercesScalars;

    // Spelled out at both query roots rather than reached through this
    // constant: the writer guards read a table LITERAL, so a constant hides a
    // silent write from the one test that would ask it to justify itself.
    private const string TABLE = 'transactions';

    /** @var list<string> */
    private const array READ = [
        'id', 'user_id', 'account_id', 'posted_at', 'booked_at', 'amount_minor',
        'currency', 'counterparty_normalized', 'occurrence_ordinal',
        'fingerprint', 'fingerprint_version',
    ];

    public function __construct(
        private DatabaseManager $db,
        private FingerprintComposer $composer,
    ) {}

    public function handle(PeerRowsApplied $event): void
    {
        $pks = $event->updated[self::TABLE] ?? [];

        if ($pks === []) {
            return;
        }

        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $event->userId)
            ->whereIn('id', $pks)
            ->get(self::READ);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $this->realign($row, $event->userId);
        }
    }

    // Rows below the current version belong to the sweep that owns the move to
    // it: rewriting one here would migrate it on the quiet, under a stamp that
    // says it was never migrated.
    private function realign(stdClass $row, int $userId): void
    {
        if (self::toInt($row->fingerprint_version ?? null) !== $this->composer->version()) {
            return;
        }

        $composed = $this->composer->composeTuple(new FingerprintTuple(
            userId: $userId,
            accountId: self::toInt($row->account_id ?? null),
            postedAtDate: self::dayOf(self::toString($row->posted_at ?? null)),
            bookedAtDateTime: self::toString($row->booked_at ?? null),
            amountMinor: self::toInt($row->amount_minor ?? null),
            currency: self::toString($row->currency ?? null),
            counterpartyNormalized: self::toString($row->counterparty_normalized ?? null),
            occurrenceOrdinal: self::toInt($row->occurrence_ordinal ?? null),
        ));

        if ($composed === self::toString($row->fingerprint ?? null)) {
            return;
        }

        // No announcement: the value is derived, so every device recomposes the
        // same digest from its own merged row. An op here would send a peer
        // back a column it can compute, and loop.
        $this->db->connection()->table('transactions')
            ->where('id', self::toInt($row->id ?? null))
            ->where('user_id', $userId)
            ->update(['fingerprint' => $composed]);
    }

    private static function dayOf(string $stored): string
    {
        return $stored === '' ? '' : substr($stored, 0, 10);
    }
}
