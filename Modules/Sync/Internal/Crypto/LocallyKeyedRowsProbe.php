<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Contracts\BlindIndexProvenance;
use Modules\Sync\Public\Services\BlindIndexCodec;

// Whether THIS device holds rows keyed under the blind-index key it has. Two
// proofs of authorship, unioned: an op-log entry this device signed, and a
// digest this device's key still reproduces. Neither can be satisfied by a
// peer's replayed rows, which a probe measuring only shape could not tell apart.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class LocallyKeyedRowsProbe
{
    // One entry per (table, pk, field), so a bounded newest-first window over
    // a single field is enough: a device that ever wrote a digest wrote one
    // here, and a device that never did has nothing to find however far back
    // the window reaches.
    private const int PROBE_ENTRIES = 25;

    public function __construct(
        private DatabaseManager $db,
        private BlindIndexProvenance $provenance,
    ) {}

    /**
     * @param  string  $deviceId  This device's own op-log author id.
     * @param  string  $keyHex  The blind-index key this device currently holds.
     */
    public function holdsRowsKeyedUnder(int $userId, string $deviceId, string $keyHex, Session $session): bool
    {
        return $this->wroteADigestItself($userId, $deviceId)
            || $this->provenance->reproducesAStoredDigest($userId, $keyHex, $session);
    }

    // The op-log records who authored each field value, so an entry this
    // device signed carrying a digest is authorship with nothing inferred.
    // It cannot see rows a raw enable-time sweep rewrote before any capture.
    private function wroteADigestItself(int $userId, string $deviceId): bool
    {
        if ($deviceId === '') {
            return false;
        }

        foreach (array_keys(BlindIndexCodec::indexedColumns()) as $qualified) {
            [$table, $field] = array_pad(explode('.', $qualified, 2), 2, '');

            if ($this->authoredADigestFor($userId, $deviceId, $table, $field)) {
                return true;
            }
        }

        return false;
    }

    private function authoredADigestFor(int $userId, string $deviceId, string $table, string $field): bool
    {
        $values = $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('table_name', $table)
            ->where('field', $field)
            ->orderByDesc('hlc_l')
            ->orderByDesc('hlc_c')
            ->limit(self::PROBE_ENTRIES)
            ->pluck('value');

        foreach ($values as $raw) {
            if (is_string($raw) && self::decodesToADigest($raw)) {
                return true;
            }
        }

        return false;
    }

    // Entry values are always JSON, and a blind-index column is never on the
    // sensitive list, so the stored form of a digest is the quoted string.
    private static function decodesToADigest(string $raw): bool
    {
        $decoded = json_decode($raw, true);

        return is_string($decoded) && BlindIndexCodec::looksDerived($decoded);
    }
}
