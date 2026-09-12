<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Modules\Budgets\Internal\Support\EnvelopeMoveId;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Services\PeriodQuery;

// The lift that ran on 2026-08-28 moved envelope_moves.period_start with an
// UPDATE, and period_start is one of the three values the row's primary key is
// folded from. Every row it touched came out of that upgrade carrying an id its
// own columns no longer derive. This gives those rows the id they should hold.
/**
 * @link ../../../../.docs/features/budgets/moving-the-budget-month.md#the-key-is-inside-the-id-so-the-id-has-to-move-with-it
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

            $genesis = $periods->containingForDay((int) $user->period_start_day, $activatedAt)->start;

            $this->rederive($connection, (int) $user->id, $genesis);
        }
    }

    public function down(): void
    {
        // Forward-only: an id is restored here from the row's own columns, and
        // the number it replaces described a period the row is no longer in.
    }

    // Only the rows the lift can have touched: it wrote every row it moved onto
    // genesis, so a row that was lifted is a row sitting there now whose stored
    // id is the derivation at some key in the window it was lifted out of.
    private function rederive(Connection $connection, int $userId, CarbonImmutable $genesis): void
    {
        $genesisKey = $genesis->toDateString();

        $rows = $connection->table('envelope_moves')
            ->where('user_id', $userId)
            ->where('period_start', $genesisKey)
            ->whereNotNull('move_group_id')
            ->get(['id', 'kind', 'move_group_id']);

        if ($rows->isEmpty()) {
            return;
        }

        $window = self::liftWindow($genesis);

        foreach ($rows as $row) {
            $target = self::derivedMoveId($row, $genesisKey);
            if ($target === null || $target === (int) $row->id) {
                continue;
            }

            if (! self::wasLifted($row, $window)) {
                continue;
            }

            // Two rows folding onto one id are one (move_group_id, kind,
            // period) carrying two amounts, which is a worse problem than a
            // stale id and not one a migration may pick a loser in.
            if ($connection->table('envelope_moves')->where('id', $target)->exists()) {
                continue;
            }

            $connection->table('envelope_moves')->where('id', $row->id)->update(['id' => $target]);
        }
    }

    // A row whose stored id is its own derivation at a key inside the window
    // the lift emptied was put where it is by that UPDATE. An autoincrement
    // from before the id was derived at all matches no key and stays as it is.
    /**
     * @param  list<string>  $window
     */
    private static function wasLifted(stdClass $row, array $window): bool
    {
        foreach ($window as $key) {
            if (self::derivedMoveId($row, $key) === (int) $row->id) {
                return true;
            }
        }

        return false;
    }

    // Every day the lift swept, not only the period start: it selected on a
    // date range, so a stored key anywhere inside it was moved onto genesis.
    /**
     * @return list<string>
     */
    private static function liftWindow(CarbonImmutable $genesis): array
    {
        $keys = [];
        for ($day = $genesis->subMonthNoOverflow(); $day->lessThan($genesis); $day = $day->addDay()) {
            $keys[] = $day->toDateString();
        }

        return $keys;
    }

    // Null where the tuple cannot be formed: move_group_id is nullable because
    // rows predating it exist, and a null there is not an identity.
    private static function derivedMoveId(stdClass $row, string $periodStart): ?int
    {
        $groupId = is_string($row->move_group_id) ? $row->move_group_id : '';
        $kind = is_string($row->kind) ? $row->kind : '';

        return $groupId === '' || $kind === ''
            ? null
            : EnvelopeMoveId::for($groupId, $kind, $periodStart);
    }
};
