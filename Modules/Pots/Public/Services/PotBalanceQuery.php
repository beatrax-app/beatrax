<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Pots\Public\Dto\PotMovementRow;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Dto\ReconciliationRow;

/**
 * Read model for the /pots page and the Goals D-10 override.
 *
 * All reads use raw `$this->db->connection()->table(...)` — never Eloquent
 * static calls (Larastan level 10 staticMethod.dynamicCall per 03-RESEARCH.md
 * Pitfall 6).
 *
 * Cross-user isolation: every read carries an explicit `->where('user_id', …)`
 * guard (T-03-07).
 *
 * D-01: unallocatedMinor is NEVER read from a stored column — always derived
 * as real − allocated at read time.
 *
 * D-06: a pot's balance is the signed SUM(amount_minor) of its pot_movements
 * rows — there is no stored balance column.
 */
final class PotBalanceQuery
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountBalanceQuery $accountBalance,
        private readonly PeriodQuery $periods,
    ) {}

    /**
     * Returns the signed SUM(amount_minor) for a pot — the pot's current
     * balance (D-06). Returns 0 when no movements exist.
     */
    public function balanceForPot(int $potId): int
    {
        return (int) $this->db->connection()
            ->table('pot_movements')
            ->where('pot_id', $potId)
            ->sum('amount_minor');
    }

    /**
     * Returns per-account reconciliation data: real = allocated + unallocated
     * (D-01 / D-15).
     *
     * `allocatedMinor` = SUM of all ACTIVE pot balances for the account.
     * `unallocatedMinor` = real − allocated (derived, never stored — D-01).
     * `isOverAllocated` = unallocated < 0 (D-02 — surface the negative state,
     * never auto-rewrite it).
     * Archived pots are excluded from allocated (D-09).
     */
    public function reconciliationForAccount(int $accountId, User $user): ReconciliationRow
    {
        $allocated = $this->allocatedForAccount($accountId, $user);
        $real = $this->accountBalance->currentBalance($accountId, $user);
        $unallocated = $real - $allocated;

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
            : 'EUR';

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

    /**
     * Returns real − allocated for an account — the value PotWriter guards
     * against when funding a pot (D-03).
     *
     * Delegates to `allocatedForAccount()` so reconciliation and the
     * writer-facing method share one query implementation.
     */
    public function currentUnallocatedForAccount(int $accountId, User $user): int
    {
        $real = $this->accountBalance->currentBalance($accountId, $user);

        return $real - $this->allocatedForAccount($accountId, $user);
    }

    /**
     * Returns active pots for the user ordered by account name then pot name,
     * each row carrying balanceMinor, accountName, goalName/categoryName
     * (left-joined), recentMovements (latest 10), and categorySpentMinor for
     * category-linked pots (D-12).
     *
     * @return list<PotRow>
     */
    public function forUser(User $user): array
    {
        return $this->loadPotRows($user, 'active');
    }

    /**
     * Returns archived pots for the user. Balance is expected to be 0 after
     * the archive-release (D-09) but computed honestly via balanceForPot.
     *
     * @return list<PotRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->loadPotRows($user, 'archived');
    }

    /**
     * Batch-load pot balances keyed by goal_id for all active goal-linked pots
     * owned by the user — avoids N+1 in GoalProgressQuery (03-RESEARCH.md
     * Pitfall 3 / D-10).
     *
     * @return array<int, int> goal_id => balanceMinor
     */
    public function linkedPotBalancesForUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('goal_id')
            ->get(['id', 'goal_id']);

        $result = [];
        foreach ($rows as $row) {
            $result[self::toInt($row->goal_id)] = $this->balanceForPot(self::toInt($row->id));
        }

        return $result;
    }

    /**
     * Returns the id of the active pot linked to $goalId for $user, or null
     * when no such pot exists. Used by GoalsPage to prefill the pot-picker
     * when opening the edit modal (D-11).
     */
    public function linkedPotIdForGoal(int $goalId, User $user): ?int
    {
        $row = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('goal_id', $goalId)
            ->first(['id']);

        if ($row === null) {
            return null;
        }

        $id = $row->id ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Returns the currency of the active pot linked to $goalId for $user, or
     * null when no such pot exists. Used by GoalProgressQuery to decide whether
     * FX conversion is needed (D-10).
     */
    public function currencyForLinkedPot(int $goalId, User $user): ?string
    {
        $row = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('goal_id', $goalId)
            ->first(['currency']);

        if ($row === null) {
            return null;
        }

        $currency = $row->currency ?? null;

        return is_string($currency) ? $currency : null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the SUM of all active pot balances for an account. Archived pots
     * are excluded (D-09). Private — shared by reconciliationForAccount and
     * currentUnallocatedForAccount so neither duplicates the query logic.
     */
    private function allocatedForAccount(int $accountId, User $user): int
    {
        $activePotIds = $this->db->connection()
            ->table('pots')
            ->where('account_id', $accountId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();

        if ($activePotIds === []) {
            return 0;
        }

        return (int) $this->db->connection()
            ->table('pot_movements')
            ->whereIn('pot_id', $activePotIds)
            ->sum('amount_minor');
    }

    /**
     * Internal loader: fetches pots with the given status, enriches each with
     * balance, recent movements, and category spend.
     *
     * @return list<PotRow>
     */
    private function loadPotRows(User $user, string $status): array
    {
        $connection = $this->db->connection();

        $pots = $connection->table('pots')
            ->leftJoin('accounts', 'pots.account_id', '=', 'accounts.id')
            ->leftJoin('goals', 'pots.goal_id', '=', 'goals.id')
            ->leftJoin('categories', 'pots.category_id', '=', 'categories.id')
            ->where('pots.user_id', $user->id)
            ->where('pots.status', $status)
            ->orderBy('accounts.name')
            ->orderBy('pots.name')
            ->get([
                'pots.id',
                'pots.name',
                'pots.account_id',
                'pots.currency',
                'pots.status',
                'pots.goal_id',
                'pots.category_id',
                'pots.created_at',
                'accounts.name as account_name',
                'goals.name as goal_name',
                'categories.name as category_name',
            ]);

        if ($pots->isEmpty()) {
            return [];
        }

        // Build a lookup of pot names for resolving counterpart transfer names
        $potNameById = $connection->table('pots')
            ->where('user_id', $user->id)
            ->pluck('name', 'id')
            ->toArray();

        // Derive current-period dates once (for category spend)
        $period = $this->periods->current();

        $rows = [];
        foreach ($pots as $pot) {
            $potId = self::toInt($pot->id);
            $balanceMinor = $this->balanceForPot($potId);

            // Recent movements: latest 10 for this pot
            $movementRows = $connection->table('pot_movements')
                ->where('pot_id', $potId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(10)
                ->get([
                    'id',
                    'kind',
                    'amount_minor',
                    'currency',
                    'counterpart_pot_id',
                    'memo',
                    'created_at',
                ]);

            $recentMovements = [];
            foreach ($movementRows as $m) {
                $counterpartPotId = $m->counterpart_pot_id !== null
                    ? self::toInt($m->counterpart_pot_id)
                    : null;

                $counterpartPotName = null;
                if ($counterpartPotId !== null) {
                    $counterpartPotName = isset($potNameById[$counterpartPotId])
                        ? self::toStr($potNameById[$counterpartPotId])
                        : null;
                }

                $createdAt = '';
                if ($m->created_at !== null && $m->created_at !== '') {
                    try {
                        $createdAt = CarbonImmutable::parse(self::toStr($m->created_at))->format('Y-m-d H:i');
                    } catch (\Throwable) {
                        $createdAt = '';
                    }
                }

                $recentMovements[] = new PotMovementRow(
                    id: self::toInt($m->id),
                    kind: self::toStr($m->kind),
                    amountMinor: self::toInt($m->amount_minor),
                    currency: self::toStr($m->currency),
                    counterpartPotId: $counterpartPotId,
                    counterpartPotName: $counterpartPotName,
                    memo: is_string($m->memo) ? $m->memo : null,
                    createdAt: $createdAt,
                );
            }

            // Category spend for the current period (D-12)
            $categoryId = $pot->category_id !== null ? self::toInt($pot->category_id) : null;
            $categorySpentMinor = null;
            if ($categoryId !== null) {
                $spent = (int) $connection->table('transactions')
                    ->where('user_id', $user->id)
                    ->where('category_id', $categoryId)
                    ->where('settled_amount_minor', '<', 0)
                    ->where('posted_at', '>=', $period->start->toDateString())
                    ->where('posted_at', '<', $period->endExclusive->toDateString())
                    ->sum($connection->raw('-settled_amount_minor'));
                $categorySpentMinor = $spent;
            }

            $potCreatedAtRaw = self::toStr($pot->created_at ?? null);
            $potCreatedAt = '';
            if ($potCreatedAtRaw !== '') {
                try {
                    $potCreatedAt = CarbonImmutable::parse($potCreatedAtRaw)->format('Y-m-d H:i');
                } catch (\Throwable) {
                    $potCreatedAt = '';
                }
            }

            $rows[] = new PotRow(
                id: $potId,
                name: self::toStr($pot->name),
                accountId: self::toInt($pot->account_id),
                accountName: self::toStr($pot->account_name),
                balanceMinor: $balanceMinor,
                currency: self::toStr($pot->currency),
                status: self::toStr($pot->status),
                goalId: $pot->goal_id !== null ? self::toInt($pot->goal_id) : null,
                goalName: is_string($pot->goal_name) ? $pot->goal_name : null,
                categoryId: $categoryId,
                categoryName: is_string($pot->category_name) ? $pot->category_name : null,
                categorySpentMinor: $categorySpentMinor,
                recentMovements: $recentMovements,
            );
        }

        return $rows;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
