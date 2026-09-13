<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Exceptions\ColumnNotDeclaredException;
use Modules\Core\Public\Support\SchemaShape;
use Modules\Sync\Internal\OpLog\OpType;

// What the durable log says a peer wrote, and which row here answers for it.
// Split from the census that reads it so that "where the peer's row landed" has
// one answer: a repair recording an alias has to match on the same payload the
// census judged the row by, and two rebuilds of it are two answers.
final readonly class PeerAuthoredRows
{
    public function __construct(
        private DatabaseManager $db,
        private PeerRowAliases $aliases,
        private RowOwnership $ownership,
    ) {}

    // Asked before the filter reads it. An absent self_retired_at is parsed as
    // a string literal, which makes whereNull always false and answers "no
    // peers wrote anything" -- a repair that then finds nothing to do, quietly.
    /**
     * @return list<string>
     *
     * @throws ColumnNotDeclaredException
     */
    public function devices(int $userId): array
    {
        $connection = $this->db->connection();
        $missing = SchemaShape::missingColumns($connection, 'device_registry', ['self_retired_at']);

        if ($missing !== []) {
            throw ColumnNotDeclaredException::on('device_registry', $missing);
        }

        return $this->textColumn($connection->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->whereNull('self_retired_at')
            ->orderBy('device_id')
            ->pluck('device_id'));
    }

    // `users` is left out for the reason the stranded census leaves it out: the
    // applier never addresses that row by the wire pk.
    /**
     * @return list<string>
     */
    public function tables(string $deviceId, int $userId): array
    {
        $names = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('device_id', $deviceId)
            ->where('op_type', OpType::CreateRow->value)
            ->groupBy('table_name')
            ->orderBy('table_name')
            ->pluck('table_name');

        $tables = [];

        foreach ($this->textColumn($names) as $table) {
            if (! $this->ownership->isSelfScoped($table)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    public function pks(string $table, string $deviceId, int $userId): array
    {
        return $this->textColumn($this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('device_id', $deviceId)
            ->where('op_type', OpType::CreateRow->value)
            ->groupBy('pk')
            ->pluck('pk'));
    }

    // The create's fields with later Sets over them, which is the value each
    // column would hold had every op landed.
    /**
     * @return array<string, mixed>
     */
    public function payload(string $table, string $pk, string $deviceId, int $userId): array
    {
        $rows = $this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('pk', $pk)
            ->where('device_id', $deviceId)
            ->whereIn('op_type', [OpType::CreateRow->value, OpType::Set->value])
            ->orderBy('hlc_l')->orderBy('hlc_c')->orderBy('id')
            ->get(['field', 'value']);

        $payload = [];

        foreach ($rows as $row) {
            $field = is_string($row->field ?? null) ? $row->field : '';

            if ($field !== '') {
                $payload[$field] = is_string($row->value ?? null) ? json_decode($row->value, true) : null;
            }
        }

        return $payload === [] ? [] : [...$payload, 'id' => $pk, 'user_id' => $userId];
    }

    // The local row the peer's create was placed on: the aliased one where the
    // applier re-homed it, the peer's own number where it did not. Null where
    // no row of this reader answers at all, which is the other census's subject.
    public function landedId(string $table, string $deviceId, string $pk, int $userId): ?string
    {
        $local = $this->aliases->localFor($table, $deviceId, $pk, $userId) ?? $pk;

        return $this->ownedHere($table, $local, $userId) ? $local : null;
    }

    public function ownedHere(string $table, string $id, int $userId): bool
    {
        return $this->ownership
            ->scopeToUser($this->db->connection()->table($table)->where('id', $id), $table, $userId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function storedRow(string $table, string $localId): array
    {
        $row = $this->db->connection()->table($table)->where('id', $localId)->first();
        /** @var array<string, mixed> $columns */
        $columns = $row === null ? [] : get_object_vars($row);

        return $columns;
    }

    // The devices other than this peer that wrote the peer's value into the
    // same column of the row at the same pk.
    /**
     * @return list<string>
     */
    public function otherAuthorsOf(PeerParentColumn $at, int $userId): array
    {
        return $this->textColumn($this->db->connection()->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $at->table)
            ->where('pk', $at->peerPk)
            ->where('field', $at->column)
            ->where('device_id', '!=', $at->deviceId)
            ->whereIn('value', [json_encode($at->peerValue), json_encode((int) $at->peerValue)])
            ->groupBy('device_id')
            ->pluck('device_id'));
    }

    // A pk is a varchar in the log and an integer in the table it names, so
    // both ends are read as text before they are compared.
    /**
     * @param  Collection<int, mixed>  $values
     * @return list<string>
     */
    private function textColumn(Collection $values): array
    {
        $text = [];

        foreach ($values as $value) {
            if (is_string($value) || is_numeric($value)) {
                $text[] = (string) $value;
            }
        }

        return $text;
    }
}
