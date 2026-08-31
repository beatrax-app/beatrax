# Measuring write cost, and the harness transaction that fakes a quadratic

Any timing of a bulk write taken inside this repository's test suite is wrong,
and wrong in a specific and convincing direction: it looks quadratic.

`tests/Pest.php` binds every test to `RefreshDatabase`, and `sqlite_testing` is
`:memory:`. `RefreshDatabase` opens a transaction before the test body and rolls
it back afterwards. Everything the test writes therefore happens inside **one
transaction that is never committed** — and any `DB::transaction()` the code
under test opens is a SAVEPOINT inside it, not a commit.

SQLite's uncommitted state grows for the whole run, so each successive insert
costs more than the last. Per-row cost climbs linearly, total cost looks
quadratic, and none of it is a property of the application.

## The measurement

Confirming an 18,540-row import, same fixture, same machine, same commit — the
only difference is whether the harness transaction is still open:

| | wall clock | per row |
| --- | --- | --- |
| Inside `RefreshDatabase`'s transaction | 236.3 s | 12.75 ms |
| After committing it first | **28.5 s** | **1.54 ms** |

The device agrees with the second number, not the first: an iPhone 12 mini on
the same commit confirms 18,541 rows in **20 s** and 27,777 rows in **30 s**,
fitting `t = 3.80s + 0.936 ms/row` at R² 0.966 across a 14× range. Flat, linear,
no degradation.

## Why the trap is convincing

The false quadratic survives the obvious experiments. Each of these comes back
"not the cause", because the cause is the harness, which none of them varies:

| Hypothesis | Experiment | Result |
| --- | --- | --- |
| SQLite, or this schema | Raw inserts, same table, same nine indexes | Flat at 0.09 ms |
| Index maintenance | Dropped the seven non-unique indexes | Curve unchanged |
| Table size | Pre-filled 18,000 rows first | Faster, not slower |
| Per-row listeners | Faked `TransactionImported` | No change |
| One long transaction | `RowChunk::DEFAULT_SIZE` is 500, so 37 commits | *True of the code, false of the harness* |
| Row size | Raw inserts with a 900-byte `raw_payload` | Flat at 0.10 ms |
| Read/write interleaving | Two indexed `SELECT`s between every insert | Flat |
| Statement-cache churn | Counted distinct `INSERT` SQL over a confirm | One shape |

The fifth row is the instructive one. "The recorder commits every 500 rows, so
it is not one long transaction" was read off the source and was true of the
code — and false of the environment the code was running in. Reading a
constant is not measuring a system.

The raw-insert probes came back flat under the same harness because they wrote
far less uncommitted state per row: 17 small columns against the app's 61, five
of which are AEAD ciphertext.

## How to measure a bulk write properly

- **Measure on the device**, or against a file-backed database with the real
  connection settings. That is the only environment whose numbers mean anything.
- If a bench number is needed, **commit the harness transaction first**
  (`while (DB::transactionLevel() > 0) { DB::commit(); }`) and accept that
  `RefreshDatabase`'s rollback will no longer clean up.
- Treat "cost grows with rows written *this run*, but not with table size" as
  the signature of this trap rather than as a finding. It is what an
  ever-growing uncommitted transaction looks like from the outside.

## Why confirm streams its rows

Confirming used to read the whole canonical row list into memory before handing
it to a recorder that already buffers internally. On the phone's 128 MB ceiling
that killed the app outright — zero rows written, nothing in the log, no failed
job, the run still `previewed`, and the preview gone thirty minutes later.

Rows reach the recorder through a generator, chunk by chunk. On device,
confirming adds **nothing measurable to peak memory**: peak after commit equals
peak after preview to the byte, on both an 18,541-row and a 27,777-row run.

## Related

- [Ingestion pipeline](ingestion-pipeline.md) — the flow this is the last stage of.
- [SQLite write locks](sqlite-write-locks.md) — the other substrate concern here.
