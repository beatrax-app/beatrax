<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

// Which rows this device has already told a peer about. Every announcer that
// walks rows rather than reacting to the insert in front of it has to ask,
// because two of them can reach the same row: the pre-sync walk reads the
// table, and the drain replays a coordinate a keyless process left behind.
/**
 * @link ../../../../.docs/features/sync/pre-sync-history-capture.md#announced-once-whichever-announcer-arrives-first
 */
final readonly class AnnouncedCreates
{
    public function __construct(private DatabaseManager $db) {}

    // How many distinct rows of a table this device has announced, for the
    // audit that counts both sides and reports a shortfall either way.
    public function rowCount(int $userId, string $table): int
    {
        return $this->query($userId, $table)->distinct()->count('pk');
    }

    // Asked per chunk rather than per table so neither the result set nor the
    // IN list grows with the size of the table.
    /**
     * @param  list<string>  $pks
     * @return array<string, true>
     */
    public function among(int $userId, string $table, array $pks): array
    {
        if ($pks === []) {
            return [];
        }

        return self::lookup($this->query($userId, $table)->whereIn('pk', $pks)->distinct()->pluck('pk'));
    }

    // Empty means no create for this row has ever left the device, so a
    // create is what it is still owed. Anything else names the columns that
    // announcement carried, and a column outside it is owed as a Set.
    /**
     * @return array<string, true>
     */
    public function fieldsOf(int $userId, string $table, int|string $pk): array
    {
        return self::lookup(
            $this->query($userId, $table)->where('pk', (string) $pk)->distinct()->pluck('field')
        );
    }

    // The creates this device authored, and no one else's. A peer holds only
    // the devices it paired with itself, so an op signed by a former peer is
    // coverage here and unverifiable there — which left a replaced phone
    // missing every row its predecessor wrote.
    /**
     * @return Builder
     */
    private function query(int $userId, string $table)
    {
        return $this->db->connection()
            ->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->whereIn('device_id', static function (Builder $query) use ($userId): void {
                $query->select('device_id')
                    ->from('device_registry')
                    ->where('user_id', $userId)
                    ->where('is_self', 1);
            });
    }

    /**
     * @param  Collection<int, mixed>  $values
     * @return array<string, true>
     */
    private static function lookup(Collection $values): array
    {
        $lookup = [];

        foreach ($values as $value) {
            if (is_int($value) || is_string($value)) {
                $lookup[(string) $value] = true;
            }
        }

        return $lookup;
    }
}
