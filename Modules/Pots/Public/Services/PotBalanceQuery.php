<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Goals\Public\Enums\GoalStatus;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Pots\Internal\Services\PotAllocationLedger;
use Modules\Pots\Internal\Services\PotRowLoader;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Dto\ReconciliationRow;
use Modules\Pots\Public\Enums\PotStatus;
use stdClass;

final readonly class PotBalanceQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private PotAllocationLedger $allocations,
        private PotRowLoader $rows,
    ) {}

    public function balanceForPot(int $potId, User $user): int
    {
        return $this->rows->balanceForPot($potId, $user);
    }

    // One currency's line of the reconciliation. Null takes the account's own
    // denomination; a caller holding a pot passes the pot's, because that is
    // the only currency the pot's balance is expressed in.
    public function reconciliationForAccount(int $accountId, User $user, ?string $currency = null): ReconciliationRow
    {
        return $this->allocations->row($accountId, $user, $currency);
    }

    /**
     * @return list<ReconciliationRow>
     */
    public function reconciliationsForAccount(int $accountId, User $user): array
    {
        return $this->allocations->rows($accountId, $user);
    }

    // The currency is not optional: what is unallocated is a different figure
    // on every line the account holds, and a caller that did not have to name
    // one took the account's and printed it under the pot's sign.
    public function currentUnallocatedForAccount(int $accountId, User $user, string $currency): int
    {
        return $this->allocations->unallocated($accountId, $user, $currency);
    }

    public function currencyForAccount(int $accountId, User $user): string
    {
        return $this->allocations->accountCurrency($accountId, $user);
    }

    /**
     * @return list<PotRow>
     */
    public function forUser(User $user): array
    {
        return $this->rows->forStatus($user, PotStatus::Active);
    }

    /**
     * @return list<PotRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->rows->forStatus($user, PotStatus::Archived);
    }

    /**
     * @return array<int, stdClass>
     */
    public function accountsForUser(User $user): array
    {
        return $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->whereIn('kind', AccountKind::spendableValues())
            ->orderBy('name')
            ->get(['id', 'name', 'default_currency'])
            ->all();
    }

    // Active goals no other pot has claimed, plus the edited pot's own goal, which
    // would otherwise vanish from its own picker. editPotId is client-controlled.
    /**
     * @return array<int, stdClass>
     */
    public function goalsForPicker(User $user, int $editPotId): array
    {
        $linkedGoalIds = $this->activeLinkedGoalIds($user);

        $goalsQuery = $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('status', GoalStatus::Active->value)
            ->orderBy('name');

        if ($editPotId !== 0) {
            $currentPotGoalId = $this->db->connection()
                ->table('pots')
                ->where('user_id', $user->id)
                ->where('id', $editPotId)
                ->value('goal_id');
            $goalsToExclude = array_filter(
                $linkedGoalIds,
                static fn (mixed $id): bool => $id !== $currentPotGoalId,
            );
            if ($goalsToExclude !== []) {
                $goalsQuery->whereNotIn('id', array_values($goalsToExclude));
            }
        } elseif ($linkedGoalIds !== []) {
            $goalsQuery->whereNotIn('id', $linkedGoalIds);
        }

        return $goalsQuery->get(['id', 'name'])->all();
    }

    // The goals a pot already funds. A goal funded by a pot takes its progress
    // from that pot alone, so anything offering a second funding route has to
    // know which goals are spoken for.
    /**
     * @return list<int>
     */
    public function goalIdsWithAnActivePot(User $user): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => self::toInt($id),
            $this->activeLinkedGoalIds($user),
        ));
    }

    /**
     * @return array<mixed>
     */
    private function activeLinkedGoalIds(User $user): array
    {
        return $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->whereNotNull('goal_id')
            ->pluck('goal_id')
            ->all();
    }

    // `hasMovements` is not `balance !== 0`: a pot funded and then emptied has a
    // contribution history and a zero balance, and the goal card tells the two
    // apart -- one is asked for a first contribution, the other is not.
    /**
     * @return array<int, array{balance: int, currency: string, potId: int, hasMovements: bool}> goal_id => pot balance, currency, pot id
     */
    public function linkedPotBalancesForUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->whereNotNull('goal_id')
            ->get(['id', 'goal_id', 'currency']);

        $potIds = array_map(static fn (stdClass $row): int => self::toInt($row->id), $rows->all());

        $moved = $potIds === [] ? [] : $this->db->connection()
            ->table('pot_movements')
            ->where('user_id', $user->id)
            ->whereIn('pot_id', $potIds)
            ->distinct()
            ->pluck('pot_id')
            ->map(static fn (mixed $id): int => self::toInt($id))
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $potId = self::toInt($row->id);
            $result[self::toInt($row->goal_id)] = [
                'balance' => $this->balanceForPot($potId, $user),
                'currency' => self::toString($row->currency),
                'potId' => $potId,
                'hasMovements' => in_array($potId, $moved, true),
            ];
        }

        return $result;
    }

    public function netMovementForPotSince(int $potId, string $since, User $user): int
    {
        return (int) $this->db->connection()
            ->table('pot_movements')
            ->where('user_id', $user->id)
            ->where('pot_id', $potId)
            ->where('created_at', '>=', $since)
            ->sum('amount_minor');
    }

    public function linkedPotIdForGoal(int $goalId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->where('goal_id', $goalId)
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        $id = $row->id ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    // One goal at a time. Anything iterating goals wants linkedPotBalancesForUser(),
    // which returns the currency beside the balance in one query.
    public function currencyForLinkedPot(int $goalId, User $user): ?string
    {
        $row = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->where('goal_id', $goalId)
            ->first(['currency']);

        if ($row === null) {
            return null;
        }

        $currency = $row->currency ?? null;

        return is_string($currency) ? $currency : null;
    }
}
