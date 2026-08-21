<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Exceptions\EvidenceEncodingFailedException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @internal Resolvers only.
 */
final class ChainLinkInsertHelper
{
    private const INSERT_CHUNK = 100;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $row  Required keys: from_transaction_id, kind, state, confidence,
     *                                     resolver, evidence (array). Optional: to_transaction_id
     *                                     (int|null — NULL only legal for exceeded-tolerance
     *                                     ics_bulk_settle candidates). user_id is sourced from the
     *                                     $user argument; any user_id in $row is ignored.
     * @return bool true when a new row was inserted, false when the (from,
     *              to, kind, user) tuple already had a row in any state.
     */
    public function insertIfNotExists(array $row, User $user): bool
    {
        $connection = $this->db->connection();

        $existsQuery = $connection
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->where('from_transaction_id', $row['from_transaction_id'])
            ->where('kind', $row['kind']);

        $toTxId = $row['to_transaction_id'] ?? null;
        if ($toTxId === null) {
            $existsQuery->whereNull('to_transaction_id');
        } else {
            $existsQuery->where('to_transaction_id', $toTxId);
        }

        if ($existsQuery->exists()) {
            return false;
        }

        $now = $this->clock->now()->toDateTimeString();
        $encoded = self::encodeEvidence($row['evidence'] ?? []);

        $connection->table('chain_links')->insert([
            'user_id' => $user->id,
            'from_transaction_id' => $row['from_transaction_id'],
            'to_transaction_id' => $toTxId,
            'kind' => $row['kind'],
            'state' => $row['state'],
            'confidence' => $row['confidence'],
            'resolver' => $row['resolver'],
            'evidence' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    // Same (from, to, kind, user) uniqueness test as insertIfNotExists(), asked
    // once for the whole batch instead of once per row — a settled card
    // statement covers 50 to 300 expenses, and each one cost a SELECT.
    /**
     * @param  list<array<string, mixed>>  $rows  same keys insertIfNotExists() requires
     * @return int the number of rows inserted
     */
    public function insertMissing(array $rows, User $user): int
    {
        if ($rows === []) {
            return 0;
        }

        $seen = $this->existingPairKeys($rows, $user);
        $now = $this->clock->now()->toDateTimeString();

        $pending = [];
        foreach ($rows as $row) {
            $toTxId = $row['to_transaction_id'] ?? null;
            $key = self::pairKey($row['from_transaction_id'] ?? null, $toTxId, $row['kind'] ?? null);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $pending[] = [
                'user_id' => $user->id,
                'from_transaction_id' => $row['from_transaction_id'],
                'to_transaction_id' => $toTxId,
                'kind' => $row['kind'],
                'state' => $row['state'],
                'confidence' => $row['confidence'],
                'resolver' => $row['resolver'],
                'evidence' => self::encodeEvidence($row['evidence'] ?? []),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($pending === []) {
            return 0;
        }

        $connection = $this->db->connection();
        foreach (array_chunk($pending, self::INSERT_CHUNK) as $chunk) {
            $connection->table('chain_links')->insert($chunk);
        }

        return count($pending);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private function existingPairKeys(array $rows, User $user): array
    {
        $fromIds = [];
        $kinds = [];
        foreach ($rows as $row) {
            $fromIds[] = $row['from_transaction_id'] ?? null;
            $kinds[] = $row['kind'] ?? null;
        }

        $found = $this->db->connection()
            ->table('chain_links')
            ->where('user_id', $user->id)
            ->whereIn('from_transaction_id', array_values(array_unique($fromIds, SORT_REGULAR)))
            ->whereIn('kind', array_values(array_unique($kinds, SORT_REGULAR)))
            ->get(['from_transaction_id', 'to_transaction_id', 'kind']);

        $keys = [];
        foreach ($found as $row) {
            $keys[self::pairKey($row->from_transaction_id, $row->to_transaction_id, $row->kind)] = true;
        }

        return $keys;
    }

    private static function pairKey(mixed $fromId, mixed $toId, mixed $kind): string
    {
        return self::idPart($fromId).'|'.self::idPart($toId).'|'.(is_string($kind) ? $kind : '');
    }

    private static function idPart(mixed $value): string
    {
        return is_numeric($value) ? (string) (int) $value : '';
    }

    private static function encodeEvidence(mixed $evidence): string
    {
        $encoded = json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($encoded === false) {
            throw new EvidenceEncodingFailedException('insert helper');
        }

        return $encoded;
    }
}
