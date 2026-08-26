<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Pots\Internal\Services\PotRowLoader;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Dto\ReconciliationRow;
use Modules\Pots\Public\Enums\PotStatus;
use stdClass;

final class PotBalanceQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountBalanceQuery $accountBalance,
        private readonly BaseCurrency $baseCurrency,
        private readonly Clock $clock,
        private readonly PotRowLoader $rows,
    ) {}

    // Only money the account already holds can be put in an envelope. Counting
    // a future-dated row made a pot read as funded by a payment still to
    // arrive, and left isOverAllocated false while the account could not cover
    // what its pots claimed.
    private function realBalance(int $accountId, User $user, string $currency): int
    {
        return $this->accountBalance
            ->currentBalanceAsOf($accountId, $user, $this->clock->now()->startOfDay())
            ->in($currency);
    }

    public function balanceForPot(int $potId, User $user): int
    {
        return $this->rows->balanceForPot($potId, $user);
    }

    public function reconciliationForAccount(int $accountId, User $user): ReconciliationRow
    {
        $accountRow = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->first(['name', 'default_currency']);

        $accountName = ($accountRow !== null && is_string($accountRow->name))
            ? $accountRow->name
            : '';
        $currency = ($accountRow !== null && is_string($accountRow->default_currency))
            ? $accountRow->default_currency
            : $this->baseCurrency->code();

        $allocated = $this->allocatedForAccount($accountId, $user);
        $real = $this->realBalance($accountId, $user, $currency);
        $unallocated = $real - $allocated;

        return new ReconciliationRow(
            accountId: $accountId,
            accountName: $accountName,
            currency: $currency,
            realBalanceMinor: $real,
            allocatedMinor: $allocated,
            unallocatedMinor: $unallocated,
            isOverAllocated: $unallocated < 0,
        );
    }

    public function currentUnallocatedForAccount(int $accountId, User $user): int
    {
        $real = $this->realBalance($accountId, $user, $this->accountCurrency($accountId, $user));

        return $real - $this->allocatedForAccount($accountId, $user);
    }

    // Pots are denominated in the account's own currency, so a multi-currency
    // account answers "what is unallocated" in that one line and leaves the
    // rest of what it holds out of the arithmetic entirely.
    private function accountCurrency(int $accountId, User $user): string
    {
        $currency = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('id', $accountId)
            ->value('default_currency');

        return is_string($currency) && $currency !== '' ? $currency : $this->baseCurrency->code();
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
            ->where('status', PotStatus::Active->value)
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

    /**
     * @return array<int, array{balance: int, currency: string, potId: int}> goal_id => pot balance, currency, pot id
     */
    public function linkedPotBalancesForUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->whereNotNull('goal_id')
            ->get(['id', 'goal_id', 'currency']);

        $result = [];
        foreach ($rows as $row) {
            $potId = self::toInt($row->id);
            $result[self::toInt($row->goal_id)] = [
                'balance' => $this->balanceForPot($potId, $user),
                'currency' => self::toString($row->currency),
                'potId' => $potId,
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

    private function allocatedForAccount(int $accountId, User $user): int
    {
        $activePotIds = $this->db->connection()
            ->table('pots')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->where('status', PotStatus::Active->value)
            ->pluck('id')
            ->toArray();

        if ($activePotIds === []) {
            return 0;
        }

        return (int) $this->db->connection()
            ->table('pot_movements')
            ->where('user_id', $user->id)
            ->whereIn('pot_id', $activePotIds)
            ->sum('amount_minor');
    }
}
