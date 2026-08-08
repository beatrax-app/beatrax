<?php

declare(strict_types=1);

namespace Modules\Migration\Tests\Support;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Script-builds a minimal, synthetic Actual Budget export (`db.sqlite` +
 * `metadata.json` packaged into a real ZIP via `ZipArchive`) matching the
 * verified schema in 13.5-RESEARCH.md § Format Schemas — Actual Budget.
 *
 * Unlike the hand-authored YNAB4/nYNAB CSV fixtures (committed as static
 * files), Actual's export is a full relational SQLite database — small
 * enough to script-generate deterministically at test time rather than
 * committing a binary blob to git. `build()` creates ONLY the tables/views
 * `ActualParser` (Plan 04) is documented to read: `accounts`, `payees`,
 * `category_groups`, `categories`, `category_mapping`, `payee_mapping`,
 * `transactions`, `zero_budgets`, `preferences`, `schedules`, `rules`, and
 * `custom_reports` (a synthetic "saved report" extra with no Beatrax
 * equivalent — Req 8's unmapped-not-dropped coverage), plus the resolving
 * views `v_transactions` / `v_categories` / `v_payees` (RESEARCH.md's
 * verified Pitfall 1: the raw tables carry stale pre-merge ids, the views
 * are the only correct read path).
 *
 * Seeds exactly the scenario `ActualParserTest`/`MigrationConfirmTest` pin:
 *  - 2 accounts (Checking, Savings), 2 category groups (Frequent, Income).
 *  - 4 categories: Groceries (FLAT goal_def), Household (no goal),
 *    Salary (income), Emergency Fund (NON-FLAT/template goal_def).
 *  - 5 payees incl. the two synthetic transfer payees
 *    (`transfer_acct`-linked, never surfaced as a real payee).
 *  - 7 raw transaction rows: 1 plain expense, 1 plain income, a 3-row
 *    is_parent/is_child split (2 legs), a transfer_id-linked pair, and 1
 *    TOMBSTONED row (must be filtered by `v_transactions`).
 *  - `zero_budgets` for Jan+Feb 2026 across Groceries/Household, with
 *    `preferences.budgetType = 'envelope'` so `zero_budgets` (not
 *    `reflect_budgets`) is the active grid.
 *  - `preferences.currencyCode = 'USD'` — the Req 7 non-EUR budget-file
 *    currency fixture (YNAB4/nYNAB carry no per-row currency at all, so
 *    this is the ONLY fixture exercising currency preservation).
 *  - 1 schedule + 1 rule (Req 8 note-only descope — becomes an
 *    `UnmappedItemDto` with a preserved note, never a real `Modules\
 *    Recurring` series).
 *  - 1 `custom_reports` row (the saved-report unsupported "extra").
 *
 * `$variant === 'v2'` changes exactly:
 *  - `zero_budgets` Jan 2026 Groceries: 200.00 -> 250.00 (the Req 10
 *    3-way-merge CONFLICT target — a local edit collides with this).
 *  - `zero_budgets` Jan 2026 Household: 100.00 -> 120.00 (the Req 10
 *    clean-APPLY target — untouched locally).
 *  - The plain-expense transaction amount: 45.00 -> 50.00 (Req 9
 *    idempotent-reimport / Req 10 transaction-field-change coverage).
 * Every other row is byte-identical between v1 and v2.
 */
final class ActualFixtureBuilder
{
    public const BUDGET_FILE_CURRENCY = 'USD';

    /**
     * Builds the fixture ZIP at `$zipPath` (overwritten if it already
     * exists). `$variant` is `'v1'` or `'v2'`.
     */
    public static function build(string $zipPath, string $variant = 'v1'): void
    {
        if (! in_array($variant, ['v1', 'v2'], true)) {
            throw new RuntimeException("Unknown ActualFixtureBuilder variant: {$variant}");
        }

        $workDir = sys_get_temp_dir().'/actual-fixture-build-'.uniqid('', true);
        mkdir($workDir, 0755, true);

        $dbPath = $workDir.'/db.sqlite';
        $metadataPath = $workDir.'/metadata.json';

        try {
            self::buildDatabase($dbPath, $variant);
            file_put_contents($metadataPath, json_encode([
                'id' => 'beatrax-fixture-budget-id',
                'budgetName' => 'Beatrax Test Actual Budget',
                'budgetVersion' => 26,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            if (file_exists($zipPath)) {
                unlink($zipPath);
            }

            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException("Could not create zip at {$zipPath}");
            }
            $zip->addFile($dbPath, 'db.sqlite');
            $zip->addFile($metadataPath, 'metadata.json');
            $zip->close();
        } finally {
            @unlink($dbPath);
            @unlink($metadataPath);
            @rmdir($workDir);
        }
    }

    private static function buildDatabase(string $dbPath, string $variant): void
    {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }

        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::createSchema($pdo);
        self::seed($pdo, $variant);
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE accounts (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                offbudget INTEGER NOT NULL DEFAULT 0,
                closed INTEGER NOT NULL DEFAULT 0,
                sort_order REAL,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE category_groups (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                is_income INTEGER NOT NULL DEFAULT 0,
                hidden INTEGER NOT NULL DEFAULT 0,
                sort_order REAL,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE categories (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                is_income INTEGER NOT NULL DEFAULT 0,
                hidden INTEGER NOT NULL DEFAULT 0,
                "group" TEXT,
                goal_def TEXT,
                cleanup_def TEXT,
                template_settings TEXT,
                sort_order REAL,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        // Actual's merge-categories/merge-payees indirection tables — the
        // fixture uses an identity mapping (every id maps to itself) since
        // no merge ever happened in this synthetic budget, but the tables
        // must exist and be readable by the exact join the views perform.
        $pdo->exec('CREATE TABLE category_mapping (id TEXT PRIMARY KEY, "transferId" TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE payee_mapping (id TEXT PRIMARY KEY, "targetId" TEXT NOT NULL)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE payees (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                transfer_acct TEXT,
                tombstone INTEGER NOT NULL DEFAULT 0,
                favorite INTEGER NOT NULL DEFAULT 0,
                learn_categories INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE transactions (
                id TEXT PRIMARY KEY,
                is_parent INTEGER NOT NULL DEFAULT 0,
                is_child INTEGER NOT NULL DEFAULT 0,
                parent_id TEXT,
                account TEXT NOT NULL,
                category TEXT,
                amount INTEGER NOT NULL,
                payee TEXT,
                notes TEXT,
                date INTEGER NOT NULL,
                imported_id TEXT,
                transfer_id TEXT,
                sort_order REAL,
                cleared INTEGER NOT NULL DEFAULT 1,
                reconciled INTEGER NOT NULL DEFAULT 0,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE zero_budgets (
                id TEXT PRIMARY KEY,
                month INTEGER NOT NULL,
                category TEXT NOT NULL,
                amount INTEGER NOT NULL,
                carryover INTEGER NOT NULL DEFAULT 0,
                goal INTEGER,
                long_goal INTEGER
            )
            SQL);

        $pdo->exec('CREATE TABLE preferences (id TEXT PRIMARY KEY, value TEXT)');

        $pdo->exec(<<<'SQL'
            CREATE TABLE schedules (
                id TEXT PRIMARY KEY,
                name TEXT,
                rule TEXT NOT NULL,
                next_date TEXT,
                completed INTEGER NOT NULL DEFAULT 0,
                posts_transaction INTEGER NOT NULL DEFAULT 0,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE TABLE rules (
                id TEXT PRIMARY KEY,
                stage TEXT,
                conditions_op TEXT,
                conditions TEXT,
                actions TEXT,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        // Synthetic "saved report" extra — no Beatrax equivalent exists;
        // ActualParser must surface each row as an UnmappedItemDto('extra'),
        // never silently drop it (Req 8).
        $pdo->exec(<<<'SQL'
            CREATE TABLE custom_reports (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                config TEXT,
                tombstone INTEGER NOT NULL DEFAULT 0
            )
            SQL);

        // Resolved read-only views — RESEARCH.md's verified Pitfall 1: the
        // importer MUST query these, never the raw tables (stale pre-merge
        // ids + no tombstone filter on the raw tables).
        $pdo->exec(<<<'SQL'
            CREATE VIEW v_categories AS
            SELECT c.id, c.name, c.is_income, c.hidden, c."group", c.goal_def, c.sort_order
            FROM categories c
            WHERE c.tombstone = 0
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE VIEW v_payees AS
            SELECT p.id, p.name, p.transfer_acct, p.favorite
            FROM payees p
            WHERE p.tombstone = 0
            SQL);

        $pdo->exec(<<<'SQL'
            CREATE VIEW v_transactions AS
            SELECT t.id, t.is_parent, t.is_child, t.parent_id, t.account,
                   COALESCE(cm."transferId", t.category) AS category,
                   t.amount,
                   COALESCE(pm."targetId", t.payee) AS payee,
                   t.notes, t.date, t.imported_id, t.transfer_id, t.sort_order,
                   t.cleared, t.reconciled
            FROM transactions t
            LEFT JOIN category_mapping cm ON cm.id = t.category
            LEFT JOIN payee_mapping pm ON pm.id = t.payee
            WHERE t.tombstone = 0
              AND (t.is_child = 0 OR NOT EXISTS (
                  SELECT 1 FROM transactions parent
                  WHERE parent.id = t.parent_id AND parent.tombstone = 1
              ))
            SQL);
    }

    private static function seed(PDO $pdo, string $variant): void
    {
        $pdo->exec("INSERT INTO accounts (id, name) VALUES ('acc-checking', 'Checking')");
        $pdo->exec("INSERT INTO accounts (id, name) VALUES ('acc-savings', 'Savings')");

        $pdo->exec("INSERT INTO category_groups (id, name, is_income) VALUES ('grp-frequent', 'Frequent', 0)");
        $pdo->exec("INSERT INTO category_groups (id, name, is_income) VALUES ('grp-income', 'Income', 1)");

        // FLAT goal_def: a single target amount, no schedule/template.
        $flatGoalDef = json_encode(['type' => 'simple', 'amount' => 20000], JSON_THROW_ON_ERROR);
        // NON-FLAT goal_def: a multi-step template — must NOT reduce to a
        // MigrationGoalDto; becomes an UnmappedItemDto instead (Req 8).
        $nonFlatGoalDef = json_encode([
            'type' => 'template',
            'steps' => [
                ['amount' => 5000, 'starting' => '2026-01'],
                ['amount' => 7500, 'starting' => '2026-06'],
            ],
        ], JSON_THROW_ON_ERROR);

        $insertCategory = $pdo->prepare(
            'INSERT INTO categories (id, name, is_income, "group", goal_def) VALUES (:id, :name, :is_income, :group, :goal_def)',
        );
        $insertCategory->execute(['id' => 'cat-groceries', 'name' => 'Groceries', 'is_income' => 0, 'group' => 'grp-frequent', 'goal_def' => $flatGoalDef]);
        $insertCategory->execute(['id' => 'cat-household', 'name' => 'Household', 'is_income' => 0, 'group' => 'grp-frequent', 'goal_def' => null]);
        $insertCategory->execute(['id' => 'cat-salary', 'name' => 'Salary', 'is_income' => 1, 'group' => 'grp-income', 'goal_def' => null]);
        $insertCategory->execute(['id' => 'cat-emergency-fund', 'name' => 'Emergency Fund', 'is_income' => 0, 'group' => 'grp-frequent', 'goal_def' => $nonFlatGoalDef]);

        foreach (['cat-groceries', 'cat-household', 'cat-salary', 'cat-emergency-fund'] as $categoryId) {
            $pdo->prepare('INSERT INTO category_mapping (id, "transferId") VALUES (:id, :id)')->execute(['id' => $categoryId]);
        }

        $insertPayee = $pdo->prepare(
            'INSERT INTO payees (id, name, transfer_acct) VALUES (:id, :name, :transfer_acct)',
        );
        $insertPayee->execute(['id' => 'payee-ah', 'name' => 'Albert Heijn', 'transfer_acct' => null]);
        $insertPayee->execute(['id' => 'payee-employer', 'name' => 'Employer', 'transfer_acct' => null]);
        $insertPayee->execute(['id' => 'payee-supermarket', 'name' => 'Supermarket', 'transfer_acct' => null]);
        $insertPayee->execute(['id' => 'payee-transfer-savings', 'name' => 'Transfer: Savings', 'transfer_acct' => 'acc-savings']);
        $insertPayee->execute(['id' => 'payee-transfer-checking', 'name' => 'Transfer: Checking', 'transfer_acct' => 'acc-checking']);

        foreach (['payee-ah', 'payee-employer', 'payee-supermarket', 'payee-transfer-savings', 'payee-transfer-checking'] as $payeeId) {
            $pdo->prepare('INSERT INTO payee_mapping (id, "targetId") VALUES (:id, :id)')->execute(['id' => $payeeId]);
        }

        $expenseAmount = $variant === 'v2' ? -5000 : -4500;

        $insertTx = $pdo->prepare(
            'INSERT INTO transactions (id, is_parent, is_child, parent_id, account, category, amount, payee, notes, date, transfer_id, cleared, reconciled, tombstone)
             VALUES (:id, :is_parent, :is_child, :parent_id, :account, :category, :amount, :payee, :notes, :date, :transfer_id, :cleared, :reconciled, :tombstone)',
        );

        // 1. Plain expense.
        $insertTx->execute(['id' => 'tx-1', 'is_parent' => 0, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-checking', 'category' => 'cat-groceries', 'amount' => $expenseAmount, 'payee' => 'payee-ah', 'notes' => null, 'date' => 20260115, 'transfer_id' => null, 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        // 2. Plain income.
        $insertTx->execute(['id' => 'tx-2', 'is_parent' => 0, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-checking', 'category' => 'cat-salary', 'amount' => 200000, 'payee' => 'payee-employer', 'notes' => null, 'date' => 20260116, 'transfer_id' => null, 'cleared' => 0, 'reconciled' => 0, 'tombstone' => 0]);
        // 3. Split parent (category NULL, amount = sum of legs) + 2 children.
        $insertTx->execute(['id' => 'tx-3', 'is_parent' => 1, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-checking', 'category' => null, 'amount' => -3000, 'payee' => 'payee-supermarket', 'notes' => null, 'date' => 20260117, 'transfer_id' => null, 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        $insertTx->execute(['id' => 'tx-3a', 'is_parent' => 0, 'is_child' => 1, 'parent_id' => 'tx-3', 'account' => 'acc-checking', 'category' => 'cat-groceries', 'amount' => -2000, 'payee' => 'payee-supermarket', 'notes' => null, 'date' => 20260117, 'transfer_id' => null, 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        $insertTx->execute(['id' => 'tx-3b', 'is_parent' => 0, 'is_child' => 1, 'parent_id' => 'tx-3', 'account' => 'acc-checking', 'category' => 'cat-household', 'amount' => -1000, 'payee' => 'payee-supermarket', 'notes' => null, 'date' => 20260117, 'transfer_id' => null, 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        // 4. Transfer pair (mutual transfer_id).
        $insertTx->execute(['id' => 'tx-4', 'is_parent' => 0, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-checking', 'category' => null, 'amount' => -10000, 'payee' => 'payee-transfer-savings', 'notes' => null, 'date' => 20260118, 'transfer_id' => 'tx-5', 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        $insertTx->execute(['id' => 'tx-5', 'is_parent' => 0, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-savings', 'category' => null, 'amount' => 10000, 'payee' => 'payee-transfer-checking', 'notes' => null, 'date' => 20260118, 'transfer_id' => 'tx-4', 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 0]);
        // 5. Tombstoned row — must be filtered by v_transactions.
        $insertTx->execute(['id' => 'tx-6-tombstoned', 'is_parent' => 0, 'is_child' => 0, 'parent_id' => null, 'account' => 'acc-checking', 'category' => 'cat-groceries', 'amount' => -999, 'payee' => 'payee-ah', 'notes' => null, 'date' => 20260119, 'transfer_id' => null, 'cleared' => 1, 'reconciled' => 0, 'tombstone' => 1]);

        $groceriesJan = $variant === 'v2' ? 25000 : 20000;
        $householdJan = $variant === 'v2' ? 12000 : 10000;

        $insertBudget = $pdo->prepare(
            'INSERT INTO zero_budgets (id, month, category, amount) VALUES (:id, :month, :category, :amount)',
        );
        $insertBudget->execute(['id' => 'zb-1', 'month' => 202601, 'category' => 'cat-groceries', 'amount' => $groceriesJan]);
        $insertBudget->execute(['id' => 'zb-2', 'month' => 202601, 'category' => 'cat-household', 'amount' => $householdJan]);
        $insertBudget->execute(['id' => 'zb-3', 'month' => 202602, 'category' => 'cat-groceries', 'amount' => 20000]);
        $insertBudget->execute(['id' => 'zb-4', 'month' => 202602, 'category' => 'cat-household', 'amount' => 10000]);

        $insertPreference = $pdo->prepare('INSERT INTO preferences (id, value) VALUES (:id, :value)');
        $insertPreference->execute(['id' => 'budgetType', 'value' => 'envelope']);
        $insertPreference->execute(['id' => 'currencyCode', 'value' => self::BUDGET_FILE_CURRENCY]);

        $rule = json_encode(['conditions' => [['field' => 'payee', 'op' => 'is', 'value' => 'payee-landlord']], 'actions' => [['field' => 'category', 'value' => 'cat-household']]], JSON_THROW_ON_ERROR);
        $pdo->prepare('INSERT INTO rules (id, stage, conditions_op, conditions, actions) VALUES (:id, :stage, :conditions_op, :conditions, :actions)')
            ->execute(['id' => 'rule-1', 'stage' => 'pre', 'conditions_op' => 'and', 'conditions' => $rule, 'actions' => $rule]);
        $pdo->prepare('INSERT INTO schedules (id, name, rule, next_date, posts_transaction) VALUES (:id, :name, :rule, :next_date, 1)')
            ->execute(['id' => 'sched-1', 'name' => 'Rent', 'rule' => 'rule-1', 'next_date' => '2026-02-01']);

        $pdo->prepare('INSERT INTO custom_reports (id, name, config) VALUES (:id, :name, :config)')
            ->execute(['id' => 'report-1', 'name' => 'Spending by month', 'config' => json_encode(['type' => 'chart'], JSON_THROW_ON_ERROR)]);
    }
}
