# Case folding is one function

"Ignore case" is written once in this codebase, in
`Modules/Core/Public/Support/UnicodeFolding`. The PHP method `of()` is the whole
rule; the SQL function `beatrax_fold` is that same method, registered on the
connection so SQL can call it. Nothing else folds case for the purpose of
matching what a reader typed.

## Why SQLite cannot do this on its own

SQLite's `LIKE` is case-insensitive, and its `LOWER()` lowercases — but both fold
**ASCII only**. `PRAGMA case_sensitive_like` can turn the folding off and cannot
widen it, and the extension that would (ICU) is not in this build:
`icu_load_collation` answers `no such function`. Adding one is what `AGENTS.md`'s
second rule forbids.

Measured on the shipped build (SQLite 3.53.3), over a body containing
`MÖRK BAR` and `Ölkanne`:

| needle | `body LIKE '%n%'` | `LOWER(body) LIKE LOWER('%n%')` | `beatrax_fold(body) LIKE beatrax_fold('%n%')` |
|---|---|---|---|
| `ör` | 0 | 0 | 1 |
| `ÖR` | 1 | 1 | 1 |
| `ölk` | 0 | 0 | 1 |
| `ÖLK` | 1 | 1 | 1 |

The middle column is what the search's `LIKE` arm used to do. A reader typing
their merchant's name in the case they see it on screen — `ölkanne`, not
`Ölkanne` — got nothing, and the app offered no reason.

FTS5's trigram tokenizer folds over the whole of Unicode, so search's other arm
already answered `örk` correctly. The arm is picked by the needle's **length**
(three characters is the trigram minimum), which made the length of a word the
thing that decided whether its accents mattered.

## What the function is

```text
beatrax_fold(x) = mb_strtolower(x, 'UTF-8')
```

One argument, `Pdo\Sqlite::DETERMINISTIC`, registered by
`Modules/Core/Internal/Providers/UnicodeFoldingProvider`. A non-scalar argument
(a `NULL` column) folds to `NULL`, which is what SQLite's own scalar functions
do with one.

It folds case and nothing else. Diacritics are **kept**: `o` does not find `ö`,
in either arm. That is FTS5's trigram default too, so matching it is what keeps
the two arms saying the same thing.

## Where it is registered, and why that is the whole risk

A SQLite user function lives on **one connection**. A connection that never had
it registered does not fold differently — it cannot run the query at all, and
says `no such function: beatrax_fold`.

`ConnectionEstablished` is the one event every way of getting a connection
passes through, so the provider listens there and nowhere else:

| How a connection appears | Where the event is dispatched |
|---|---|
| First resolve of a named connection | `DatabaseManager::connection()` |
| A connection built from an inline config | `DatabaseManager::connectUsing()` / `build()` |
| An explicit reconnect | `DatabaseManager::reconnect()` |
| A lost connection re-opened mid-query | `Connection::run()` → `reconnectIfMissingConnection()` → the manager's reconnector → `reconnect()` |
| A connection opened **before** the listener was attached | replayed by `Modules/Core/Internal/Providers/AlreadyOpenConnectionsProvider` |
| One of those the replay has to skip | walked by the provider's own `Application::booted()` callback |

The last two rows are the desktop: NativePHP's provider makes `nativephp` the
default connection and opens it inside its own `boot()`, before this module
attaches anything. A resolved connection is cached, so the event never fires
again for the one the desktop actually reads — which is why the replay exists.

The replay cannot reach a connection that is **already inside a transaction**,
because re-dispatching the event also re-writes `PRAGMA journal_mode` and
`PRAGMA synchronous`, which SQLite refuses there. Registering a function inside
a transaction is legal, so the provider walks the open connections itself as
well, from an `Application::booted()` callback — after every provider has
booted, so a connection opened by one that boots later is still seen. Both paths
are idempotent: `createFunction` on a name already registered replaces it.

A queue worker, `sync:serve`, the scheduler and an `artisan` command each boot
the same providers in their own process, so each attaches the listener before it
can resolve a connection.

The one PDO this does not reach is `Modules/DevMode/Internal/Sql/IsolatedSelectProcess`,
which opens the database file itself in a child process, outside the framework,
to run one developer-typed `SELECT` under a timeout. It is the same exemption
`PRAGMA busy_timeout` has there, for the same reason, and it is pinned.

## What happens when it is missing

The query fails. It does not fall back.

A fallback to `LOWER()` is exactly the ASCII-only comparison this replaced, so a
connection that quietly took it would return **fewer rows with no signal** —
the original defect, now hidden behind a fix. `UnicodeFolding::registerOn()`
therefore refuses at connect time if the PDO cannot carry a user function, and
any connection that somehow escapes registration raises SQLite's own
`no such function: beatrax_fold` on the first folded query. Both are loud.

`tests/Contracts/AFoldedQueryCannotRunOnAnUnfoldedConnectionArchTest.php` is
what keeps that second case unreachable: it walks every connection
`config/database.php` configures and every path above, and asserts the function
answers on each.

## Residual differences between the two arms

The `LIKE` arm folds through `mb_strtolower`; the FTS arm folds through FTS5's
own table. They agree on every cased letter in the Latin, Greek and Cyrillic
ranges the product's twenty-six locales are written in. They differ where a
lowercase mapping **expands**: `mb_strtolower('İ')` is `i` plus a combining dot,
FTS5 leaves `İ` alone, and neither arm finds `istanbul` in `İSTANBUL`. That is a
gap in both, not a disagreement between them, and it is not what this page
exists to fix.

## Cost

Folding a column defeats an index on it. Neither arm ever used one: a
`LIKE '%needle%'` cannot, and the previous `LOWER(name) LIKE 'needle%'` could
not either. What it adds is one PHP call per row already being read and
compared: measured over a 50,000-row table scanned end to end, 24.2 ms plain
against 56.2 ms folded. The `LIKE` arm's scan is bounded by
`FtsCandidateResolver::LIKE_FALLBACK_CANDIDATE_CAP` and is only ever reached by
a needle too short to tokenise.
