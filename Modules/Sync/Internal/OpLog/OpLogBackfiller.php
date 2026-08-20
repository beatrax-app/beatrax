<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use RuntimeException;

// Writes the rows that already existed when sync was switched on into the op
// log as CREATE_ROW ops. Capture is event-driven, so a device that was used
// before pairing had an empty log and handed its first peer nothing — the
// phone sat on "0 of 0 records" while the desktop held years of data.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final readonly class OpLogBackfiller
{
    // Rows per SELECT. The log is written entry-by-entry regardless; this
    // only bounds how much of a table is held in memory at once.
    private const CHUNK = 200;

    // Rules stay on the device that authored them, so they are never put on
    // the wire — not in the pairing snapshot and not incrementally. They keep
    // merge rules so a peer can still apply an op from an older build, but
    // nothing here produces one. The rules screen says so.
    private const DEVICE_LOCAL_TABLES = ['categorization_rules', 'rule_conditions', 'rule_actions'];

    // Never emitted as a field: the row's identity travels as the op's pk,
    // and re-stating it invites a create whose pk and user_id disagree.
    private const SKIPPED_COLUMNS = ['id'];

    // Covered tables that carry no user_id of their own. Each is scoped
    // through the parent column named here, whose table does carry one.
    private const PARENT_SCOPE = [
        'rule_conditions' => ['rule_id', 'categorization_rules'],
        'rule_actions' => ['rule_id', 'categorization_rules'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private CoveredTableOrder $order,
        private SensitiveFieldRegistry $sensitiveFields,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    // Returns the number of rows captured. Idempotent row-wise: a row already
    // carrying a create op is skipped. Skipping the whole backfill when the
    // user had ANY entry meant one import between enabling sync and pairing
    // kept every older account, budget, goal and pot off the new peer.
    public function backfill(int $userId, OpLogWriter $writer): int
    {
        $connection = $this->db->connection();

        $captured = 0;

        // Parents first, so the entries a peer replays arrive in an order its
        // foreign keys accept.
        foreach ($this->order->insertionOrder() as $table) {
            if (in_array($table, self::DEVICE_LOCAL_TABLES, true)) {
                continue;
            }

            $captured += $this->captureTable($connection, $table, $userId, $writer);
        }

        return $captured;
    }

    // Capture specific rows as create ops, for writes that happen AFTER the
    // one-time backfill — an import, above all. Shares plaintextFields() with
    // the backfill rather than restating it: a second copy of which columns
    // are ciphertext at rest is the one that would rot.
    /**
     * @param  list<int|string>  $ids
     */
    public function captureRowsById(string $table, array $ids, int $userId, OpLogWriter $writer): int
    {
        if ($ids === []) {
            return 0;
        }

        $connection = $this->db->connection();
        $columns = $this->columnsOf($connection, $table);

        if ($columns === []) {
            return 0;
        }

        $captured = 0;

        $this->scopedQuery($connection, $table, $userId, $columns)
            ->whereIn($table.'.id', $ids)
            ->orderBy($table.'.id')
            ->chunk(self::CHUNK, function ($rows) use ($table, $userId, $writer, &$captured): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed> $fields */
                    $fields = (array) $row;
                    $pk = $fields['id'] ?? null;
                    unset($fields['id']);

                    if (! is_int($pk) && ! is_string($pk)) {
                        continue;
                    }

                    $writer->writeCreateRow($table, $pk, $this->plaintextFields($table, $fields, $userId));
                    $captured++;
                }
            });

        return $captured;
    }

    // Which of THIS chunk's rows already carry a create op, as a lookup keyed
    // by pk. Asked per chunk rather than per table so neither the result set
    // nor the IN list grows with the size of the table.
    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<string, true>
     */
    private function alreadyCaptured(Connection $connection, string $table, int $userId, $rows): array
    {
        $pks = [];
        foreach ($rows as $row) {
            $pk = ((array) $row)['id'] ?? null;
            if (is_int($pk) || is_string($pk)) {
                $pks[] = (string) $pk;
            }
        }

        if ($pks === []) {
            return [];
        }

        $found = $connection->table('op_log_entries')
            ->where('user_id', $userId)
            ->where('table_name', $table)
            ->where('op_type', OpType::CreateRow->value)
            ->whereIn('pk', $pks)
            ->distinct()
            ->pluck('pk');

        $lookup = [];
        foreach ($found as $pk) {
            if (is_int($pk) || is_string($pk)) {
                $lookup[(string) $pk] = true;
            }
        }

        return $lookup;
    }

    private function captureTable(
        Connection $connection,
        string $table,
        int $userId,
        OpLogWriter $writer,
    ): int {
        $columns = $this->columnsOf($connection, $table);

        if ($columns === []) {
            return 0;
        }

        $captured = 0;

        $this->scopedQuery($connection, $table, $userId, $columns)
            ->orderBy($table.'.id')
            ->chunk(self::CHUNK, function ($rows) use ($connection, $table, $userId, $writer, &$captured): void {
                $already = $this->alreadyCaptured($connection, $table, $userId, $rows);

                foreach ($rows as $row) {
                    /** @var array<string, mixed> $fields */
                    $fields = (array) $row;
                    $pk = $fields['id'] ?? null;
                    unset($fields['id']);

                    if (! is_int($pk) && ! is_string($pk)) {
                        continue;
                    }

                    if (isset($already[(string) $pk])) {
                        continue;
                    }

                    $writer->writeCreateRow($table, $pk, $this->plaintextFields($table, $fields, $userId));
                    $captured++;
                }
            });

        return $captured;
    }

    // Sensitive columns are ciphertext AT REST, and OpLogWriter encrypts what
    // it is handed under a DIFFERENT associated data. Passing the stored
    // column straight through wrapped a second layer round the first, and the
    // peer that unwrapped the outer one projected the inner base64 as a name.
    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function plaintextFields(string $table, array $fields, int $userId): array
    {
        $session = ($this->session)();

        foreach ($fields as $field => $value) {
            if (! is_string($value) || ! $this->sensitiveFields->isSensitive($table, $field)) {
                continue;
            }

            $read = $this->codec->decryptValue($table, $field, $value, $userId, $session);

            // `decrypted: false` with the value handed back untouched is the
            // ordinary pre-encryption row; the codec blanking it instead means
            // it held ciphertext no epoch in the keyring opens.
            if (! $read['decrypted'] && $read['value'] !== $value) {
                throw new RuntimeException(
                    "OpLogBackfiller: cannot read {$table}.{$field} for user {$userId} — refusing to capture an unreadable value.",
                );
            }

            $fields[$field] = $read['value'];
        }

        return $fields;
    }

    /**
     * @param  list<string>  $columns
     * @return Builder
     */
    private function scopedQuery(
        Connection $connection,
        string $table,
        int $userId,
        array $columns,
    ) {
        $select = array_map(static fn (string $column): string => $table.'.'.$column, $columns);
        $query = $connection->table($table)->select($select);

        $parent = self::PARENT_SCOPE[$table] ?? null;

        if ($parent === null) {
            return $query->where($table.'.user_id', $userId);
        }

        [$foreignKey, $parentTable] = $parent;

        return $query->whereIn(
            $table.'.'.$foreignKey,
            $connection->table($parentTable)->select('id')->where('user_id', $userId),
        );
    }

    // Empty for a table this build has no schema for — a module whose
    // migrations are not installed still appears in the merge rules, and
    // capturing it would fail the whole backfill on an optional feature.
    /**
     * @return list<string>
     */
    private function columnsOf(Connection $connection, string $table): array
    {
        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return [];
        }

        /** @var list<string> $listing */
        $listing = $schema->getColumnListing($table);
        $columns = array_values(array_diff($listing, self::SKIPPED_COLUMNS));

        // The pk is selected separately from the emitted fields so the
        // capture loop can split it back out without re-querying.
        return $columns === [] ? [] : array_merge(['id'], $columns);
    }
}
