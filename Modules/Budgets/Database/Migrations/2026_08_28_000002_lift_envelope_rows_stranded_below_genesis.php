<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Services\PeriodQuery;

// The old re-key mapped a stored row by the old period's FIRST instant, which
// under any later start day lands one period earlier -- below the genesis an
// upgrader's fold walks from, where no read and no month-back nav reaches it
// again. This lifts exactly those rows back onto genesis.
/**
 * @link ../../../../.docs/features/budgets/moving-the-budget-month.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        /** @var PeriodQuery $periods */
        $periods = Container::getInstance()->make(PeriodQuery::class);
        $connection = $this->db()->connection($this->getConnection());

        $users = $connection->table('users')
            ->whereNotNull('envelope_activated_at')
            ->get(['id', 'period_start_day', 'envelope_activated_at']);

        foreach ($users as $user) {
            $activatedAt = SafeDate::parseOrNull(is_string($user->envelope_activated_at) ? $user->envelope_activated_at : '');
            if (! $activatedAt instanceof CarbonImmutable) {
                continue;
            }

            $userId = (int) $user->id;
            $genesis = $periods->containingForDay((int) $user->period_start_day, $activatedAt)->start;

            // The old mapping could only ever be one period out: the stored key
            // and the anchor sat in the same old period, so the new windows they
            // fall in are at most one apart. Anything earlier was already below
            // genesis before the move and is left where the reader put it.
            $floor = $genesis->subMonthNoOverflow()->toDateString();
            $genesisKey = $genesis->toDateString();

            $this->liftAssignments($connection, $userId, $floor, $genesisKey);

            $connection->table('envelope_moves')
                ->where('user_id', $userId)
                ->where('period_start', '>=', $floor)
                ->where('period_start', '<', $genesisKey)
                ->update(['period_start' => $genesisKey]);
        }
    }

    public function down(): void
    {
        // Forward-only: the day the rows were stranded under was never recorded,
        // so there is nothing to put them back to.
    }

    // (user_id, category_id, period_start) is UNIQUE, so a stranded row whose
    // envelope already has a genesis row merges into it rather than colliding.
    private function liftAssignments(Connection $connection, int $userId, string $floor, string $genesisKey): void
    {
        $stranded = $connection->table('envelope_assignments')
            ->where('user_id', $userId)
            ->where('period_start', '>=', $floor)
            ->where('period_start', '<', $genesisKey)
            ->get(['id', 'category_id', 'assigned_minor']);

        foreach ($stranded as $row) {
            $existingId = $connection->table('envelope_assignments')
                ->where('user_id', $userId)
                ->where('category_id', $row->category_id)
                ->where('period_start', $genesisKey)
                ->value('id');

            if ($existingId === null) {
                $connection->table('envelope_assignments')
                    ->where('id', $row->id)
                    ->update(['period_start' => $genesisKey]);

                continue;
            }

            $connection->table('envelope_assignments')
                ->where('id', $existingId)
                ->update(['assigned_minor' => $connection->raw('assigned_minor + '.(int) $row->assigned_minor)]);
            $connection->table('envelope_assignments')->where('id', $row->id)->delete();
        }
    }
};
