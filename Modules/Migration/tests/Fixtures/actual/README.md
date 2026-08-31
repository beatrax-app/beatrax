# Actual Budget golden fixture

Unlike the hand-authored YNAB4/nYNAB CSV fixtures in the sibling `ynab4/` and
`nynab/` directories, the Actual Budget export is a full relational SQLite
database. Rather than commit a binary `db.sqlite` blob to git, this fixture
is **script-generated at test time** by
`Modules/Migration/tests/Support/ActualFixtureBuilder.php`, which builds the
minimal Actual Budget schema, views and rows, then packages `db.sqlite` +
`metadata.json` into a real ZIP via `ZipArchive` — so tests round-trip through
the exact same extraction/read path production will use.

`ActualFixtureBuilder::build(string $zipPath, string $variant = 'v1')`
produces:

- 2 accounts, 2 category groups, 4 categories (one FLAT `goal_def`, one
  NON-FLAT/template `goal_def`, one income category, one plain category).
- 5 payees (including the two synthetic transfer-account payees).
- 7 raw transaction rows: 1 plain expense, 1 plain income, a 3-row
  `is_parent`/`is_child` split, a `transfer_id`-linked pair, and 1
  tombstoned row (proving `v_transactions` filtering).
- `zero_budgets` for Jan+Feb 2026 with `preferences.budgetType = 'envelope'`.
- `preferences.currencyCode = 'USD'` — the one fixture exercising a non-EUR
  budget-file currency (YNAB4/nYNAB carry no per-row currency).
- 1 schedule + 1 rule, which the importer records as a note rather than
  translating.
- 1 `custom_reports` row — a saved report with no Beatrax equivalent, covering
  the rule that an unmapped entity is surfaced rather than dropped.

`$variant === 'v2'` changes exactly the Jan 2026 Groceries/Household budgeted
amounts and the plain-expense transaction amount, so the idempotent-reimport
and 3-way-merge tests have a minimal delta to work against — every other row is
byte-identical to `v1`.

`$variant === ActualFixtureBuilder::NO_BUDGET_TYPE` is `v1` with the
`preferences.budgetType` row left out, which is how a real Actual export
routinely ships. The importer must read it as the envelope default and say so,
rather than refusing the file.

See `Modules/Migration/tests/Unit/Fixtures/GoldenFixturesSmokeTest.php` for the
smoke test that opens a built fixture read-only and asserts its shape.
