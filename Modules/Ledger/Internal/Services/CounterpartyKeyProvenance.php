<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\CounterpartyKey;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Contracts\BlindIndexProvenance;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Proof of authorship for a blind-index digest: it re-derives the digest from
// the row's OWN plaintext under the supplied key. A peer's replayed rows carry
// digests this device cannot reproduce, which is what a shape test — "is there
// a 64-hex value in this column" — can never tell apart.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class CounterpartyKeyProvenance implements BlindIndexProvenance
{
    use CoercesScalars;

    // Oldest-first, because a device's OWN rows predate anything a peer
    // replayed into it: catch-up appends, so the local ledger sits at the low
    // end of the id order and a window taken from the newest rows would see
    // only the peer's.
    private const int PROBE_ROWS = 25;

    public function __construct(
        private DatabaseManager $db,
        private BlindIndexCodec $blindIndex,
        private FingerprintComposer $fingerprints,
        private SensitiveColumnCodec $codec,
    ) {}

    public function reproducesAStoredDigest(int $userId, string $keyHex, Session $session): bool
    {
        return array_any($this->probeRows($userId), fn (stdClass $row): bool => $this->rowIsKeyedUnder($row, $userId, $keyHex, $session));
    }

    // Rows that could carry either domain: a keyed counterparty name, or an
    // IBAN whose keyed form is stored one table over on an income series.
    /**
     * @return list<stdClass>
     */
    private function probeRows(int $userId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->connection()
            ->table('transactions')
            ->where('user_id', $userId)
            ->where(static function (Builder $query): void {
                $query->whereRaw('length(counterparty_normalized) = ?', [BlindIndexCodec::DIGEST_LENGTH])
                    ->orWhereNotNull('counterparty_iban');
            })
            ->orderBy('id')
            ->limit(self::PROBE_ROWS)
            ->get(['counterparty_normalized', 'counterparty_name', 'counterparty_iban'])
            ->all();

        return $rows;
    }

    private function rowIsKeyedUnder(stdClass $row, int $userId, string $keyHex, Session $session): bool
    {
        return $this->nameDigestIsStored($row, $userId, $keyHex, $session)
            || $this->ibanDigestIsStored($row, $userId, $keyHex, $session);
    }

    // The transaction's own column first, because that costs no query at all.
    // merchants is asked second so a ledger whose transactions were converted
    // by a sweep that could not read their names still answers truthfully.
    private function nameDigestIsStored(stdClass $row, int $userId, string $keyHex, Session $session): bool
    {
        $digest = $this->nameDigest($row, $userId, $keyHex, $session);
        if ($digest === null) {
            return false;
        }

        $stored = self::toString($row->counterparty_normalized ?? null);

        if ($stored !== '' && hash_equals($stored, $digest)) {
            return true;
        }

        return $this->columnHolds('merchants', 'normalized_name', $userId, $digest)
            || $this->columnHolds('recurring_series', 'cluster_counterparty_key', $userId, $digest);
    }

    // Null where the row names no counterparty this key could have written: an
    // unreadable value, or the `_no_counterparty` sentinel every ledger holds
    // and therefore no ledger's authorship can rest on.
    private function nameDigest(stdClass $row, int $userId, string $keyHex, Session $session): ?string
    {
        $name = $this->readable('counterparty_name', $row->counterparty_name ?? null, $userId, $session);
        if ($name === '') {
            return null;
        }

        $normalized = $this->fingerprints->normalize($name);
        if ($normalized === '' || $normalized === CounterpartyKey::NONE) {
            return null;
        }

        return $this->blindIndex->deriveWithKey(CounterpartyKey::DOMAIN, $normalized, $userId, $keyHex);
    }

    // The income half. A ledger of nothing but named-payer-less SEPA credits
    // holds `_no_counterparty` in every transaction and its only keyed value
    // is the payer's IBAN on the series, so the name probe alone reads false
    // on a device whose whole ledger is keyed.
    private function ibanDigestIsStored(stdClass $row, int $userId, string $keyHex, Session $session): bool
    {
        $iban = $this->readable('counterparty_iban', $row->counterparty_iban ?? null, $userId, $session);
        if ($iban === '') {
            return false;
        }

        $digest = $this->blindIndex->deriveWithKey(
            CounterpartyKey::DOMAIN_IBAN,
            CounterpartyKey::normalizeIban($iban),
            $userId,
            $keyHex,
        );

        return $this->columnHolds('recurring_series', 'cluster_counterparty_key', $userId, $digest);
    }

    private function columnHolds(string $table, string $column, int $userId, string $digest): bool
    {
        return $this->db->connection()
            ->table($table)
            ->where('user_id', $userId)
            ->where($column, $digest)
            ->exists();
    }

    // Empty for a sealed value this process holds no epoch key for, which is
    // the same answer as an absent one: neither can prove authorship.
    private function readable(string $column, mixed $stored, int $userId, Session $session): string
    {
        $value = self::toString($stored);
        if ($value === '') {
            return '';
        }

        return trim($this->codec->decryptValue('transactions', $column, $value, $userId, $session)['value']);
    }
}
