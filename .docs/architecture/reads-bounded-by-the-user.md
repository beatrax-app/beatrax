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
| 5 | `SearchQuery::search()` on a common word | 25,000 candidate ids materialised, 18 MB, 445 ms — per keystroke in the palette | **Yes** |
| 6 | `CommunityCorpusQuery::lookupGeneralized()` for a reader who named no country | **143.5 ms per unresolved row**, 6,750 patterns scanned past PHP's 4,096-entry PCRE cache | **Yes** |
| 7 | `CounterpartyDisplayName::forUser()` | 109.7 ms and 2.05 MB for 2,000 counterparties, on every transaction-detail render | **Yes** |
| 8 | `EntityNameSearch` counterparty scan | the whole merchant list materialised per palette keystroke, 1.14 MB, to return three names | **Yes** |

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

### 5 — a common word handed `whereIn` the whole matching ledger

The FTS tokenizer is trigram, so a word like *betaling* matches most of a Dutch
ledger. `FtsCandidateResolver::resolve()` plucked every matching rowid and
`SearchQuery` fed the list to `whereIn` — twice, because `totals()` clones the
page query. The list was read as a bound and was never one.

The restructure this was filed as needing turned out to be small: the FTS arm
hands back the **query** rather than its result, and `whereIn()` routes a
`Builder` through `createSub()` into `IN (SELECT …)`. `EXPLAIN QUERY PLAN`
confirms `LIST SUBQUERY` — SQLite materialises the MATCH once rather than per
outer row, which a correlated `whereExists` would not have done. That shape was
measured and rejected: it takes `totals()` to 3,375 ms.

Capping the candidate set is not available and it is worth saying why, because
it is the obvious idea. The page orders `posted_at DESC, id DESC` while the FTS
pluck comes back in ascending rowid order, so a cap would hand the page the
oldest rows and then sort those; and `totals()` aggregates over the whole
candidate set, with `$totalCount === 0` gating the did-you-mean suggestion.

The two sentinels the id list carried survive: `null` still means filters-only,
and `[]` — the amount branch — became a lazy `EXISTS` probe consulted only once
the text parses as money.

| common word, palette width | before | after |
| --- | --- | --- |
| bindings in one statement | **20,004** | **58** |
| peak PHP memory | 8.95 MB | **0.13 MB** |
| total SQL | 177.5 ms | **84.6 ms** |

58 is the highlight load — six sentinels plus fifty page rowids — so it is
bounded by the page rather than by the ledger.

The amount branch beside it was deliberately **not** converted. As a subquery it
runs twice, once in the page read and once in the totals clone: 7.7 ms to
16.5 ms on `49.90`. It is already bounded to rows sharing one figure, and
holding the subquery would have split the chain across two statements, which is
the scanner's documented blind spot — its allow-list entry would have gone stale
while the read still happened. It keeps its entry.

Fifteen query shapes — common and rare words, no-match, a bare number, three
money queries, the LIKE fallback, filters-only, multi-word, two filter
combinations and a second cursor page — return identical pages: every row id and
its order, both totals, `hasMore`, both cursors, the did-you-mean and the
highlighted snippets.

### 6 — the corpus scan a reader who named no country pays

`MysteryMerchantsPage` matches every unresolved description against the bundled
community corpus. The doc's own earlier figure — 4.14 ms per row — was measured
for a reader who **had** named their country, whose region scope is about 480
patterns. It is not the shape most installs are in: naming no country is the
default, and `inRegion()` then widens to every region at once.

That is 6,750 compiled patterns in one scan, and PHP's PCRE cache holds 4,096.
Past that ceiling every pattern is re-compiled, with JIT, on every single row —
so the cost per pattern does not stay flat, it jumps roughly 25-fold:

| Region scope | patterns | ms per row | µs per pattern |
| --- | --- | --- | --- |
| **no country (the default)** | 6,750 | **143.49** | 21.26 |
| NL | 476 | 0.24 | 0.51 |
| US | 777 | 0.66 | 0.85 |
| DE | 504 | 0.50 | 0.99 |

