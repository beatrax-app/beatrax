<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/architecture/chain-resolution.md
 */
final class RetypeByAliasResolver
{
    use CoercesScalars;

    private const UPDATE_BATCH_SIZE = 500;

    private const CANDIDATE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
    ) {}

    /**
     * @return int The number of rows retyped.
     */
    public function resolveForUser(User $user): int
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toDateTimeString();

        // The early return is load-bearing, not an optimisation: with no aliases
        // there is nothing to match, and the pass below would decrypt every
        // counterparty IBAN for nothing.
        $aliasKindByIban = $this->loadAliasMap($connection, $user);
        if ($aliasKindByIban === []) {
            return 0;
        }

        $accountIdsByKind = $this->loadAccountKindMap($connection, $user);

        /** @var list<int> $transferOutIds */
        $transferOutIds = [];
        /** @var list<int> $transferInIds */
        $transferInIds = [];

        $connection
            ->table('transactions')
            ->select(['id', 'account_id', 'amount_minor', 'counterparty_iban'])
            ->where('user_id', $user->id)
            ->whereIn('type', ['expense', 'income'])
            ->whereNotNull('counterparty_iban')
            ->where('counterparty_iban', '!=', '')
            ->chunkById(self::CANDIDATE_CHUNK_SIZE, function ($rows) use (
                $aliasKindByIban,
                $accountIdsByKind,
                $user,
                &$transferOutIds,
                &$transferInIds,
            ): void {
                foreach ($rows as $row) {
                    /** @var stdClass $row */
                    $this->matchCandidate(
                        $row,
                        $aliasKindByIban,
                        $accountIdsByKind,
                        $user,
                        $transferOutIds,
                        $transferInIds,
                    );
                }
            });

        $touched = 0;
        $touched += $this->applyRetype($connection, $transferOutIds, 'transfer_out', $now);
        $touched += $this->applyRetype($connection, $transferInIds, 'transfer_in', $now);

        return $touched;
    }

    /**
     * @param  array<string, string>  $aliasKindByIban
     * @param  array<string, list<int>>  $accountIdsByKind
     * @param  list<int>  $transferOutIds
     * @param  list<int>  $transferInIds
     *
     * @param-out list<int> $transferOutIds
     * @param-out list<int> $transferInIds
     */
    private function matchCandidate(
        stdClass $row,
        array $aliasKindByIban,
        array $accountIdsByKind,
        User $user,
        array &$transferOutIds,
        array &$transferInIds,
    ): void {
        $storedIban = $row->counterparty_iban ?? null;
        if (! is_string($storedIban) || $storedIban === '') {
            return;
        }

        $plainIban = $this->codec->decryptValue(
            'transactions',
            'counterparty_iban',
            $storedIban,
            $user->id,
            ($this->session)(),
        )['value'];

        $targetKind = $aliasKindByIban[$plainIban] ?? null;
        if ($targetKind === null) {
            return;
        }

        $rowId = self::toInt($row->id ?? null);
        $ownAccountId = self::toInt($row->account_id ?? null);
        if ($rowId === 0 || ! self::hasAccountOtherThan($accountIdsByKind[$targetKind] ?? [], $ownAccountId)) {
            return;
        }

        $amountMinor = self::toInt($row->amount_minor ?? null);
        if ($amountMinor < 0) {
            $transferOutIds[] = $rowId;
        } else {
            $transferInIds[] = $rowId;
        }
    }

    /**
     * @param  list<int>  $targetAccountIds
     */
    private static function hasAccountOtherThan(array $targetAccountIds, int $ownAccountId): bool
    {
        foreach ($targetAccountIds as $targetAccountId) {
            if ($targetAccountId !== $ownAccountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> real_iban (plaintext) => target_account_kind
     */
    private function loadAliasMap(Connection $connection, User $user): array
    {
        $map = [];
        $rows = $connection->table('known_counterparty_ibans')
            ->where('user_id', $user->id)
            ->get(['real_iban', 'target_account_kind']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $realIban = self::toStringOrNull($row->real_iban ?? null);
            $targetKind = self::toStringOrNull($row->target_account_kind ?? null);
            if ($realIban !== null && $realIban !== '' && $targetKind !== null && $targetKind !== '') {
                $map[$realIban] = $targetKind;
            }
        }

        return $map;
    }

    /**
     * @return array<string, list<int>> kind => account ids
     */
    private function loadAccountKindMap(Connection $connection, User $user): array
    {
        $map = [];
        $rows = $connection->table('accounts')
            ->where('user_id', $user->id)
            ->get(['id', 'kind']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $kind = self::toStringOrNull($row->kind ?? null);
            $id = self::toInt($row->id ?? null);
            if ($kind !== null && $kind !== '' && $id > 0) {
                $map[$kind][] = $id;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     */
    private function applyRetype(Connection $connection, array $ids, string $type, string $now): int
    {
        if ($ids === []) {
            return 0;
        }

        $touched = 0;
        foreach (array_chunk($ids, self::UPDATE_BATCH_SIZE) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $touched += $connection->update(
                "UPDATE transactions SET type = ?, updated_at = ? WHERE id IN ({$placeholders})",
                [$type, $now, ...$batch],
            );
        }

        return $touched;
    }
}
