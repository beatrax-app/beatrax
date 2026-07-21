<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Illuminate\Database\ConnectionInterface;
use Modules\Core\Models\User;

// Re-numbers a user's remaining pinned saved_reports rows to a dense 1..N
// pin_order sequence, returning only the rows whose order changed. Shared
// by TogglePin and DeleteReport; callers must invoke this inside the same
// DB transaction as the mutation that changed the pinned set.
final class PinOrderCompactor
{
    /**
     * @return list<array{id: int, pin_order: int}>
     */
    public static function compact(ConnectionInterface $connection, User $user): array
    {
        $rows = $connection
            ->table('saved_reports')
            ->where('user_id', $user->id)
            ->where('pinned', true)
            ->orderBy('pin_order')
            ->get(['id', 'pin_order']);

        $changed = [];
        $order = 1;
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $id = self::toInt($row->id);
            $currentOrder = self::toInt($row->pin_order);
            if ($currentOrder !== $order) {
                $connection->table('saved_reports')->where('id', $id)->update(['pin_order' => $order]);
                $changed[] = ['id' => $id, 'pin_order' => $order];
            }
            $order++;
        }

        return $changed;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