Every compiled pattern is a `preg_quote`'d literal between two zero-width
lookarounds, so a match **requires the needle verbatim**, case-folded. A
`stripos` probe is therefore a sound necessary condition rather than a
heuristic — with one exception, established by testing all 196,608 codepoints
of the BMP and SMP against `/iu`: exactly two fold onto an ASCII letter,
`U+017F ſ` onto `s` and `U+212A K` onto `k`. A haystack carrying either skips
the probe. Ten of the corpus's 6,750 needles are not ASCII and skip it too.

| Region scope | before | after |
| --- | --- | --- |
| no country | 143.49 ms/row | **1.27 ms/row** |
| NL | 0.24 ms/row | **0.11 ms/row** |

The per-pattern cost is flat at ~0.19 µs at every scope, so the cache cliff is
gone rather than moved. `mb_check_encoding` moved out of the scan for the same
reason: `matchesCompiled()` asked it of the same haystack once per corpus row.

Equivalence was measured rather than argued, twice: 3.17 million needle-haystack
pairs to show the probe never rejects a real match, and 3,500 lookups over five
region scopes — 841 of them resolving to a name — against the same scan with the
probe removed. Byte-identical both times.

### 7 — every counterparty, on every transaction-detail render

`CounterpartyDisplayName::forUser()` fills a `<select>` in three Livewire
components, and `TransactionDetail` re-renders it on every update the reader
types. It read the whole merchant list with `get()` — holding the raw result set
alongside the list it built from it — and then sorted with
`LocaleCollator::compare()` once per comparison.

The sort was the larger half, and not for the reason it looks like. `compare()`
resolves the translator **out of the service container on every call**, and a
2,000-name sort makes 23,241 of them. ICU itself was never the cost:

| variant | 2,000 names |
| --- | --- |
| `LocaleCollator::compare()` as it stood | 85–119 ms |
| the same ICU comparisons, translator held | 11.2 ms |
| one sort key per name, `LocaleCollator::sorted()` | **1.5 ms** |

`sorted()` keys each name once instead of collating each pair, which is `n`
calls into ICU rather than `n·log n`. ICU sort keys are order-equivalent to
`compare()` by contract, and that was checked rather than assumed — 1,089 pairs
across ten locales, then the whole 2,000-name list across all twenty-six.

The read itself is now a keyset walk, so the picker holds one window of rows
rather than the table beside the list.

| | wall | queries | peak |
| --- | --- | --- | --- |
| Before | 109.7 ms | 1 | 2.05 MB |
| After | **20.9 ms** | 5 | **1.25 MB** |

What is left is dominated by the decrypt, and two thirds of *that* is not the
cipher — see the note on `loadKeyring` below.

### 8 — every counterparty, per palette keystroke

`EntityNameSearch` returns at most three counterparty names, and the cap can
only be a walk that stops: `display_name` is ciphertext, so SQL has no name to
match on. The walk was over a `get()`, so the whole merchant list was paid for
before the break could fire. It is now a keyset walk, and a keystroke whose
matches are near the front of the table stops inside the first window.

| | statements | peak |
| --- | --- | --- |
| Before, match near the front | 1, unbounded | 1.26 MB |
| After, match near the front | **1, windowed** | **0.16 MB** |
| Before, no match | 1, unbounded | 1.14 MB |
| After, no match | 8, windowed | **0.30 MB** |

Wall time for the no-match keystroke is unchanged within measurement noise
(15–16 ms either way at 2,000 counterparties): the decrypts dominate and the
extra statements do not register. That case cannot be made cheaper without a
name predicate SQL could use, which ciphertext does not give it.

