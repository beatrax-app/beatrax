<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Sync\Public\Services\BlindIndexCodec;
use stdClass;

// Converts the counterparty matching keys a user already has from plaintext to
// the keyed digest. It rewrites `fingerprint` in the same statement as
// `counterparty_normalized`: the fingerprint is composed OVER that column, so
// a row converted without it would no longer match its own re-import.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final class CounterpartyKeyBackfill
{
    use CoercesScalars;

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BlindIndexCodec $blindIndex,
        private readonly FingerprintComposer $fingerprints,
    ) {}

    // Caller supplies the key rather than the session: this runs inside the
    // enable-time transaction, whose keyring file is staged and not yet
    // readable through the ordinary accessor.
    public function run(int $userId, string $keyHex): void
    {
        $connection = $this->db->connection();

        $converted = $this->convertTransactions($connection, $userId, $keyHex);
        $converted += $this->convertMerchants($connection, $userId, $keyHex);
        $converted += $this->convertExpenseSeries($connection, $userId, $keyHex);

        // Only when something was actually keyed. A joining device sweeps an
        // empty database, and marking that would make it refuse the group's
        // blind-index key in favour of the one it minted for itself.
        if ($converted > 0) {
            $this->blindIndex->markDerived($userId);
        }
    }

    private function convertTransactions(ConnectionInterface $connection, int $userId, string $keyHex): int
    {
        $converted = 0;

        $connection->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_normalized', '<>', CounterpartyKey::NONE)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex, &$converted): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $stored = self::toString($row->counterparty_normalized ?? null);
                    if ($stored === '' || BlindIndexCodec::looksDerived($stored)) {
                        continue;
                    }

                    $connection->table('transactions')
                        ->where('id', $row->id)
                        ->update($this->convertedTransaction($row, $userId, $keyHex, $stored));
                    $converted++;
                }
            }, 'id');

        return $converted;
    }

    // The date columns go back through CarbonImmutable before they reach the
    // tuple, so the fingerprint written here is byte-identical to the one a
    // re-import of the same statement composes.
    /**
     * @return array{counterparty_normalized: string, fingerprint: string}
     */
    private function convertedTransaction(stdClass $row, int $userId, string $keyHex, string $stored): array
    {
        $derived = $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $stored, $userId, $keyHex);

        return [
            'counterparty_normalized' => $derived,
            'fingerprint' => $this->fingerprints->composeTuple(
                $userId,
                self::toInt($row->account_id ?? null),
                CarbonImmutable::parse(self::toString($row->posted_at ?? null))->toDateString(),
                CarbonImmutable::parse(self::toString($row->booked_at ?? null))->toDateTimeString(),
                self::toInt($row->amount_minor ?? null),
                self::toString($row->currency ?? null),
                $derived,
            ),
        ];
    }

    private function convertMerchants(ConnectionInterface $connection, int $userId, string $keyHex): int
    {
        $converted = 0;

        $rows = $connection->table('merchants')
            ->where('user_id', $userId)
            ->get(['id', 'normalized_name']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $stored = self::toString($row->normalized_name ?? null);
            if ($stored === '' || $stored === CounterpartyKey::NONE || BlindIndexCodec::looksDerived($stored)) {
                continue;
            }

            $connection->table('merchants')
                ->where('id', $row->id)
                ->update([
                    'normalized_name' => $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $stored, $userId, $keyHex),
                ]);
            $converted++;
        }

        return $converted;
    }

    // Expense rows only. An income series stores a decrypted IBAN in this
    // column, which the detector recomputes from the transaction each sweep
    // and never keys; converting it would leave the lookup matching nothing.
    private function convertExpenseSeries(ConnectionInterface $connection, int $userId, string $keyHex): int
    {
        $converted = 0;

        $rows = $connection->table('recurring_series')
            ->where('user_id', $userId)
            ->where('direction', Direction::Expense->value)
            ->get(['id', 'cluster_counterparty_key']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $stored = self::toString($row->cluster_counterparty_key ?? null);
            if ($stored === '' || $stored === CounterpartyKey::NONE || BlindIndexCodec::looksDerived($stored)) {
                continue;
            }

            $connection->table('recurring_series')
                ->where('id', $row->id)
                ->update([
                    'cluster_counterparty_key' => $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $stored, $userId, $keyHex),
                ]);
            $converted++;
        }

        return $converted;
    }
}
