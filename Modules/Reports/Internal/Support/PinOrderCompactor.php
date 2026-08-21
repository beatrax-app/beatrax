<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

use Illuminate\Database\ConnectionInterface;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;

// Callers must run this inside the same transaction as the mutation that
// changed the pinned set.
final class PinOrderCompactor
{
    use CoercesScalars;

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
}
