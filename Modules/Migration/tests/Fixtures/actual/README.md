# Actual Budget golden fixture

Unlike the hand-authored YNAB4/nYNAB CSV fixtures in the sibling `ynab4/` and
`nynab/` directories, the Actual Budget export is a full relational SQLite
database. Rather than commit a binary `db.sqlite` blob to git, this fixture
is **script-generated at test time** by
`Modules/Migration/tests/Support/ActualFixtureBuilder.php`, which builds the
minimal schema/views/rows documented in `13.5-RESEARCH.md` § Format Schemas —
Actual Budget, then packages `db.sqlite` + `metadata.json` into a real ZIP
via `ZipArchive` — so tests round-trip through the exact same
extraction/read path production will use.

`ActualFixtureBuilder::build(string $zipPath, string $variant = 'v1')`
produces:

- 2 accounts, 2 category groups, 4 categories (one FLAT `goal_def`, one
  NON-FLAT/template `goal_def`, one income category, one plain category).
- 5 payees (including the two synthetic transfer-account payees).
- 7 raw transaction rows: 1 plain expense, 1 plain income, a 3-row
  `is_parent`/`is_child` split, a `transfer_id`-linked pair, and 1
  tombstoned row (proving `v_transactions` filtering).
- `zero_budgets` for Jan+Feb 2026 with `preferences.budgetType = 'envelope'`.
- `preferences.currencyCode = 'USD'` — the one fixture exercising Req 7's
  non-EUR budget-file currency (YNAB4/nYNAB carry no per-row currency).
- 1 schedule + 1 rule (Req 8 note-only descope).
- 1 `custom_reports` row (the saved-report "extra" with no beatrax
  equivalent — Req 8 unmapped-not-dropped coverage).

`$variant === 'v2'` changes exactly the Jan 2026 Groceries/Household budgeted
amounts and the plain-expense transaction amount, for the Req 9/10
idempotent-reimport and 3-way-merge tests — every other row is byte-identical
to `v1`. See `Modules/Migration/tests/Unit/Fixtures/GoldenFixturesSmokeTest.php`
for the smoke test that opens a built fixture read-only and asserts its shape.