Both lists were proved unchanged rather than assumed: 2,000 names and nineteen
palette needles, rendered under all twenty-six shipped locales and diffed
against the same code path with the fix removed — byte-identical everywhere.

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
- **Two thirds of a bulk decrypt is the keyring lookup, not the cipher.**
  `SensitiveColumnCodec::decryptValue()` asks `GdkKeyringService::loadKeyring()`
  once per value, and that call releases the app-lock key out of the session and
  re-derives its cache fingerprint before the memo can answer. Over 2,000
  counterparties: 12.6 ms total, of which 8.7 ms is the lookup and 3.6 ms the
  AEAD. The fingerprint is what stops a withheld or rotated key resolving to a
  cached keyring, so making it cheaper means moving when that check runs — a
  security-relevant change, and not one to make as a side effect of a read pass.

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
choice is a sibling in the same file doing it correctly. `CounterpartyDisplayName`
was on this list — `forIds()` bounded, `forUser()` beside it not — and is the one
row of it that has since been closed:

| File | Bounded | Unbounded |
| --- | --- | --- |
| `Modules/Anomaly/Public/Services/AnomalyAlertQuery.php` | `openForUser()` — same predicate, `limit(26)` and a keyset cursor | `openDetectorBreakdownForUser()` |
| `Modules/Chains/Public/Services/ChainLinkQuery.php` | `candidatesForReview()` — `limit`, cursor, and a `count()` sibling | `hintsForReview()` |
| `Modules/Search/Internal/Console/ReindexSearchCommand.php` | the bulk read, `chunk(500)`, with an OOM comment | the `distinct()->pluck()` thirty lines above |
| `Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php` | `filesize()` checked against a cap before the read | the identical read in `ProcessFetchedInboxMessagesJob` |

## The reads with no bound at all: ten, now seven, plus one newly named

Named here rather than fixed, so the next pass starts from a list instead of a
grep. Ranked by rows times how often the path runs. Numbers 1, 2 and 10 of the
original ten are struck through: they were the subject of the pass above, and
`BoundedReadArchTest` no longer carries an allow-list entry for the first two.
Number 11 is new — found while measuring 10, and left for a pass that measures
the page it sits on rather than the scan underneath it.

1. ~~`CounterpartyDisplayName::forUser()`~~ — **fixed**: a keyset walk, and one
   collation key per name rather than one collation per pair. 109.7 ms → 20.9 ms.
2. ~~`EntityNameSearch`~~ — **fixed**: a keyset walk the match limit can stop,
   so three names cost one window rather than the merchant list. 1.26 MB → 0.16 MB.
3. `AnomalyAlertQuery::openDetectorBreakdownForUser()` — every open alert **on
   every dashboard paint**; nothing auto-closes an alert.
4. `IcsSettlementResolver` candidate transfers — whole history, no date
   predicate, **synchronously inside the import-confirm request**.
5. `ImportSyncCapture` — every id of one import run, then an unchunked
   `whereIn`, while `ConfirmImport` beside it streams the rows themselves.
6. `DetectAnomaliesJob` — one import run, which for the onboarding import is the
   whole ledger.
7. `TransactionStatusWriter` — the cleared-rows predicate has no lower bound, so a
   first Complete-reconcile plucks every cleared row an account ever held and
   dispatches one sync op per id.
8. `ChainLinkQuery::hintsForReview()` — grows with import history, drained only
   by a manual dismiss.
9. `EnvelopePeriodRekeyer` (assignments and moves) — the whole envelope history,
   read when the reader changes the budget month start day.
10. ~~`MysteryMerchantsPage`~~'s corpus match — **fixed**, and the figure it was
    filed under was the wrong one. 4.14 ms per row is what a reader who named
    their country pays; the default install names none, scans every region's
    patterns at once, and paid 143.5 ms per row. Now 1.27 ms. The scan still
    reaches every row the reader owns, which is deliberate — see above.
11. `MerchantNameResolver::resolve()` issues **two queries per unresolved row**
    — `users.community_settings` and the corpus exact lookup — which the same
    page render multiplies by the whole ledger: 50,000 statements at 25,000
    rows. Measured, not fixed. The obvious memo has a trap in it:
    `CommunitySettings::enabled()` refuses to cache **by design**, because an
    opt-out answered from a cache no sync write can drop is an opt-out that
    keeps sharing after the reader switched it off. A memo scoped to one render
    is compatible with that and a memo on the singleton resolver is not, so the
    fix belongs in the page rather than under it — and belongs in a pass that
    measures the page end to end, which this one did not.

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

