<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ledger\Public\Enums\Direction;
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

    private const int CHUNK_SIZE = RowChunk::DEFAULT_SIZE;

    // ClusterKeyComposer::MAX_PART_LENGTH, which caps a composed part and so
    // truncates a 64-character digest to 240 of its 256 bits.
    private const CLUSTER_PART_MAX_LENGTH = 60;

    /** @var array<string, string> table => the plaintext IBAN column on it */
    private const IBAN_SOURCES = [
        'accounts' => 'iban',
        'known_counterparty_ibans' => 'real_iban',
    ];

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
        // rows, and LocallyKeyedRowsProbe::holdsRowsKeyedUnder() asks them directly.
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
        $ibans = $this->signatureIbans($connection, $userId);

        $connection->table('chain_links')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($connection, $userId, $keyHex, $ibans): void {
                $plainKeys = $this->plainKeysFor($connection, $userId, $rows);

                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $rewritten = $this->rewrittenEvidence($row, $userId, $keyHex, $ibans, $plainKeys);
                    if ($rewritten === null) {
                        continue;
                    }

                    $connection->table('chain_links')->where('id', $row->id)->update(['evidence' => $rewritten]);
                }
            }, 'id');
    }

    // Keyed by transaction id, which is the primary key, so two links off one
    // transaction share the entry rather than colliding. A row the user does
    // not own is absent, which reads back as the empty key the per-link
    // lookup's own user_id predicate produced.
    /**
     * @param  iterable<int, stdClass>  $rows
     * @return array<int, string>
     */
    private function plainKeysFor(ConnectionInterface $connection, int $userId, iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[self::toInt($row->from_transaction_id ?? null)] = true;
        }

        $keys = [];
        $transactions = $connection->table('transactions')
            ->whereIn('id', array_keys($ids))
            ->where('user_id', $userId)
            ->get(['id', 'counterparty_normalized']);

        foreach ($transactions as $transaction) {
            /** @var stdClass $transaction */
            $keys[self::toInt($transaction->id ?? null)] = self::toString($transaction->counterparty_normalized ?? null);
        }

        return $keys;
    }

    // The IBAN half is on one arm's evidence and on neither of the others, so
    // it is recovered by trying every IBAN a resolver could have reached: the
    // blob's own first, then the user's accounts and the registered aliases. A
    // hash none of them reproduces belongs to an arm that hashes no IBAN.
    /**
     * @param  list<string>  $ibans
     * @param  array<int, string>  $plainKeys
     */
    private function rewrittenEvidence(stdClass $row, int $userId, string $keyHex, array $ibans, array $plainKeys): ?string
    {
        $evidence = json_decode(self::toString($row->evidence ?? null), true);
        if (! is_array($evidence)) {
            return null;
        }

        $plainKey = $plainKeys[self::toInt($row->from_transaction_id ?? null)] ?? '';

        if ($plainKey === '' || BlindIndexCodec::looksDerived($plainKey)) {
            return null;
        }

        return $this->resignedAgainstMatchingIban($evidence, $plainKey, $ibans, $userId, $keyHex);
    }

    // The derivation sits inside the loop rather than above it: a link whose
    // hash no reachable IBAN reproduces is left alone, and on such a link the
    // key it would have been re-signed with was never needed.
    /**
     * @param  array<array-key, mixed>  $evidence
     * @param  list<string>  $ibans
     */
    private function resignedAgainstMatchingIban(array $evidence, string $plainKey, array $ibans, int $userId, string $keyHex): ?string
    {
        $storedHash = $evidence['signature_hash'] ?? null;
        if (! is_string($storedHash) || $storedHash === '') {
            return null;
        }

        $matched = $evidence['matched_iban'] ?? null;
        if (is_string($matched) && $matched !== '') {
            array_unshift($ibans, $matched);
        }

        foreach ($ibans as $iban) {
            if (! hash_equals($storedHash, self::signatureHash($plainKey, $iban))) {
                continue;
            }

            $derivedKey = $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $plainKey, $userId, $keyHex);
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

    // Two arms hash one of the user's own accounts. The alias arm hashes the
    // matched partner's IBAN, which `accounts` never holds and which it can
    // only have drawn from the registered alias set it matched against.
    /**
     * @return list<string>
     */
    private function signatureIbans(ConnectionInterface $connection, int $userId): array
    {
        $ibans = [''];

        foreach (self::IBAN_SOURCES as $table => $column) {
            foreach ($connection->table($table)->where('user_id', $userId)->pluck($column) as $iban) {
                if (is_string($iban) && $iban !== '') {
                    $ibans[] = $iban;
                }
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

                    $connection->table('recurring_series')
                        ->where('id', $row->id)
                        ->update($this->convertedSeries($row, $userId, $keyHex, $stored));
                }
            }, 'id');
    }

    // `cluster_key` is composed OVER this column, so a row rekeyed without it
    // keeps a slug of the merchant name or the payer IBAN in the indexed half
    // of the row's UNIQUE — and the next detection pass composes the keyed one,
    // misses this row, and inserts the series a second time.
    /**
     * @return array{cluster_counterparty_key: string, cluster_key: string}
     */
    private function convertedSeries(stdClass $row, int $userId, string $keyHex, string $stored): array
    {
        $direction = self::toString($row->direction ?? null);
        $normalizedIban = CounterpartyKey::normalizeIban($stored);
        $isIban = $direction === Direction::Income->value && self::looksLikeIban($normalizedIban);

        $derived = $this->blindIndex->deriveWithKey(
            $isIban ? CounterpartyKey::DOMAIN_IBAN : CounterpartyKey::DOMAIN,
            $isIban ? $normalizedIban : $stored,
            $userId,
            $keyHex,
        );

        return [
            'cluster_counterparty_key' => $derived,
            'cluster_key' => self::clusterKey(
                $direction,
                $derived,
                self::toString($row->latest_currency ?? null),
                self::toString($row->cadence ?? null),
            ),
        ];
    }

    // Only an income series can hold an IBAN here, so an expense row's matching
    // key is never shape-tested and the two cannot be confused. A statement may
    // print the IBAN in groups; the hashed value keeps that spacing, which is
    // why the probe strips it and CounterpartyKey::normalizeIban() does not.
    private static function looksLikeIban(string $normalizedIban): bool
    {
        return preg_match(
            '/^[A-Z]{2}\d{2}[A-Z\d]{8,30}$/',
            CounterpartyKey::compactIban($normalizedIban),
        ) === 1;
    }

    // Mirrors ClusterKeyComposer::compose(). Duplicated rather than shared
    // because a sweep in Ledger reaching into a Recurring detector would be the
    // wrong direction; ClusterKeySurvivesTheSweepTest pins the two together.
    private static function clusterKey(string $direction, string $counterpartyKey, string $currency, string $cadence): string
    {
        return implode('::', [
            self::clusterPart($direction),
            self::clusterPart($counterpartyKey),
            self::clusterPart($currency),
            self::clusterPart($cadence),
        ]);
    }

    private static function clusterPart(string $value): string
    {
        $hyphenated = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
        $trimmed = trim($hyphenated, '-');

        return strlen($trimmed) > self::CLUSTER_PART_MAX_LENGTH
            ? rtrim(substr($trimmed, 0, self::CLUSTER_PART_MAX_LENGTH), '-')
            : $trimmed;
    }
}
