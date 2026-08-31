<?php

declare(strict_types=1);

namespace Modules\Chains\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Exceptions\EvidenceEncodingFailedException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Sync\Public\Events\EntityMutated;

/**
 * @internal Resolvers only.
 */
final readonly class ChainLinkInsertHelper
{
    private const int INSERT_CHUNK = 100;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    // chain_links has no UNIQUE, so the (user, from, to, kind) tuple both write
    // paths already dedupe on is the only statement of what makes a link the
    // same link — and it is the id, because the resolvers run per device and an
    // autoincrement gave each a different number for one funding pair.

    // None of the four moves after insert: `kind` is never rewritten, and a
    // hint row keeps its NULL endpoint until it is deleted whole.
    public static function idFor(int $userId, mixed $fromTransactionId, mixed $toTransactionId, mixed $kind): int
    {
        return DerivedRowId::for('chain_links', [
            'user_id' => $userId,
            'from_transaction_id' => self::idPartOrNull($fromTransactionId),
            'to_transaction_id' => self::idPartOrNull($toTransactionId),
            'kind' => is_string($kind) ? $kind : '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row  Required keys: from_transaction_id, kind, state, confidence,
     *                                     resolver, evidence (array). Optional: to_transaction_id
     *                                     (int|null — NULL only legal for exceeded-tolerance
     *                                     ics_bulk_settle candidates). user_id is sourced from the
     *                                     $userId argument; any user_id in $row is ignored.
     * @return bool true when a new row was inserted, false when the (from,
     *              to, kind, user) tuple already had a row in any state.
     */
    public function insertIfNotExists(array $row, int $userId): bool
    {
        $connection = $this->db->connection();

        $existsQuery = $connection
            ->table('chain_links')
            ->where('user_id', $userId)
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

        $columns = [
            'user_id' => $userId,
            'from_transaction_id' => $row['from_transaction_id'],
            'to_transaction_id' => $toTxId,
            'kind' => $row['kind'],
            'state' => $row['state'],
            'confidence' => $row['confidence'],
            'resolver' => $row['resolver'],
            'evidence' => $encoded,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $id = self::idFor($userId, $row['from_transaction_id'], $toTxId, $row['kind']);

        $connection->table('chain_links')->insert(['id' => $id] + $columns);

        $this->capture($id, $userId, $columns);

        return true;
    }

    // Same (from, to, kind, user) uniqueness test as insertIfNotExists(), asked
    // once for the whole batch instead of once per row — a settled card
    // statement covers 50 to 300 expenses, and each one cost a SELECT.
    /**
     * @param  list<array<string, mixed>>  $rows  same keys insertIfNotExists() requires
     * @return int the number of rows inserted
     */
    public function insertMissing(array $rows, int $userId): int
    {
        if ($rows === []) {
            return 0;
        }

        $seen = $this->existingPairKeys($rows, $userId);
        $now = $this->clock->now()->toDateTimeString();

        $pending = [];
        foreach ($rows as $row) {
            $toTxId = $row['to_transaction_id'] ?? null;
            $key = self::pairKey($row['from_transaction_id'] ?? null, $toTxId, $row['kind'] ?? null);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $pending[self::idFor($userId, $row['from_transaction_id'], $toTxId, $row['kind'])] = [
                'user_id' => $userId,
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

        $insert = [];
        foreach ($pending as $id => $columns) {
            $insert[] = ['id' => $id] + $columns;
        }

        $connection = $this->db->connection();
        foreach (array_chunk($insert, self::INSERT_CHUNK) as $chunk) {
            $connection->table('chain_links')->insert($chunk);
        }

        foreach ($pending as $id => $columns) {
            $this->capture($id, $userId, $columns);
        }

        return count($pending);
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    private function capture(int $id, int $userId, array $columns): void
    {
        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'chain_links',
            pk: $id,
            userId: $userId,
            mutationType: 'create',
            dirtyFields: $columns,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true>
     */
    private function existingPairKeys(array $rows, int $userId): array
    {
        $fromIds = [];
        $kinds = [];
        foreach ($rows as $row) {
            $fromIds[] = $row['from_transaction_id'] ?? null;
            $kinds[] = $row['kind'] ?? null;
        }

        $found = $this->db->connection()
            ->table('chain_links')
            ->where('user_id', $userId)
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

    // The NULL endpoint is a value the identity has to keep, not a missing one:
    // a hint row and a resolved row off the same transaction differ by exactly
    // this column, so folding both onto 0 would make them one link.
    private static function idPartOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