## One finding that was wrong, and why it looked right

`RelayServeCommand:171` buffers a request body with no `limit:` argument, while
`PairingFrameRequestHandler:91` passes `limit: self::MAX_BODY_BYTES` — 8,192 —
to the identical Amp call. That reads like a sibling doing it correctly beside
one that forgot, which is the strongest shape in this whole page. It is not one.

The two are different endpoints carrying different protocols, and neither
conclusion survives being checked:

- **It is not uncapped.** The relay listener is built with
  `SocketHttpServer::createForDirectAccess()` and no driver factory, so Amp's
  `HttpDriver::DEFAULT_BODY_SIZE_LIMIT` — 131,072 bytes — applies before the
  handler is reached. Nothing in the tree calls `increaseSizeLimit()`.
- **The sibling's number would break it.** A delivery body is a JSON envelope
  around `base64_encode($blob)`, and `RelayClient::MAX_BLOB_BYTES` is 66,560,
  which base64 takes to about 89 KB. `RelayResourceLimitsTest` posts exactly
  that and expects 202. An 8,192 limit turns a legitimate at-cap delivery into
  a refusal.

Left alone deliberately. Recorded here because the next reader will find the
same asymmetry and reach the same wrong conclusion.

## The other axis: a read bounded by how much the sender sent

Everything above bounds a read by *row count*. The same ceiling is reached a
second way, in *bytes*, whenever one object is materialised whole and its length
was chosen by somebody outside this device: a `.eml` dropped into the watched
folder, a raw message fetched from Gmail or Microsoft Graph. Gmail alone carries
attachments up to about 25 MB, ~35 MB once base64-encoded, and the base64url
decode held three further copies of that before the plaintext existed.

The measurement that mattered was not a timing. It was that the doors were
inconsistent: `ScanInboxDropFolderJob` checked `filesize()` against a cap while
`ProcessFetchedInboxMessagesJob`, reading the same kind of file for the same
parser, checked nothing — and the cap that did exist was skipped outright when
`filesize()` answered `false`, which is the case where the size is least known.

`Modules\Core\Public\Support\BoundedRead` is the single door now:

- `refuseAbove()` — a size the sender states, checked before the bytes exist.
  This is what Gmail's `sizeEstimate` is read into, ahead of the decode.
- `file()` — stat, refuse, read at most the ceiling plus one byte, refuse again.
  A stat that fails refuses the file rather than waving it through, and the
  second check closes the window in which the file grows between the two.
- `stream()` — `Content-Length` where the response declares one, otherwise the
  body a chunk at a time, abandoned the moment the running total passes the
  ceiling.
- `head()` — for a reader that only wants the front of a body (a provider's
  error message). It declines to hold the rest rather than refusing the whole,
  because losing a diagnosis is worse than truncating it.

The ceiling is `Modules\Core\Public\Support\UploadLimits::MAX_MESSAGE_BYTES`,
one number for every entry point, because a message EmailScan accepts is a
message Receipts has to read back off disk afterwards.

A refusal is scoped to the one message: `InboxScanContext::skipOversized()` logs
it and the walk continues. Letting it out left the cursor where it was, so every
later tick walked into the same message again.

`tests/Contracts/BoundedMessageReadArchTest.php` is the guard — it scans the
provider clients and the two consuming job directories for a whole body reaching
one PHP string (`(string) $response->getBody()`, `file_get_contents(`,
`$files->get(`) and fails on a hit that is not routed through the seam.

## Related

- [Measuring write cost](measuring-write-cost.md) — why a bulk timing from the test suite is wrong.
- [SQLite write locks](sqlite-write-locks.md) — the other substrate concern on this file.
- [Ingestion pipeline](ingestion-pipeline.md) — the path most of these reads sit on.
