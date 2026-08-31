<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Pots\Public\Dto\PotMovementRow;
use Modules\Pots\Public\Dto\PotRow;
use Modules\Pots\Public\Enums\PotMovementKind;
use Modules\Pots\Public\Enums\PotStatus;
use stdClass;

// The pots screen wants a pot as the reader sees it: its account, its goal, its
// category spend for the open period and its last movements. Assembling that is
// a different job from answering what an account holds, so it reads the
// pot_movements sums on PotBalanceQuery's behalf rather than the other way round.
/**
 * @link ../../../../.docs/features/pots/architecture.md
 */
final readonly class PotRowLoader
{
    use CoercesScalars;

    private const int RECENT_MOVEMENT_LIMIT = 10;

    public function __construct(
        private DatabaseManager $db,
        private PeriodQuery $periods,
        private CrossCurrencyTotal $fx,
        private SpendByCategoryQuery $spendByCategory,
        private Clock $clock,
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

        $joined = $connection->table('pots')
            ->leftJoin('accounts', 'pots.account_id', '=', 'accounts.id')
            ->leftJoin('goals', 'pots.goal_id', '=', 'goals.id')
            ->leftJoin('categories', 'pots.category_id', '=', 'categories.id');

        $pots = CategoryPathName::joinParent($joined, $user->id, 'categories', 'parent_categories')
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
                ...CategoryPathName::columns('categories', 'parent_categories'),
            ]);

        if ($pots->isEmpty()) {
            return [];
        }

        $potNameById = $connection->table('pots')
            ->where('user_id', $user->id)
            ->pluck('name', 'id')
            ->toArray();

        // The owner's own budget month, not the browsing session's: this is a
        // Public read a caller may run for a user who is not behind the guard.
        $period = $this->periods->containingForUser($user, $this->clock->now());
        $spendByCategory = $this->spendByCategory->forUserAndPeriodByCurrency($user->id, $period);

        $movementCounts = $this->movementCounts(
            $connection,
            $user,
            array_values(array_map(static fn (stdClass $pot): int => self::toInt($pot->id), $pots->all())),
        );

        $rows = [];
        foreach ($pots as $pot) {
            $rows[] = $this->buildPotRow($pot, $user, $connection, $potNameById, $spendByCategory, $movementCounts);
        }

        return $rows;
    }

    // One statement for the whole list: the card needs to know an eleventh
    // movement exists, and asking per pot would be a query per card.
    /**
     * @param  list<int>  $potIds
     * @return array<int, int>
     */
    private function movementCounts(ConnectionInterface $connection, User $user, array $potIds): array
    {
        if ($potIds === []) {
            return [];
        }

        $rows = $connection->table('pot_movements')
            ->where('user_id', $user->id)
            ->whereIn('pot_id', $potIds)
            ->groupBy('pot_id')
            ->get(['pot_id', $connection->raw('COUNT(*) as movement_count')]);

        $counts = [];
        foreach ($rows as $row) {
            $counts[self::toInt($row->pot_id)] = self::toInt($row->movement_count);
        }

        return $counts;
    }

    /**
     * @param  array<int|string, mixed>  $potNameById
     * @param  array<string, int>  $spendByCategory
     * @param  array<int, int>  $movementCounts
     */
    private function buildPotRow(stdClass $pot, User $user, ConnectionInterface $connection, array $potNameById, array $spendByCategory, array $movementCounts): PotRow
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
        $spent = $this->categorySpent($pot, $categoryId, $spendByCategory);

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
            categoryName: CategoryPathName::fromRow($pot),
            categorySpentMinor: $spent === null ? null : $spent['minor'],
            categorySpentUnconverted: $spent === null ? [] : $spent['unconverted'],
            recentMovements: $this->buildRecentMovements($movementRows, $potNameById),
            movementCount: $movementCounts[$potId] ?? 0,
        );
    }

    // tryFrom, never from: `pot_movements.kind` has no CHECK, a peer on a newer
    // build writes its own spelling straight through the op log, and a raise
    // here took the whole /pots page down on the older device.
    /**
     * @param  iterable<mixed>  $movementRows
     * @param  array<int|string, mixed>  $potNameById
     * @return list<PotMovementRow>
     *
     * @link ../../../../.docs/features/sync/a-peer-may-be-on-a-newer-version.md
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
                kind: PotMovementKind::tryFrom(self::toString($m->kind)),
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

    // Bucketed by settled currency and converted into the pot's, never filtered
    // to it: a card denominated elsewhere dropped out of a line sitting beside a
    // balance that counted everything. The buckets are the ledger's own, so the
    // line and the budgets grid one screen away answer the same question.
    /**
     * @param  array<string, int>  $spendByCategory  "categoryId|currency" => spend minor
     * @return array{minor: int, unconverted: list<string>}|null
     */
    private function categorySpent(stdClass $pot, ?int $categoryId, array $spendByCategory): ?array
    {
        if ($categoryId === null) {
            return null;
        }

        $byCurrency = [];
        foreach ($spendByCategory as $key => $spentMinor) {
            [$rowCategoryId, $currency] = explode('|', self::toString($key), 2) + [1 => ''];
            if ((int) $rowCategoryId !== $categoryId || $currency === '') {
                continue;
            }
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0) + $spentMinor;
        }

        $converted = $this->fx->of($byCurrency, self::toString($pot->currency));

        return ['minor' => $converted->minor, 'unconverted' => $converted->unconverted];
    }
}
