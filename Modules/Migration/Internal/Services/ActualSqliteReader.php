<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services;

use Generator;
use JsonException;
use PDO;
use Pdo\Sqlite as PdoSqlite;
use PDOStatement;
use RuntimeException;

/**
 * Read-only wrapper over an extracted Actual Budget `db.sqlite` file.
 *
 * NOVEL PATTERN — no prior art in this codebase (13.5-PATTERNS.md Section 7).
 * Every other raw-SQL read service in this project (`GoalProgressQuery`,
 * `CategorizationRuleQuery`, ...) injects the app's own
 * `Illuminate\Database\DatabaseManager` and reads the SAME SQLite database
 * the app already writes to. This class deliberately does the OPPOSITE: it
 * opens a WHOLLY SEPARATE `\Pdo\Sqlite` connection to an untrusted, extracted
 * export file using `\Pdo\Sqlite::ATTR_OPEN_FLAGS => \Pdo\Sqlite::OPEN_READONLY`
 * — PHP 8.4+'s dedicated driver subclass (NOT the deprecated base-`PDO::SQLITE_*`
 * constants, which still work on PHP 8.5 but emit a deprecation notice). A
 * write attempt through this handle throws
 * `PDOException: ... attempt to write a readonly database` — verified
 * directly on this project's PHP 8.5.7 runtime (13.5-RESEARCH.md Code
 * Examples).
 *
 * This file NEVER joins the extracted database into the app's own
 * `DatabaseManager` connection via SQLite's cross-database-join SQL keyword
 * (Pitfall 6 / T-13.5-09) — doing so would share the app's single-writer WAL
 * connection state with an untrusted file and blur the "never write back"
 * boundary this whole class exists to enforce. A repo-wide grep for that
 * keyword must always return zero hits in this file.
 *
 * Reads go through Actual's own resolved `v_transactions`/`v_categories`/
 * `v_payees` VIEWS, never the raw tables (Pitfall 1) — the raw
 * `transactions.category`/`.payee` columns carry stale pre-merge ids that
 * only `category_mapping`/`payee_mapping` (already joined inside the views)
 * resolve correctly, and the raw tables carry tombstoned/dead-child rows the
 * views already filter out. If a very old export predates the views,
 * `viewExists()` gates a fallback that replicates the exact join+filter by
 * hand.
 *
 * Every query is parameterized (PDO prepared statements) or interpolates
 * only a fixed, code-controlled table-name literal (never source-file
 * content) — source-file content (payee names, notes, JSON blobs) is never
 * string-interpolated into SQL (T-13.5-11).
 *
 * `transactions()` is a `Generator` that streams rows via repeated
 * `PDOStatement::fetch()` calls (never `fetchAll()`), so a multi-year export
 * does not materialize every row in PHP memory at once.
 *
 * `PDOStatement::fetch()`'s return type is untyped (`mixed`) in PHPStan's
 * stubs regardless of fetch-mode argument, so every row is narrowed through
 * `fetchAssocRow()` (an `is_array()` runtime check) and every scalar column
 * through the `toStr()`/`toInt()`/`toBool()` coercers below — the identical
 * discipline `Modules\Goals\Public\Services\GoalProgressQuery` already
 * established for raw `DatabaseManager` reads (`stdClass` columns are just
 * as untyped as this class's array rows).
 */
final class ActualSqliteReader
{
    private const MAX_JSON_BYTES = 65536;

    private const MAX_JSON_DEPTH = 20;

    private readonly PdoSqlite $pdo;

    public function __construct(string $dbPath)
    {
        if (! is_file($dbPath)) {
            throw new RuntimeException("Actual SQLite file not found at '{$dbPath}'");
        }

        $this->pdo = new PdoSqlite(
            'sqlite:'.$dbPath,
            null,
            null,
            [PdoSqlite::ATTR_OPEN_FLAGS => PdoSqlite::OPEN_READONLY],
        );
    }

