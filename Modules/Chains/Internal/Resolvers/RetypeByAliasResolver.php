<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Resolvers;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\RowChunk;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Ledger\Public\Services\TransactionTypeWriter;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/architecture/chain-resolution.md
 */
final readonly class RetypeByAliasResolver
{
    use CoercesScalars;

    private const int CANDIDATE_CHUNK_SIZE = RowChunk::DEFAULT_SIZE;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private TransactionTypeWriter $types,
    ) {}

    /**
     * @return int The number of rows retyped.
     */
    public function resolveForUser(User $user): int
    {
        $connection = $this->db->connection();

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
            ->whereIn('type', [TransactionType::Expense->value, TransactionType::Income->value])
            // No `!= ''` beside it: sealed values are never the empty string,
            // so that half of the filter admitted every row once the column
            // was encrypted. matchCandidate() refuses a blank of either kind.
            ->whereNotNull('counterparty_iban')
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

        return $this->types->retype($user->id, $transferOutIds, TransactionType::TransferOut)
            + $this->types->retype($user->id, $transferInIds, TransactionType::TransferIn);
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
        return array_any($targetAccountIds, fn (int $targetAccountId): bool => $targetAccountId !== $ownAccountId);
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
}
