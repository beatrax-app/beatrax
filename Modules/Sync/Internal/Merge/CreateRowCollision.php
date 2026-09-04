<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Throwable;

// Whether a create the primary key refused names a DIFFERENT row than the one
// already sitting at that id. Two devices writing while apart each take the
// next autoincrement, so a table carrying no natural key of its own hands one
// id to two unrelated rows.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class CreateRowCollision
{
    // The applier seeds the first two from the op envelope rather than the
    // wire, and the write time moves whenever anything about the row does.
    private const array NOT_COMPARED = ['id', 'user_id', 'created_at', 'updated_at'];

    public function __construct(
        private DatabaseManager $db,
        private SensitiveFieldRegistry $sensitive,
    ) {}

    // A birth time the stored row does not share, AND some other column that
    // disagrees as well. Either half alone answers wrong: a create replayed
    // after its row was edited differs in the edited column while still being
    // the same row, and a stored created_at of null differs from every peer.
    /**
     * @param  array<string, mixed>  $payload
     */
    public function contradicts(string $table, int|string $pk, array $payload, ?string $seededCreatedAt = null): bool
    {
        $stored = $this->storedRow($table, $pk);

        if ($stored === null || ! $this->birthTimesDisagree($table, $payload, $stored, $seededCreatedAt)) {
            return false;
        }

        return $this->anyOtherColumnDisagrees($table, $payload, $stored);
    }

    // A row whose first half carried no birth time was given one from the op's
    // HLC. That value is this device's invention, so reading it back as the
    // peer's claim turns the REST of the same create into a second row wearing
    // one id.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $stored
     */
    private function birthTimesDisagree(string $table, array $payload, array $stored, ?string $seededCreatedAt): bool
    {
        if ($seededCreatedAt !== null && self::asText($stored['created_at'] ?? null) === $seededCreatedAt) {
            return false;
        }

        return $this->differs($table, 'created_at', $payload, $stored);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $stored
     */
    private function anyOtherColumnDisagrees(string $table, array $payload, array $stored): bool
    {
        foreach (array_keys($payload) as $column) {
            if (! in_array($column, self::NOT_COMPARED, true) && $this->differs($table, $column, $payload, $stored)) {
                return true;
            }
        }

        return false;
    }

    // A sensitive column is re-sealed for this device before the insert, and a
    // fresh nonce makes it differ from the stored ciphertext every time, so
    // comparing the two would call every replay a collision.
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $stored
     */
    private function differs(string $table, string $column, array $payload, array $stored): bool
    {
        $comparable = array_key_exists($column, $payload)
            && array_key_exists($column, $stored)
            && ! $this->sensitive->isSensitive($table, $column);

        if (! $comparable) {
            return false;
        }

        $wire = $payload[$column];
        $here = $stored[$column];

        if ($wire === null || $here === null) {
            return $wire !== $here;
        }

        return self::asText($wire) !== self::asText($here);
    }

    // SQLite answers a boolean column with an int and the wire carries a bool,
    // so the two spellings of one value are read as one.
    private static function asText(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    // A table the schema cannot answer for is not a collision, so a replay
    // that would otherwise continue is never stopped by this question.
    /**
     * @return array<string, mixed>|null
     */
    private function storedRow(string $table, int|string $pk): ?array
    {
        try {
            $row = $this->db->connection()->table($table)->where('id', $pk)->first();
        } catch (Throwable) {
            return null;
        }

        if (! is_object($row)) {
            return null;
        }

        $columns = [];

        foreach (get_object_vars($row) as $column => $value) {
            $columns[(string) $column] = $value;
        }

        return $columns;
    }
}
