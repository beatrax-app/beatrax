# A read bounded by how much the user has

The whole backend runs on the reader's phone, over a SQLite file that grows for
years, with no server to absorb a bad query. A read whose only bound is *however
much history this person has* is not slow — on a 128 MB ceiling it is the app
dying, and on NativePHP's single-process `php -S` one runaway operation takes the
backend down with it (a `set_time_limit` expiry is a fatal, not a `Throwable`, so
nothing catches it).

This page records what was measured, what was changed, and — the part that is
easy to skip and expensive to skip — **which whole-table reads are correct as
written**. A `->get()` over `categories` is 29 rows seeded at install; converting
it to a chunked read is churn that makes the codebase worse.

## The fixture the numbers come from

A five-year ledger, file-backed, with the real schema, indexes and triggers:

| Table | Rows | Where the shape comes from |
| --- | --- | --- |
| `transactions` | 25,000 | 2021-09-05 → 2026-09-04, three accounts, 140 counterparties |
| `op_log_entries` | 1,225,000 | 49 field rows per transaction — the live desktop database runs at 48.8 |
| `transaction_search_docs` | 25,000 | one per transaction, trigram-indexed |
| `categorization_rules` | 280 | the live desktop database holds 279 |

Timings taken **outside** the test suite. Any bulk figure taken inside it is
wrong in a specific direction — see [measuring write cost](measuring-write-cost.md).

## Ranked by measurement

| # | Read | Measured | Fixed |
| --- | --- | --- | --- |
| 1 | `AnomalyEvaluator` per transaction, inside a full-history backfill | 138.6 ms/row, 5.1 queries/row — each query plucks a 12-month window into a PHP array. 25,000 rows extrapolates to **58 minutes** | No — see below |
| 2 | `RuleEngine::match()` per transaction | **282.9 queries per row**, 31.5 ms/row. A re-apply over the fixture extrapolates to 7,072,500 queries and **13 minutes** | **Yes** |
| 3 | `PersistedOpLogEntries::forUser()` | **696 MB** peak growth, 10.3 s, for 1,225,000 entries | No — no production caller |
| 4 | `FingerprintRederiveService::run()` | 52 MB growth / 90.5 MB peak, 4.47 s, reading the whole table to skip nearly all of it | **Yes** |
| 5 | `SearchQuery::search()` on a common word | 25,000 candidate ids materialised, 18 MB, 445 ms — per keystroke in the palette | No |

### 2 — the rule book, re-read once per transaction

`RuleEngine::match()` read `categorization_rules` whole, then issued one
`rule_conditions` query per rule and one `rule_actions` query per firing rule.
It is called once per transaction from the re-apply job and once per row from the
import pipeline, so the cost was `transactions × rules`.

`ActiveRuleSet` now loads the book in three queries — rules, then all conditions
and all actions joined to their owning rule — and holds it for the life of the
instance. Nothing keeps an engine across a rule write: `RuleEngine`,
`ActiveRuleSet` and `ApplyAutoCategoryStage` are all transient bindings, and
`TheRuleBookIsNotRereadPerTransactionTest` pins that.

| | queries | 100 rows | 25,000 rows |
| --- | --- | --- | --- |
| Before | 28,294 | 3,148 ms | 7,072,500 queries, ~787 s (extrapolated) |
| After | **3** | **567 ms** | **3 queries, 35.1 s (measured)** |

Matched-rule counts are identical before and after on the same fixture (194 for
the first 100 rows, 55,688 over all 25,000).

### 4 — a re-derive that read the rows it was about to skip

`FingerprintRederiveService::run()` selected every transaction of every user,
twenty columns, and only then skipped in PHP the rows already at the target
normalization version. It runs from a migration, and a phone applies every
migration.

The version predicate moved into SQL — provably equivalent, because the PHP loop
already skipped exactly those rows before touching them — and the result is
streamed rather than fetched.

| | peak | wall |
| --- | --- | --- |
| Before, every row stale | 90.5 MB | 4,473 ms |
| After, every row stale | **46.5 MB** | **1,861 ms** |
| Before, 99% already current | 90.5 MB | 4,473 ms |
| After, 99% already current | **40.5 MB** | **84 ms** |

## Found and measured, deliberately not fixed

- **The anomaly backfill is quadratic in history.** `BackfillAnomaliesJob` walks
  every transaction and, per row, `FirstTimeMerchantDetector` and
  `LargeVsTypicalDetector` each pluck a twelve-month window of amounts into a PHP
  array. Measured at 138.6 ms per row. The window is anchored on each
  transaction's own date, so it cannot be memoised, and every cheaper shape —
  capping the sample, computing the percentile in SQL — changes which
  transactions are flagged as anomalous. That is a behaviour change, and a
  behaviour change needs its spec change merged first.
- **`PersistedOpLogEntries::forUser()` holds the whole op-log as objects.** 696 MB
  at 1.2 M entries. Its only consumer is `OpLogRebuilder::rebuild()`, which no
  production path reaches. Streaming the read alone would not help: the replayer
  sorts and groups the entries it is handed, so the peak moves rather than
  disappears. Fixing it means restructuring the replay, not the read.
