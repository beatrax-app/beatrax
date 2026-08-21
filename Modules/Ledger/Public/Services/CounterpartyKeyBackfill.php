<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Sync\Public\Services\BlindIndexCodec;
use stdClass;

// Converts the counterparty matching keys a user already has from plaintext to
// the keyed digest. Chunked throughout, because this runs inside the
// enable-time transaction and a whole-table load would hold one SQLite writer
// lock across a ledger the size of a life.

// It rewrites `fingerprint` in the same statement as `counterparty_normalized`:
// the fingerprint is composed OVER that column, so a row converted without it
// would not match its own re-import.
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

        // FIRST, while the transactions it reads still hold plaintext keys:
        // the substitution below needs the value it is replacing.
        $this->convertChainLinkSignatures($connection, $userId, $keyHex);

        $this->convertTransactions($connection, $userId, $keyHex);
        $this->convertMerchants($connection, $userId, $keyHex);
        $this->convertSeriesClusterKeys($connection, $userId, $keyHex);

        // Unconditional: this records that the sweep ran, not that it found
        // anything. Whether the device holds keyed rows is a question about the
        // rows, and BlindIndexCodec::holdsDerivedRows() asks them directly.
        $this->blindIndex->markCounterpartyKeysSwept($userId);
    }

    private function convertTransactions(ConnectionInterface $connection, int $userId, string $keyHex): void
    {
        $connection->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_normalized', '<>', CounterpartyKey::NONE)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $stored = self::toString($row->counterparty_normalized ?? null);
                    if ($stored === '' || BlindIndexCodec::looksDerived($stored)) {
                        continue;
                    }

                    $connection->table('transactions')
                        ->where('id', $row->id)
                        ->update($this->convertedTransaction($row, $userId, $keyHex, $stored));
                }
            }, 'id');
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

    // `chain_links.evidence->signature_hash` is sha256(matching key|funding
    // IBAN), and the auto-promotion counter matches confirmed links on it. Left
    // alone, every link confirmed before encryption stops matching what the
    // resolver computes afterwards and the three-link counter silently resets.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function convertChainLinkSignatures(ConnectionInterface $connection, int $userId, string $keyHex): void
    {
        $ibans = $this->userIbans($connection, $userId);

        $connection->table('chain_links')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex, $ibans): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $rewritten = $this->rewrittenEvidence($connection, $row, $userId, $keyHex, $ibans);
                    if ($rewritten === null) {
                        continue;
                    }

                    $connection->table('chain_links')->where('id', $row->id)->update(['evidence' => $rewritten]);
                }
            }, 'id');
    }

    // The IBAN half of the hash is not stored on every arm's evidence, so it is
    // recovered by trying the user's own account IBANs against the stored
    // digest. A hash no IBAN reproduces was not built this way and is left be.
    /**
     * @param  list<string>  $ibans
     */
    private function rewrittenEvidence(ConnectionInterface $connection, stdClass $row, int $userId, string $keyHex, array $ibans): ?string
    {
        $evidence = json_decode(self::toString($row->evidence ?? null), true);
        if (! is_array($evidence)) {
            return null;
        }

        $storedHash = $evidence['signature_hash'] ?? null;
        if (! is_string($storedHash) || $storedHash === '') {
            return null;
        }

        $plainKey = self::toString($connection->table('transactions')
            ->where('id', $row->from_transaction_id)
            ->where('user_id', $userId)
            ->value('counterparty_normalized'));

        if ($plainKey === '' || BlindIndexCodec::looksDerived($plainKey)) {
            return null;
        }

        $derivedKey = $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $plainKey, $userId, $keyHex);

        foreach ($ibans as $iban) {
            if (! hash_equals($storedHash, self::signatureHash($plainKey, $iban))) {
                continue;
            }

            $evidence['signature_hash'] = self::signatureHash($derivedKey, $iban);

            return json_encode($evidence, JSON_THROW_ON_ERROR);
        }

        return null;
    }

    // Mirrors PaypalFundingResolver::signatureHash(). Duplicated rather than
    // shared because a sweep in Ledger reaching into a Chains resolver would
    // be the wrong direction; ChainSignatureParityTest pins the two together.
    private static function signatureHash(string $matchingKey, string $fundingIban): string
    {
        return hash('sha256', $matchingKey.'|'.$fundingIban);
    }

    /**
     * @return list<string>
     */
    private function userIbans(ConnectionInterface $connection, int $userId): array
    {
        $ibans = [''];

        foreach ($connection->table('accounts')->where('user_id', $userId)->pluck('iban') as $iban) {
            if (is_string($iban) && $iban !== '') {
                $ibans[] = $iban;
            }
        }

        return $ibans;
    }

    private function convertMerchants(ConnectionInterface $connection, int $userId, string $keyHex): void
    {
        $connection->table('merchants')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex): void {
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
                }
            }, 'id');
    }

    // Both directions. An expense series stores the counterparty matching key;
    // an income series stores a decrypted IBAN, or falls back to the same
    // matching key when the payer has none — so the domain is chosen per row.
    private function convertSeriesClusterKeys(ConnectionInterface $connection, int $userId, string $keyHex): void
    {
        $connection->table('recurring_series')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $stored = self::toString($row->cluster_counterparty_key ?? null);
                    if ($stored === '' || $stored === CounterpartyKey::NONE || BlindIndexCodec::looksDerived($stored)) {
                        continue;
                    }

                    $domain = self::looksLikeIban($stored) ? CounterpartyKey::DOMAIN_IBAN : CounterpartyKey::DOMAIN;
                    $plain = $domain === CounterpartyKey::DOMAIN_IBAN ? mb_strtoupper($stored) : $stored;

                    $connection->table('recurring_series')
                        ->where('id', $row->id)
                        ->update([
                            'cluster_counterparty_key' => $this->blindIndex->deriveWithKey($domain, $plain, $userId, $keyHex),
                        ]);
                }
            }, 'id');
    }

    // The two things this column can hold are told apart by shape, and they
    // cannot collide: FingerprintComposer::normalize() lower-cases and strips
    // everything but letters, digits, `&` and spaces, so a matching key never
    // carries the upper-case country-and-check-digit head of an IBAN.
    private static function looksLikeIban(string $value): bool
    {
        return preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{8,30}$/', $value) === 1;
    }
}