    /**
     * @return list<array{id: string, name: string, is_income: bool, hidden: bool}>
     */
    public function categoryGroups(): array
    {
        $stmt = $this->pdo->query('SELECT id, name, is_income, hidden FROM category_groups WHERE tombstone = 0');
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'id' => self::toStr($row['id']),
                'name' => self::toStr($row['name']),
                'is_income' => self::toBool($row['is_income']),
                'hidden' => self::toBool($row['hidden']),
            ];
        }

        return $rows;
    }

    /**
     * Reads via `v_categories` (Pitfall 1) — falls back to the raw table
     * (tombstone-filtered) only when the view is absent (a very old export).
     *
     * @return list<array{id: string, name: string, is_income: bool, group: ?string, goal_def: ?string}>
     */
    public function categories(): array
    {
        $sql = $this->viewExists('v_categories')
            ? 'SELECT id, name, is_income, "group", goal_def FROM v_categories'
            : 'SELECT id, name, is_income, "group", goal_def FROM categories WHERE tombstone = 0';

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('could not query categories/v_categories');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'id' => self::toStr($row['id']),
                'name' => self::toStr($row['name']),
                'is_income' => self::toBool($row['is_income']),
                'group' => self::toNullableStr($row['group']),
                'goal_def' => self::toNullableStr($row['goal_def']),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    public function accounts(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM accounts WHERE tombstone = 0');
        if ($stmt === false) {
            throw new RuntimeException('could not query accounts');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = ['id' => self::toStr($row['id']), 'name' => self::toStr($row['name'])];
        }

        return $rows;
    }

    /**
     * Reads via `v_payees` (Pitfall 1); falls back to the raw table when the
     * view is absent. `transfer_acct` non-null identifies a transfer's
     * synthetic "payee" — never a real merchant/payee.
     *
     * @return list<array{id: string, name: string, transfer_acct: ?string}>
     */
    public function payees(): array
    {
        $sql = $this->viewExists('v_payees')
            ? 'SELECT id, name, transfer_acct FROM v_payees'
            : 'SELECT id, name, transfer_acct FROM payees WHERE tombstone = 0';

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('could not query payees/v_payees');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'id' => self::toStr($row['id']),
                'name' => self::toStr($row['name']),
                'transfer_acct' => self::toNullableStr($row['transfer_acct']),
            ];
        }

        return $rows;
    }

    /**
     * Streams transactions via `v_transactions` (Pitfall 1) — a mapping-
     * resolved, tombstone-filtered read, chunked via repeated `fetch()`
     * calls rather than `fetchAll()` so a multi-year export never
     * materializes wholly in memory inside this method. Falls back to a
     * hand-replicated join+filter when the view is absent.
     *
     * @return Generator<int, array{id: string, is_parent: bool, is_child: bool, parent_id: ?string, account: string, category: ?string, amount: int, payee: ?string, notes: ?string, date: int, transfer_id: ?string, cleared: bool, reconciled: bool}>
     */
    public function transactions(): Generator
    {
        $sql = $this->viewExists('v_transactions')
            ? 'SELECT id, is_parent, is_child, parent_id, account, category, amount, payee, notes, date, transfer_id, cleared, reconciled FROM v_transactions ORDER BY date, id'
            : $this->rawTransactionsFallbackSql();

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('could not query transactions/v_transactions');
        }

        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            yield [
                'id' => self::toStr($row['id']),
                'is_parent' => self::toBool($row['is_parent']),
                'is_child' => self::toBool($row['is_child']),
                'parent_id' => self::toNullableStr($row['parent_id']),
                'account' => self::toStr($row['account']),
                'category' => self::toNullableStr($row['category']),
                'amount' => self::toInt($row['amount']),
                'payee' => self::toNullableStr($row['payee']),
                'notes' => self::toNullableStr($row['notes']),
                'date' => self::toInt($row['date']),
                'transfer_id' => self::toNullableStr($row['transfer_id']),
                'cleared' => self::toBool($row['cleared']),
                'reconciled' => self::toBool($row['reconciled']),
            ];
        }
    }

    /**
     * Reads `preferences` row `id='budgetType'`, normalising the legacy
     * pre-migration values (`'rollover'`→envelope, `'report'`→tracking —
     * Assumption A5) onto the current `'envelope'|'tracking'` pair.
     *
     * @return 'envelope'|'tracking'
     */
    public function budgetType(): string
    {
        $value = $this->preference('budgetType');
        if ($value === null) {
            throw new RuntimeException("Actual export has no 'preferences.budgetType' row — cannot determine envelope vs tracking mode");
        }

        return match ($value) {
            'envelope', 'rollover' => 'envelope',
            'tracking', 'report' => 'tracking',
            default => throw new RuntimeException("unrecognized Actual preferences.budgetType value '{$value}'"),
        };
    }

    /**
     * Branches on `budgetType()` — `'envelope'` reads `zero_budgets`,
     * `'tracking'` reads `reflect_budgets` (Pitfall 7). An empty/absent
     * result from the WRONG table (the file's other, inactive mode) is never
     * reported as "no budget history" — this method only ever queries the
     * table matching the file's OWN active mode.
     *
     * @return list<array{category: string, month: int, amount: int}>
     */
    public function budgetAssignments(): array
    {
        $table = $this->budgetType() === 'envelope' ? 'zero_budgets' : 'reflect_budgets';

        if (! $this->tableExists($table)) {
            // The active mode's own table is missing entirely — genuinely
            // no budget history in this export, not "wrong mode" (Pitfall 7
            // only concerns the INACTIVE table's emptiness/absence).
            return [];
        }

        $stmt = $this->pdo->query("SELECT category, month, amount FROM {$table}");
        if ($stmt === false) {
            throw new RuntimeException("could not query {$table}");
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'category' => self::toStr($row['category']),
                'month' => self::toInt($row['month']),
                'amount' => self::toInt($row['amount']),
            ];
        }

        return $rows;
    }

    /**
     * Budget-file-level currency (Open Question 2, resolved): reads
     * `preferences` row `id='currencyCode'`. Returns null when absent — the
     * caller (`ActualParser`) falls back to the user's base currency and
     * records an "assumed {currency}" `UnmappedItemDto`.
     */
    public function currency(): ?string
    {
        return $this->preference('currencyCode');
    }

    /**
     * Joins `schedules` to their `rules` row, decoding (size/depth-bounded,
     * T-13.5-10) the rule's `conditions` JSON into a one-line human-readable
     * summary the caller uses to build a `MigrationScheduleDto` note.
     *
     * @return list<array{id: string, name: ?string, conditionsSummary: string}>
     */
    public function schedulesWithRules(): array
    {
        $sql = <<<'SQL'
            SELECT s.id, s.name, r.conditions
            FROM schedules s
            JOIN rules r ON r.id = s.rule
            WHERE s.tombstone = 0 AND r.tombstone = 0
            SQL;

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $conditionsJson = self::toNullableStr($row['conditions']) ?? '';
            $rows[] = [
                'id' => self::toStr($row['id']),
                'name' => self::toNullableStr($row['name']),
                'conditionsSummary' => $this->summarizeConditions($this->boundedJsonDecode($conditionsJson)),
            ];
        }

        return $rows;
    }

    /**
     * Every category row carrying a non-null `goal_def` (Open Question 3) —
     * returns the RAW JSON string; decoding (size/depth-bounded, T-13.5-10)
     * and interpreting the flat-vs-non-flat grammar is
     * `ActualGoalDefInterpreter`'s job, not this reader's.
     *
     * @return list<array{category_id: string, goal_def: string}>
     */
    public function goalDefs(): array
    {
        $sql = $this->viewExists('v_categories')
            ? 'SELECT id, goal_def FROM v_categories WHERE goal_def IS NOT NULL'
            : 'SELECT id, goal_def FROM categories WHERE tombstone = 0 AND goal_def IS NOT NULL';

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $goalDef = self::toNullableStr($row['goal_def']);
            if ($goalDef === null || $goalDef === '') {
                continue;
            }
            $rows[] = ['category_id' => self::toStr($row['id']), 'goal_def' => $goalDef];
        }

        return $rows;
    }

    /**
     * A synthetic "saved report" extra with no beatrax equivalent (Req 8) —
     * every row surfaces as an `UnmappedItemDto('extra')`, never silently
     * dropped.
     *
     * @return list<array{id: string, name: string}>
     */
    public function customReports(): array
    {
        if (! $this->tableExists('custom_reports')) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT id, name FROM custom_reports WHERE tombstone = 0');
        if ($stmt === false) {
            return [];
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = ['id' => self::toStr($row['id']), 'name' => self::toStr($row['name'])];
        }

        return $rows;
    }

    private function preference(string $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM preferences WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $value = $stmt->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function viewExists(string $viewName): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'view' AND name = :name");
        $stmt->execute(['name' => $viewName]);

        return $stmt->fetchColumn() !== false;
    }

    private function tableExists(string $tableName): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute(['name' => $tableName]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Replicates `v_transactions`' own join+filter by hand for a very old
     * export predating the view (Pitfall 1's documented fallback path).
     */
    private function rawTransactionsFallbackSql(): string
    {
        return <<<'SQL'
            SELECT t.id, t.is_parent, t.is_child, t.parent_id, t.account,
                   COALESCE(cm."transferId", t.category) AS category,
                   t.amount,
                   COALESCE(pm."targetId", t.payee) AS payee,
                   t.notes, t.date, t.transfer_id, t.cleared, t.reconciled
            FROM transactions t
            LEFT JOIN category_mapping cm ON cm.id = t.category
            LEFT JOIN payee_mapping pm ON pm.id = t.payee
            WHERE t.tombstone = 0
              AND (t.is_child = 0 OR NOT EXISTS (
                  SELECT 1 FROM transactions parent
                  WHERE parent.id = t.parent_id AND parent.tombstone = 1
              ))
            ORDER BY t.date, t.id
            SQL;
    }

    /**
     * Bounds a `json_decode()` of untrusted source-blob content (a rule's
     * `conditions` column) by byte size and nesting depth before decoding
     * (T-13.5-10, Denial of Service) — returns null on an oversized,
     * malformed, or empty blob rather than throwing.
     */
    private function boundedJsonDecode(string $json): mixed
    {
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Builds a one-line human-readable summary of a rule's decoded
     * `conditions` JSON for the schedule note (`Modules\Recurring` note-only
     * descope, Open Question 4) — never a machine-parseable re-import
     * format.
     */
    private function summarizeConditions(mixed $decoded): string
    {
        $list = null;
        if (is_array($decoded) && isset($decoded['conditions']) && is_array($decoded['conditions'])) {
            $list = $decoded['conditions'];
        } elseif (is_array($decoded) && array_is_list($decoded)) {
            $list = $decoded;
        }

        if ($list === null || $list === []) {
            return 'no rule conditions recorded';
        }

        $parts = [];
        foreach ($list as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $field = is_string($condition['field'] ?? null) ? $condition['field'] : '?';
            $op = is_string($condition['op'] ?? null) ? $condition['op'] : '?';
            $value = $condition['value'] ?? '?';
            $valueStr = is_scalar($value) ? (string) $value : '?';
            $parts[] = "{$field} {$op} {$valueStr}";
        }

        return $parts === [] ? 'no rule conditions recorded' : implode('; ', $parts);
    }

    /**
     * Narrows a `PDOStatement::fetch()` result (untyped `mixed` in PHPStan's
     * stubs, regardless of fetch-mode argument) to a genuine associative
     * array via a runtime `is_array()` check, or null at end-of-results.
     *
     * @return array<string, mixed>|null
     */
    private function fetchAssocRow(PDOStatement $stmt): ?array
    {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if (! is_array($row)) {
            throw new RuntimeException('unexpected non-array row from PDOStatement::fetch(PDO::FETCH_ASSOC)');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    private static function toStr(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    private static function toNullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::toStr($value);
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toBool(mixed $value): bool
    {
        return is_numeric($value) ? (int) $value !== 0 : (bool) $value;
    }
}
