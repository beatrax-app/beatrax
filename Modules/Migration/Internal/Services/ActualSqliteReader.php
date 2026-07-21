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
 * @link ../../../../.docs/features/migration/architecture.md
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
     * @return list<array{category: string, month: int, amount: int}>
     */
    public function budgetAssignments(): array
    {
        $table = $this->budgetType() === 'envelope' ? 'zero_budgets' : 'reflect_budgets';

        if (! $this->tableExists($table)) {
            // The active mode's own table is missing entirely — genuinely no
            // budget history in this export, not "wrong mode".
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

    public function currency(): ?string
    {
        return $this->preference('currencyCode');
    }

    /**
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
     * @return list<array{category_id: string, goal_def: string}>
     */
    public function goalDefs(): array
    {
        // Returns the RAW goal_def JSON string per category — decoding and
        // interpreting the flat-vs-non-flat grammar is
        // ActualGoalDefInterpreter's job, not this reader's.
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

    private function boundedJsonDecode(string $json): mixed
    {
        // Bounds decoding of untrusted source-blob content by byte size and
        // nesting depth — returns null on an oversized, malformed, or empty
        // blob rather than throwing.
        if ($json === '' || strlen($json) > self::MAX_JSON_BYTES) {
            return null;
        }

        try {
            return json_decode($json, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

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
