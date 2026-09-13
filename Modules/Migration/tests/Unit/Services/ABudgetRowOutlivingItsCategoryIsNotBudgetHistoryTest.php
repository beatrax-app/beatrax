<?php

declare(strict_types=1);

use Modules\Migration\Internal\Services\ActualSqliteReader;

// Actual's budget tables keep a row after the category it names is tombstoned.
// Every other read in this class resolves tombstones away; this one did not, so
// those months were staged, counted in the preview's "months of budget history"
// and then dropped at promotion, which has no category to write them to and
// reports nothing when it finds none.
function outlivedCategoryDatabase(bool $withResolvedView): string
{
    $dir = sys_get_temp_dir().'/actual-outlived-category-'.uniqid('', true);
    mkdir($dir, 0755, true);
    $path = $dir.'/db.sqlite';

    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE categories (id TEXT PRIMARY KEY, name TEXT NOT NULL, is_income INTEGER DEFAULT 0, hidden INTEGER DEFAULT 0, "group" TEXT, goal_def TEXT, tombstone INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec("INSERT INTO categories (id, name, tombstone) VALUES ('cat-live', 'Groceries', 0), ('cat-gone', 'Holiday Fund', 1)");
    $pdo->exec('CREATE TABLE zero_budgets (id TEXT PRIMARY KEY, month INTEGER, category TEXT, amount INTEGER)');
    $pdo->exec("INSERT INTO zero_budgets VALUES ('b1', 202601, 'cat-live', 20000), ('b2', 202601, 'cat-gone', 50000), ('b3', 202602, 'cat-gone', 50000)");
    $pdo->exec('CREATE TABLE preferences (id TEXT PRIMARY KEY, value TEXT)');
    $pdo->exec("INSERT INTO preferences VALUES ('budgetType', 'rollover'), ('currencyCode', 'EUR')");

    if ($withResolvedView) {
        $pdo->exec('CREATE VIEW v_categories AS SELECT id, name, is_income, hidden, "group", goal_def FROM categories WHERE tombstone = 0');
    }

    return $path;
}

// Both spellings, because the raw-table fallback builds a different subquery
// from the resolved view and only one of them is exercised by a modern export.
it('reads a budget month only for a category the export still has', function (bool $withResolvedView): void {
    $reader = new ActualSqliteReader(outlivedCategoryDatabase($withResolvedView));

    expect($reader->budgetAssignments())->toBe([
        ['category' => 'cat-live', 'month' => 202601, 'amount' => 20000],
    ]);
})->with([
    'a modern export, through v_categories' => [true],
    'an export old enough to predate the view' => [false],
]);
