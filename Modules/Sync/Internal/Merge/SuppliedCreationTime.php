<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Throwable;

// A row that arrives without a birth time is written without one, and nothing
// afterwards gives it back. The lists that order by created_at put a null last
// however new the row is, and past their limit drop it from the page entirely —
// six envelope moves on a paired desktop sat under moves a week older.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SuppliedCreationTime
{
    /** @var array<string, bool> */
    private array $hasColumn = [];

    public function __construct(private readonly DatabaseManager $db) {}

    // The op's own HLC, whose high half is a wall clock in milliseconds: not
    // the birth time the writer knew, but the earliest moment this device can
    // prove the row existed, which is the honest answer and an orderable one.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, list<OpLogEntry>>  $fields
     * @return array<string, mixed>
     */
    public function seed(string $table, array $payload, array $fields): array
    {
        $earliest = self::earliestOf($fields);

        if ($earliest === null) {
            return $payload;
        }

        foreach (['created_at', 'updated_at'] as $column) {
            if (($payload[$column] ?? null) === null && $this->has($table, $column)) {
                $payload[$column] = CarbonImmutable::createFromTimestampMs($earliest)->toDateTimeString();
            }
        }

        return $payload;
    }

    // The value seed() would write for these ops, so a later half of the same
    // create can recognise a birth time this device invented rather than read
    // it as a second row wearing the same id.
    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    public static function seededValueFor(array $fields): ?string
    {
        $earliest = self::earliestOf($fields);

        return $earliest === null
            ? null
            : CarbonImmutable::createFromTimestampMs($earliest)->toDateTimeString();
    }

    /**
     * @param  array<string, list<OpLogEntry>>  $fields
     */
    private static function earliestOf(array $fields): ?int
    {
        $earliest = null;

        foreach ($fields as $entries) {
            foreach ($entries as $entry) {
                if ($earliest === null || $entry->hlcL < $earliest) {
                    $earliest = $entry->hlcL;
                }
            }
        }

        return $earliest !== null && $earliest > 0 ? $earliest : null;
    }

    // Memoised for the life of one replay, the window in which the schema
    // cannot change; a table the schema cannot answer for is left alone.
    private function has(string $table, string $column): bool
    {
        try {
            return $this->hasColumn[$table.'.'.$column] ??= $this->db->connection()
                ->getSchemaBuilder()
                ->hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }
}
