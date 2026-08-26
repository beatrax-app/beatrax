<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Pots\Public\Dto\PotMovementRow;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Enums\PotStatus;
use stdClass;

// The pots screen wants a pot as the reader sees it: its account, its goal, its
// category spend for the open period and its last movements. Assembling that is
// a different job from answering what an account holds, so it reads the
// pot_movements sums on PotBalanceQuery's behalf rather than the other way round.
/**
 * @link ../../../../.docs/features/pots/architecture.md
 */
final class PotRowLoader
{
    use CoercesScalars;

    private const RECENT_MOVEMENT_LIMIT = 10;

    public function __construct(
        private readonly DatabaseManager $db,
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

    /**
     * @return list<PotRow>
     */
    public function forStatus(User $user, PotStatus $status): array
    {
        $connection = $this->db->connection();

        $pots = $connection->table('pots')
            ->leftJoin('accounts', 'pots.account_id', '=', 'accounts.id')
            ->leftJoin('goals', 'pots.goal_id', '=', 'goals.id')
            ->leftJoin('categories', 'pots.category_id', '=', 'categories.id')
            ->where('pots.user_id', $user->id)
            ->where('pots.status', $status->value)
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
                ...CategoryDisplayName::columns('categories'),
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
            ->limit(self::RECENT_MOVEMENT_LIMIT)
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
            categoryName: CategoryDisplayName::fromRow($pot, 'category'),
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
        return SafeDate::parseOrNull(self::toString($createdAt))?->format('Y-m-d H:i') ?? '';
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
