# Why confirming a large import is quadratic

Confirming an import costs more per row the further into the run it gets. On a
27,777-row ASN export it takes about nine minutes at the bench. **The cause has
not been found.** This page exists so the next person does not repeat the eight
experiments that have already been done.

## The shape of it

Per-`INSERT` time, measured through Laravel's `QueryExecuted`, over one
18,540-row confirm:

| rows so far | ms per insert |
| --- | --- |
| 0–999 | 1.13 |
| 4,000–4,999 | 3.59 |
| 9,000–9,999 | 6.65 |
| 14,000–14,999 | 8.83 |
| 18,000–18,540 | 9.77 |

Linear growth in per-row cost means quadratic total cost, and the totals agree:
4,653 rows take 16.2 s (3.47 ms/row) while 27,773 take 547.8 s (19.7 ms/row) —
6× the rows at 5.7× the per-row cost.

The growth sits inside the `INSERT` statement's own measured time, which in
Laravel spans `prepare` + `bindValues` + `execute`.

## What it is not

Every one of these was tested, not reasoned about. None of them changed the
curve.

| Hypothesis | Experiment | Result |
| --- | --- | --- |
| SQLite, or this schema | Raw `insertOrIgnore` into `transactions`, same nine indexes, 19,000 rows | **Flat at 0.09 ms** |
| Index maintenance | Dropped the seven non-unique indexes, re-ran | Curve unchanged (0.66 → 7.74) |
| Table size | Pre-filled 18,000 rows, then confirmed 4,653 | **Faster**, not slower — it tracks rows written *this run* |
| Per-row listeners | Faked `TransactionImported` (Search, Transfers, Receipts) | 9.24 vs 9.93 ms/row — no change |
| The FTS index | Same experiment — Search listens to that event | Excluded with it |
| One long transaction | `RowChunk::DEFAULT_SIZE` is 500, so 37 separate commits | Not applicable |
| Row size | Raw inserts with a realistic 900-byte `raw_payload` | Flat at 0.10 ms |
| Read/write interleaving | Raw inserts with two indexed `SELECT`s between each | Flat at 0.2–0.3 ms |
| Statement-cache churn | Counted distinct `INSERT` SQL strings across a confirm | **One** shape, 61 columns |

## What is left

The app path is both slower in absolute terms (1.13 ms against a raw 0.09 ms
for the same table) and the only version that degrades. The differences that
remain between it and a raw loop are all PHP-side per-row work:

- `FingerprintComposer::compose()`
- `CanonicalTransaction::toAttributes()`
- `SensitiveColumnCodec::encryptAttrs()` — five AEAD columns per row
- Eloquent's query construction for a 61-column `insertOrIgnore`
- `readBack()` hydrating Eloquent models, once per 500-row chunk

Memory is **not** the mechanism: confirm adds only 8 MB across 27,773 rows
(133 MB → 141 MB), so heap growth and GC pressure cannot explain a 9× slowdown.

A function-level PHP profiler is unlikely to answer this on its own — it will
report that `PDOStatement::execute` is where the time goes, which is already
known. What would settle it is capturing the app's exact SQL and bindings for
row 1 and row 18,000 and replaying both raw against an identically-sized
database; if the replay is flat, the cost is in how the statement is issued
rather than in what it does.

## What was fixed, and what was not

The **crash** is fixed and is a separate matter from this. Confirming used to
read the whole canonical row list into memory before handing it to a recorder
that already buffers internally, which killed the app on the phone's 128 MB
ceiling with zero rows written, nothing in the log, no failed job, and the run
still `previewed`. Rows now reach the recorder through a generator, chunk by
chunk: the same 6 MB fixture went from dying with nothing written to writing
27,773 rows at a 141 MB peak.

The slowness above is what remains. A large import completes; it is just slow.

## Related

- [Ingestion pipeline](ingestion-pipeline.md) — the flow this is the last stage of.
- [SQLite write locks](sqlite-write-locks.md) — the other substrate concern on
  this path, and one that *was* closed.
