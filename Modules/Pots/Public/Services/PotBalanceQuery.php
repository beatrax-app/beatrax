<?php

declare(strict_types=1);

namespace Modules\Pots\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Pots\Public\Dto\PotMovementRow;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Dto\ReconciliationRow;
use stdClass;

/**
 * @link ../../../../.docs/features/pots/architecture.md
 */
final class PotBalanceQuery
{
    use CoercesScalars;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AccountBalanceQuery $accountBalance,
        private readonly PeriodQuery $periods,
    ) {}

    public function balanceForPot(int $potId, User $user): int
    {
        return (int) $this->db->connection()
            ->table('pot_movements')
            ->where('user_id', $user->id)
            ->where('pot_id', $potId)
            ->sum('amount_minor');
    }

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

    public function currentUnallocatedForAccount(int $accountId, User $user): int
    {
        $real = $this->accountBalance->currentBalance($accountId, $user);

        return $real - $this->allocatedForAccount($accountId, $user);
    }

    /**
     * @return list<PotRow>
     */
    public function forUser(User $user): array
    {
        return $this->loadPotRows($user, 'active');
    }

    /**
     * @return list<PotRow>
     */
    public function archivedForUser(User $user): array
    {
        return $this->loadPotRows($user, 'archived');
    }

    /**
     * @return array<int, array{balance: int, currency: string}> goal_id => pot balance + currency
     */
    public function linkedPotBalancesForUser(User $user): array
    {
        $rows = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNotNull('goal_id')
            ->get(['id', 'goal_id', 'currency']);

        $result = [];
        foreach ($rows as $row) {
            $result[self::toInt($row->goal_id)] = [
                'balance' => $this->balanceForPot(self::toInt($row->id), $user),
                'currency' => self::toString($row->currency),
            ];
        }

        return $result;
    }

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

    // Single-goal convenience lookup; batched consumers should prefer
    // linkedPotBalancesForUser(), which carries the currency alongside the
    // balance.
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

    // Shared by reconciliationForAccount and currentUnallocatedForAccount so
    // neither duplicates the query logic.
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
            ->where('user_id', $user->id)
            ->whereIn('pot_id', $activePotIds)
            ->sum('amount_minor');
    }

    /**
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
                'accounts.name as account_name',
                'goals.name as goal_name',
                'categories.name as category_name',
            ]);

        if ($pots->isEmpty()) {
            return [];
        }

        $potNameById = $connection->table('pots')
            ->where('user_id', $user->id)
            ->pluck('name', 'id')
            ->toArray();

        $period = $this->periods->current();
        $periodStart = $period->start->toDateString();
        $periodEndExclusive = $period->endExclusive->toDateString();

        $rows = [];
        foreach ($pots as $pot) {
            $rows[] = $this->buildPotRow($pot, $user, $connection, $potNameById, $periodStart, $periodEndExclusive);
        }

        return $rows;
    }

    /**
     * @param  array<int|string, mixed>  $potNameById
     */
    private function buildPotRow(stdClass $pot, User $user, ConnectionInterface $connection, array $potNameById, string $periodStart, string $periodEndExclusive): PotRow
    {
        $potId = self::toInt($pot->id);

        $movementRows = $connection->table('pot_movements')
            ->where('user_id', $user->id)
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

        $categoryId = $pot->category_id !== null ? self::toInt($pot->category_id) : null;

        return new PotRow(
            id: $potId,
            name: self::toString($pot->name),
            accountId: self::toInt($pot->account_id),
            accountName: self::toString($pot->account_name),
            balanceMinor: $this->balanceForPot($potId, $user),
            currency: self::toString($pot->currency),
            status: self::toString($pot->status),
            goalId: $pot->goal_id !== null ? self::toInt($pot->goal_id) : null,
            goalName: is_string($pot->goal_name) ? $pot->goal_name : null,
            categoryId: $categoryId,
            categoryName: is_string($pot->category_name) ? $pot->category_name : null,
            categorySpentMinor: $this->categorySpentMinor($connection, $pot, $categoryId, $user, $periodStart, $periodEndExclusive),
            recentMovements: $this->buildRecentMovements($movementRows, $potNameById),
        );
    }

    /**
     * @param  iterable<mixed>  $movementRows
     * @param  array<int|string, mixed>  $potNameById
     * @return list<PotMovementRow>
     */
    private function buildRecentMovements(iterable $movementRows, array $potNameById): array
    {
        $recentMovements = [];
        foreach ($movementRows as $m) {
            /** @var stdClass $m */
            $counterpartPotId = $m->counterpart_pot_id !== null ? self::toInt($m->counterpart_pot_id) : null;
            $counterpartPotName = ($counterpartPotId !== null && isset($potNameById[$counterpartPotId]))
                ? self::toString($potNameById[$counterpartPotId])
                : null;

            $recentMovements[] = new PotMovementRow(
                id: self::toInt($m->id),
                kind: self::toString($m->kind),
                amountMinor: self::toInt($m->amount_minor),
                currency: self::toString($m->currency),
                counterpartPotId: $counterpartPotId,
                counterpartPotName: $counterpartPotName,
                memo: is_string($m->memo) ? $m->memo : null,
                createdAt: self::formatMovementDate($m->created_at),
            );
        }

        return $recentMovements;
    }

    private static function formatMovementDate(mixed $createdAt): string
    {
        if ($createdAt === null || $createdAt === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse(self::toString($createdAt))->format('Y-m-d H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    // Filtered to the pot's own currency — summing mixed currencies in raw
    // minor units would be dishonest against the label the view shows.
    private function categorySpentMinor(ConnectionInterface $connection, stdClass $pot, ?int $categoryId, User $user, string $periodStart, string $periodEndExclusive): ?int
    {
        if ($categoryId === null) {
            return null;
        }

        return (int) $connection->table('transactions')
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->where('settled_currency', self::toString($pot->currency))
            ->where('settled_amount_minor', '<', 0)
            ->where('posted_at', '>=', $periodStart)
            ->where('posted_at', '<', $periodEndExclusive)
            ->sum($connection->raw('-settled_amount_minor'));
    }
}
