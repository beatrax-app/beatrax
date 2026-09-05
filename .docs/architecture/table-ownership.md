# Table ownership

Module boundaries here are enforced in one dimension: the `use` statement.
`App\PhpStan\Rules\BoundaryRule` and the `arch(...)` rules in
`tests/Contracts/BoundaryArchTest.php` both police who imports whom.

The database is the other dimension, and it is much wider. Modules reach across
it constantly — a raw query builder call names a table as a string, so no
import, no namespace, and no static analyser sees the crossing. This page
describes the ownership rule that makes those crossings legible, and the two
invariants that pin them.

## The rule: the migration that creates a table owns it

There is no hand-written table-to-module map, and there should never be one — a
list like that is wrong the day after somebody adds a migration. Ownership is
derived at test time by `boundaryTableOwnership()` in
[`tests/Contracts/BoundaryArchTest.php`](../../tests/Contracts/BoundaryArchTest.php),
which reads every migration under `Modules/*/Database/Migrations/` and under
`database/migrations/` — the framework's own four migrations, plus the
application-level schema rewrites that belong to no module — and records, per
table, the module whose migration created it.

Three spellings create a table in this repo and all three are recognised:

| Spelling | Where it appears |
| --- | --- |
| `Schema::create('x', …)` | the earliest Ledger and Core migrations, and the framework's own |
| `$this->schema()->create('x', …)` | every migration extending `Modules\Core\Database\Support\ModuleMigration` — the DI-only form, which is most of them |
| raw `CREATE TABLE` / `CREATE VIRTUAL TABLE` | `hlc_clock_state` and `sync_peer_catch_up_state`, whose compound primary keys a `Blueprint` cannot express, and the FTS5 pair `transaction_search_docs` / `transaction_search_fts` |

Ownership only means something while exactly one module creates a table, so the
invariant asserts that too: a table created by two modules fails the test rather
than letting the derivation silently pick one. **No table in the tree is
contested.** The framework's own tables (`cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`) have no owning module and are attributed to
`@root`; a module writing them is a crossing like any other.

## What is pinned: writes, not reads

`crossModuleRawTableWrites` scans every production file under `Modules/`
(excluding `tests/` and `Database/`) for a raw-table reference — `DB::table`,
`->table`, `->from`, any `join` variant — that names a table another module
owns, and asks whether the fluent chain ends in a write. It also reads raw SQL
strings, because `$connection->update('UPDATE transactions …')` names its table
inside a string literal and every table-name grep in this repo used to miss it.

Writes counted: `update`, `updateOrInsert`, `insert`, `insertGetId`,
`insertOrIgnore`, `insertUsing`, `upsert`, `delete`, `forceDelete`, `truncate`,
`increment`, `decrement`, `incrementEach`, `decrementEach`, and the SQL verbs
`INSERT INTO`, `REPLACE INTO`, `UPDATE`, `DELETE FROM`, `TRUNCATE`.

The write must terminate the chain at its own nesting depth. That is what keeps
a read like `->table('op_log_entries')->chunkById(…, function () { … })` from
being reported as a write because the closure it was handed contains one.

### The pin is a count, not a line number

An entry reads `Modules/Transfers/…/TransferPairer.php transactions 2` — file,
table, and **how many** writes, deliberately without the line. Do not "tighten"
this back to `path:line`. A line-keyed pin fails on every edit made *above* a
write, which has nothing to do with a boundary being crossed; on a branch where
several people are editing these files at once, the test then cries wolf through
a merge and people learn to re-pin without reading. The count is stable under
any edit that does not add or remove a write, and it still catches both the case
a bare file+table pair would miss and the one it would not: a **new** file
writing a foreign table, and an **already-pinned** file gaining a second write
to the same one.

The line numbers are still computed, and the failure message prints them
(`… transactions 3 (now at line 143, 151, 159)`) so a reader is pointed at the
exact write. They are just not what the assertion compares.

`crossModuleSchemaAlterations` needs no count. Its key is the module-and-table
pair, and a module adding a second column to a table it already alters is the
same declared fact, not a new one.

**Reads are deliberately unrestricted.** Of the cross-module raw-table
references in production, the overwhelming majority are reads — joins onto
`transactions`, `accounts`, `categories`, and `counterparties` that are the
normal way this application composes a screen. Restricting those is a much
larger argument about whether every cross-module read should go through a
`Public/` query seam, and it has not been had. The write half is pinned now
because a write is where one module changes another module's state, which is
the coupling that actually breaks things.

## What is pinned: schema alterations

`crossModuleSchemaAlterations` pins the second, cheaper half — a module adding
columns to a table it did not create. Fourteen such pairs exist:

| Table | Owner | Modules that add columns |
| --- | --- | --- |
| `users` | `Core` | `Anomaly`, `Auth`, `Budgets`, `Categorization`, `FX`, `Recurring`, `Tax` |
| `transactions` | `Ledger` | `Categorization`, `Counterparties`, `Import` |
| `user_preferences` | `Core` | `Calendar`, `Reports` |
| `accounts` | `Ledger` | `Forecasting` |
| `inbox_messages` | `EmailScan` | `Receipts` |

`Modules/Core/Models/User.php` therefore carries columns owned by seven other
modules, and nothing in the code said so. This is **accepted by design** — the
invariant declares the arrangement, it does not forbid it. What it buys is that
an eighth module bolting a column onto `users` shows up as a decision somebody
signed off on rather than as a migration nobody looked twice at.

## When the test fails

The failure message names what appeared and what went missing.

- **A new entry appeared.** Either route the write through the owning module's
  `Public/` surface, or decide the crossing is right and add the line to the
  pinned list in the same commit. The list is the record of that decision.
- **A pinned entry no longer matches.** The count changed. Either the file
  gained a write to that table — treat it as a new entry above — or one went
  away, in which case lower the count or drop the line. Moving a write around
  inside the file does not do this.
- **A table is contested.** Two modules create the same table. Ownership is
  undefined until one of them stops; nothing else in this page holds otherwise.

## Where to look next

- [Module boundaries](module-boundaries.md) — the import-level half of the same
  boundary, and the arch invariants that hold it.
- [Writing an arch invariant](../conventions/arch-invariants.md) — the house
  style every grep-style rule in `BoundaryArchTest.php` shares.
- [Data model](https://github.com/beatrax-app/spec/blob/main/20-architecture/data-model.md) — the table-by-table layout, grouped by owning module.
