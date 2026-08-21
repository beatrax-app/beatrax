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
        $evidence = $row['evidence'] ?? [];
        $encoded = json_encode(
            $evidence,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if ($encoded === false) {
            throw new EvidenceEncodingFailedException('insert helper');
        }

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
}
