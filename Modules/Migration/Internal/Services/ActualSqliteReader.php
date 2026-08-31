<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Services;

use Generator;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Migration\Internal\Enums\ActualBudgetType;
use Modules\Migration\Internal\Exceptions\ActualSqliteReadException;
use Modules\Migration\Internal\Exceptions\UnrecognizedActualBudgetTypeException;
use Modules\Migration\Internal\Parsers\Support\BoundedJson;
use Modules\Migration\Internal\Services\Concerns\SummarizesRuleConditions;
use PDO;
use Pdo\Sqlite as PdoSqlite;
use PDOStatement;

final readonly class ActualSqliteReader
{
    use CoercesScalars;
    use SummarizesRuleConditions;

    // Actual's own out-of-the-box mode, and what an export that names none is
    // read as. The parser surfaces the assumption rather than burying it.
    public const DEFAULT_BUDGET_TYPE = ActualBudgetType::Envelope;

    private PdoSqlite $pdo;

    public function __construct(string $dbPath)
    {
        if (! is_file($dbPath)) {
            throw new ActualSqliteReadException("Actual SQLite file not found at '{$dbPath}'");
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
                'id' => self::toString($row['id']),
                'name' => self::toString($row['name']),
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
            throw new ActualSqliteReadException('could not query categories/v_categories');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'id' => self::toString($row['id']),
                'name' => self::toString($row['name']),
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
            throw new ActualSqliteReadException('could not query accounts');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = ['id' => self::toString($row['id']), 'name' => self::toString($row['name'])];
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
            throw new ActualSqliteReadException('could not query payees/v_payees');
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'id' => self::toString($row['id']),
                'name' => self::toString($row['name']),
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
            throw new ActualSqliteReadException('could not query transactions/v_transactions');
        }

        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            yield [
                'id' => self::toString($row['id']),
                'is_parent' => self::toBool($row['is_parent']),
                'is_child' => self::toBool($row['is_child']),
                'parent_id' => self::toNullableStr($row['parent_id']),
                'account' => self::toString($row['account']),
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

    // The mode the file states, or null when it states none — real exports
    // routinely omit the row, and refusing those rejected the whole file.
    public function declaredBudgetType(): ?ActualBudgetType
    {
        $value = $this->preference('budgetType');
        if ($value === null) {
            return null;
        }

        return ActualBudgetType::fromPreference($value)
            ?? throw new UnrecognizedActualBudgetTypeException("unrecognized Actual preferences.budgetType value '{$value}'");
    }

    public function budgetType(): ActualBudgetType
    {
        return $this->declaredBudgetType() ?? self::DEFAULT_BUDGET_TYPE;
    }

    /**
     * @return list<array{category: string, month: int, amount: int}>
     */
    public function budgetAssignments(): array
    {
        $table = $this->budgetType()->budgetTable();

        if (! $this->tableExists($table)) {
            // The ACTIVE mode's own table is missing, so this export genuinely
            // has no budget history — it is not a wrong-mode read.
            return [];
        }

        $stmt = $this->pdo->query("SELECT category, month, amount FROM {$table}");
        if ($stmt === false) {
            throw new ActualSqliteReadException("could not query {$table}");
        }

        $rows = [];
        while (($row = $this->fetchAssocRow($stmt)) !== null) {
            $rows[] = [
                'category' => self::toString($row['category']),
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
                'id' => self::toString($row['id']),
                'name' => self::toNullableStr($row['name']),
                'conditionsSummary' => $this->summarizeConditions(BoundedJson::decode($conditionsJson)),
            ];
        }

        return $rows;
    }

    /**
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
            $rows[] = ['category_id' => self::toString($row['id']), 'goal_def' => $goalDef];
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
            $rows[] = ['id' => self::toString($row['id']), 'name' => self::toString($row['name'])];
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
            throw new ActualSqliteReadException('unexpected non-array row from PDOStatement::fetch(PDO::FETCH_ASSOC)');
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    private static function toNullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::toString($value);
    }

    private static function toBool(mixed $value): bool
    {
        return is_numeric($value) ? (int) $value !== 0 : (bool) $value;
    }
}