- **A common search word materialises the whole matching ledger.** The FTS
  tokenizer is trigram, so a word like *betaling* matches most of a Dutch ledger;
  `FtsCandidateResolver::resolve()` returns every matching rowid and
  `SearchQuery` feeds them to `whereIn`. 25,000 ids, 18 MB, 445 ms — per
  keystroke in the palette. The id list is load-bearing for control flow (`null`
  means filters-only, `[]` triggers the amount branch), so removing it is a
  restructure of the search path rather than a bound.

## What was left alone, and why

Three hundred and forty-seven read sites were classified. The great majority are
correct as written, and converting them would be churn that makes the code worse:

- **~112 read a table with a natural small ceiling** — `accounts` (10),
  `categories` (29), `currencies`, `pots`, `goals`, `device_registry`, `inboxes`,
  `wizard_progress`, `saved_reports`, the `*_settings` and `*_preferences` tables.
  A `->get()` over 29 seeded rows is the right query.
- **~34 already carry a `limit`, a page or a keyset cursor.**
- **~62 are a `whereIn` over a set the caller already holds** — a page of rows, a
  chunk, one transaction's legs.
- **~26 aggregate in SQL**, so the rows handed back to PHP are one per currency,
  account, category or counterparty however long the ledger is.
- **~9 are bounded by a fixed date window** — one calendar grid, one statement
  period, one forecast horizon.

`Modules/FX/Public/Services/ExchangeRateService` deserves a specific mention
because it looks like a whole-table read and is not: both queries correlate on
`MAX(rate_date)` per pair, so the answer is one row per currency pair whatever
the rate history holds.

### The pairs that make the case

The most convincing evidence that an unbounded read is a mistake rather than a
choice is a sibling in the same file doing it correctly:

| File | Bounded | Unbounded |
| --- | --- | --- |
| `Modules/Anomaly/Public/Services/AnomalyAlertQuery.php` | `openForUser()` — same predicate, `limit(26)` and a keyset cursor | `openDetectorBreakdownForUser()` |
| `Modules/Chains/Public/Services/ChainLinkQuery.php` | `candidatesForReview()` — `limit`, cursor, and a `count()` sibling | `hintsForReview()` |
| `Modules/Counterparties/Public/Queries/CounterpartyDisplayName.php` | `forIds()` — same three columns, same decrypt | `forUser()` |
| `Modules/Search/Internal/Console/ReindexSearchCommand.php` | the bulk read, `chunk(500)`, with an OOM comment | the `distinct()->pluck()` thirty lines above |
| `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php` | `filesize()` checked against a cap before the read | the identical read in `ProcessFetchedInboxMessagesJob` |

## The ten reads with no bound at all

Named here rather than fixed, so the next pass starts from a list instead of a
grep. Ranked by rows times how often the path runs:

1. `CounterpartyDisplayName::forUser()` — every counterparty decrypted and
   locale-sorted **on every transaction-detail render**.
2. `EntityNameSearch` — every counterparty read and decrypted **per
   command-palette keystroke**, to return at most three names.
3. `AnomalyAlertQuery::openDetectorBreakdownForUser()` — every open alert **on
   every dashboard paint**; nothing auto-closes an alert.
4. `IcsSettlementResolver` candidate transfers — whole history, no date
   predicate, **synchronously inside the import-confirm request**.
5. `ImportSyncCapture` — every id of one import run, then an unchunked
   `whereIn`, while `ConfirmImport` beside it streams the rows themselves.
6. `DetectAnomaliesJob` — one import run, which for the onboarding import is the
   whole ledger.
7. `ReconciliationWriter` — the cleared-rows predicate has no lower bound, so a
   first Complete-reconcile plucks every cleared row an account ever held and
   dispatches one sync op per id.
8. `ChainLinkQuery::hintsForReview()` — grows with import history, drained only
   by a manual dismiss.
9. `EnvelopePeriodRekeyer` (assignments and moves) — the whole envelope history,
   read when the reader changes the budget month start day.
10. `MysteryMerchantsPage` — chunked, so memory is fine, but each unresolved row
    is matched against the 7,063-row community corpus: **4.14 ms per row measured**
    against a real corpus, which is 103 s over a 25,000-row ledger on a page
    render, against the desktop's 120 s `max_execution_time`.

## The guard

`tests/Contracts/BoundedReadArchTest.php` tokenises every file under `Modules/`
and `app/` and reports a fluent chain that names a growing table and ends in
`->get()` or `->pluck()` with nothing in the chain that bounds it. `cursor` and
the `lazy*` family are not in the bounds list because they are not bounds — they
are the fix, and a chain ending in one hands PHP a row at a time.

Its allow-list is keyed `path::table` and records **how many** reads are admitted
there and **why** each is bounded by something real. A new read in an allowed file
pushes the count past its entry; an entry that stops matching fails too, so the
list cannot decay into a blanket exemption.

Its one honest blind spot: a chain assembled across two variables
(`$q = DB::table('transactions')…;` then `$q->get();`) is invisible to it, because
the table name and the terminal are in different statements.

## Related

- [Measuring write cost](measuring-write-cost.md) — why a bulk timing from the test suite is wrong.
- [SQLite write locks](sqlite-write-locks.md) — the other substrate concern on this file.
- [Ingestion pipeline](ingestion-pipeline.md) — the path most of these reads sit on.
