# Pitfalls Research

**Domain:** Local-only personal-finance / transaction-aggregator (Laravel + SQLite, multi-source ingestion, IMAP receipt scanning, cross-account chain resolution)
**Researched:** 2026-05-12
**Confidence:** HIGH for money/idempotency/IMAP/SQLite (verified via official docs + multiple sources); MEDIUM for ASN/ICS-specific quirks (verified via vendor format docs + community reports, but project-specific empirical confirmation required); MEDIUM for recurring/chain heuristics (industry patterns, no canonical reference)

---

## Critical Pitfalls

### Pitfall 1: Floating-point arithmetic on money

**What goes wrong:**
`0.1 + 0.2 === 0.30000000000000004` shows up as a balance that's off by €0.01, a recurring-payment detector that fails to match `9.99 + 9.99 + 9.99` against `29.97`, or an ICS settlement reconciler that thinks the user underpaid by €0.000003. Once written to the DB as a `float`/`REAL`, the damage is permanent — re-reading does not give back the originally-displayed value.

**Why it happens:**
PHP `float`/SQLite `REAL` use IEEE-754 binary doubles. Numbers like `0.1`, `0.01`, `99.99` have no exact base-2 representation. Developers reach for `float` because PHP autoconverts numeric strings to floats in any arithmetic context (`"9.99" + "9.99"` silently becomes a float).

**How to avoid:**
- **Storage:** SQLite `INTEGER` storing minor units (cents). EUR amounts → integer cents. USD amounts → integer cents. JPY → integer yen (no minor unit). Use a separate `currency` column (ISO 4217) so the scale is unambiguous.
- **Arithmetic:** Never operate on amounts as PHP floats. Either keep them as integers throughout, or use `brick/money` (Money pattern) which wraps amount+currency and forbids cross-currency operations.
- **Display only:** Convert integer cents → formatted decimal at the view layer, never round-trip back through float.
- **DB type discipline:** Forbid `REAL`/`FLOAT` columns for any amount. A simple migration-time grep gate prevents regressions.

**Warning signs:**
- Any column named `amount`, `total`, `balance`, `fee` with type `REAL`, `FLOAT`, `DOUBLE`, or `decimal` without explicit precision.
- Any `==` comparison on monetary amounts (should be exact-integer or `bccomp`).
- Sums that drift by 1-cent across long reports.
- "Off by 0.0000001" in test snapshots — a smoking gun that floats leaked in.

**Phase to address:**
**Schema / foundation phase (first).** Once a single transaction is persisted as float, everything downstream inherits the precision loss. This must be locked in before any importer is written.

---

### Pitfall 2: Unstable transaction identity for idempotent imports

**What goes wrong:**
The user re-uploads the same ASN CSV (overlap month) and gets duplicate rows. Or the user uploads MT940 for January and CAMT.053 for the same January — same transactions, different formats — and the importer treats them as new. Or the bank silently changes the free-text description ("ALBERT HEIJN 1234" → "AH AMSTERDAM CENTRUM") between two exports of the same period, and a hash that includes description text breaks.

**Why it happens:**
Naive identity = `hash(date, amount, description)`. But:
- Description text is **not stable** across exports for the same logical transaction. ASN/ICS rewrite, truncate, or reformat narratives.
- Two formats (MT940 vs CAMT.053) of the same period contain the same transactions with **different field representations** — CAMT carries structured fields MT940 lacks.
- PayPal exports the same transaction with Gross/Fee/Net on **one line**, but a separate "Fee" pseudo-row exists in some report variants.
- iDEAL/SEPA transactions have a stable `EndToEndId` (CAMT) or counterparty IBAN + reference, but MT940 only exposes a subset.

**How to avoid:**
- **Layered identity:** Each transaction gets a stable `external_id` from the **most-specific available field**, in priority order:
  1. CAMT `EndToEndId` / `AcctSvcrRef` / `TxId` if present
  2. MT940 Tag 61 reference + Tag 86 structured subfields (counterparty IBAN + remittance reference)
  3. PayPal `Transaction ID` column (always present)
  4. ICS card transaction reference (where exported)
  5. Last resort: `hash(date, amount_cents, currency, counterparty_iban_or_account)` — **NEVER** include free-text description in the fallback hash.
- **Per-source dedup keys:** Store `source` + `external_id` as a unique constraint. Same logical transaction appearing in MT940 and CAMT for the same account = recognize via a second-pass reconciler that matches `(account, date, amount_cents, counterparty_iban)` and merges, preferring the richer source.
- **Cross-format reconciliation:** Treat MT940 and CAMT as **two views** of the same ledger, not two sources. Pick one as canonical per account; use the other only to enrich (CAMT structured fields fill gaps in MT940 records).
- **Re-import safety:** Always upsert by `(source, external_id)`, never insert.
- **Audit trail:** Keep an `imports` table recording filename + sha256 + range. Re-uploading the exact same file is a no-op; uploading a different export covering the same period merges per the rule above.

**Warning signs:**
- Duplicate counts after re-import (should always be zero new transactions for the same file).
- A unit test of "re-import January twice" not present in the suite.
- Identity logic that touches the description string in any way.
- No `unique(source, external_id)` index in migrations.

**Phase to address:**
**First ingestion phase (single source).** Lock down identity contract before adding the second source. Adding a source later is easy; retrofitting a stable-identity contract across legacy data is painful.

---

### Pitfall 3: PayPal CSV reconciliation horror — fees, holds, and currency conversion as separate rows

**What goes wrong:**
PayPal CSV exports the same logical purchase as **3-5 rows**: the gross payment, a fee row, a currency-conversion row, sometimes a hold/release pair, and sometimes a "Transfer to bank" row when the balance is later swept. A naïve importer that treats every row as a transaction either:
- Double-counts (gross + fee both recorded as outflows when fee is already part of gross)
- Counts a temporary hold and the corresponding release as two real transactions
- Loses the FX information by recording only the EUR net
- Records "Transfer to bank" as a fresh outflow when it's actually an internal movement that should link to the ASN/ICS incoming row

**Why it happens:**
PayPal's CSV is **an event log, not a transaction log**. Each accounting-relevant event is one row. The grouping is implicit via the `Transaction ID` / `Reference Txn ID` columns. Most importers don't read `Reference Txn ID`.

**How to avoid:**
- **Group by `Transaction ID` and walk `Reference Txn ID` chains.** A "Fee" or "Currency Conversion" row references its parent payment's Transaction ID. Roll them up into a single logical transaction record with `gross`, `fee`, `fx_rate`, and `net` fields.
- **Preserve original-currency amounts.** Foreign payments have a `Currency` column ≠ EUR; the EUR settled amount is on a sibling row. Both belong on the unified transaction (`original_amount`, `original_currency`, `settled_amount_eur`).
- **Filter event types:** Skip `Authorization`, `Hold`, `Reserve`, `Reversal of General Account Hold` — they're informational, not real money movements. Keep `Express Checkout Payment`, `General Withdrawal`, `Refund`, `Mass Pay`, `Currency Conversion` (as enrichment).
- **"Transfer to bank" rows are funding-chain links, not transactions** — surface them to the chain resolver so they match against the ASN incoming credit.
- **Empirically validate:** First step on any new PayPal export is a test that says "sum of net amounts equals the closing balance minus opening balance." If it doesn't, the import is wrong.

**Warning signs:**
- Monthly PayPal totals that don't reconcile against the displayed PayPal balance.
- Fee rows visible as separate "transactions" in the UI.
- Foreign-currency PayPal purchases that show as exactly EUR (FX info dropped).
- "Transfer to bank" appearing as a regular expense.

**Phase to address:**
**PayPal ingestion phase.** Plan it as its own substantial slice; PayPal is the highest-complexity source after MT940 parsing.

---

### Pitfall 4: ICS bulk-settlement reconciliation collapses

**What goes wrong:**
ICS Cards sends a monthly statement (€523.47) and the user pays a round number via iDEAL (€525.00) — or pays the exact amount but on a different date than the statement period boundary. The reconciler either:
- Can't match because amounts differ by €1.53
- Matches the wrong statement period (paid in February for the January statement)
- Splits one payment across two statements when the user prepays
- Doesn't account for refunds that arrived after the statement closed, leaving "phantom" unmatched ICS transactions

Compounded: the user occasionally makes **multiple smaller iDEAL payments** in one month against one statement, or one payment that covers two statements after a missed month.

**Why it happens:**
The ICS → ASN iDEAL settlement is an **unstructured lump sum**. ASN sees one outgoing transaction with no per-purchase specification. The relationship is purely temporal and amount-based, and only after-the-fact.

**How to avoid:**
- **Model ICS statements as first-class entities.** A `card_statement` has a period, line items, and a total. Settlements are linked to statements (many-to-many: a payment can cover part of a statement, a statement can be paid in multiple installments).
- **Tolerant matching with explicit handling of rounding/overpayment.** Match candidates within ±€5 or ±2% of statement total within a ±10-day window. Surface ambiguity to the user; don't auto-decide.
- **Track unsettled balance per statement.** A statement is `open` until linked settlements sum to the total ± tolerance. The "next ICS payment forecast" is the current open balance.
- **Treat overpayments as carry-forward credit** on the next statement, not as a reconciliation failure.
- **Refunds after statement close** stay attached to the statement they belong to (purchase date), but flow into the *next* settlement amount. Make this explicit in the model.

**Warning signs:**
- A boolean `is_settled` column on statements (insufficient — settlement is partial, multi-payment, or over/under).
- Reconciliation logic that uses exact-amount match.
- No concept of "open ICS balance" in the data model.
- "Phantom" small ICS transactions never linked to a settlement.

**Phase to address:**
**Chain-resolution / settlement phase.** Build after both ASN and ICS sources are flowing reliably; this is the hardest cross-source piece.

---

### Pitfall 5: PHP 8.4+ IMAP extension removal breaks ingestion silently

**What goes wrong:**
The project starts on PHP 8.3 with `ext-imap`, ships, then a routine `brew upgrade php` in a year drops the user onto PHP 8.4 where `ext-imap` is no longer bundled in core (moved to PECL). IMAP scanning silently fails on the next launch, or worse, the entire Laravel app refuses to boot due to a missing extension dependency. The user finds out weeks later when a forecast is wrong because no receipts have been ingested.

**Why it happens:**
The legacy `ext-imap` is built on `c-client` (unmaintained ~20 years). PHP 8.4 unbundled it. It still exists on PECL but is a maintenance dead-end. Some pure-PHP libraries (`webklex/php-imap`, `webklex/laravel-imap`) historically defaulted to the native extension when available and only fell back to their pure-PHP path otherwise.

**How to avoid:**
- **From day one, use a pure-PHP IMAP path.** `webklex/laravel-imap` supports configuring the protocol/driver — explicitly select the pure-PHP socket implementation, not the native `ext-imap` driver. This makes the dependency `composer.json`-only, with no PECL/native build needed.
- **CI matrix on PHP 8.3 and 8.4** (and 8.5 when it lands) — failing the build on PHP version drift catches the regression before deploy.
- **Document the install requirements** in a SETUP file that explicitly says "no ext-imap needed."
- **Detect at boot:** A `php artisan diagnose` command that checks the IMAP driver actually opens a connection — surfaces failures *now* rather than in a midnight cron.

**Warning signs:**
- `composer.json` has `"ext-imap": "*"` in `require`.
- Webklex config has the native driver selected (or unset).
- No PHP-version constraint on the project.
- IMAP scanning works in dev but the install doc says "make sure PHP imap extension is enabled."

**Phase to address:**
**Email-ingestion phase (or earlier, when picking IMAP library).** Cheap to do right from the start; expensive to migrate after.

---

### Pitfall 6: IMAP rate-limiting and lockouts on historical backfill

**What goes wrong:**
The user kicks off "scan the last 3 months of email" (per project requirements) or, ambitious, "scan the last 5 years." The importer fans out 20 parallel connections, downloads everything, and Gmail/iCloud:
- Throttles to ~1 op/sec
- Closes the connection mid-fetch
- Temporarily locks the account (Gmail: 1–24h)
- Triggers a "suspicious activity" security warning email
- Burns through the 2.5 GB/day Gmail bandwidth cap

The importer treats partial completion as final, and the user has a half-ingested history with no obvious way to resume.

**Why it happens:**
Gmail allows up to 15 simultaneous IMAP connections per account, but with hard bandwidth limits (2.5 GB/day download, 500 MB/day upload). Third-party clients that aggressively download history are the **primary trigger** for rate-limiting. iCloud and Outlook have similar but undocumented limits.

**How to avoid:**
- **Single connection per account, sequential UID fetch.** Don't parallelize.
- **Server-side filtering before fetch:** `SEARCH` on date range + known sender domains, then fetch only matching UIDs. Don't `FETCH ALL`.
- **Fetch envelopes + structure first, bodies only when matched.** Most messages can be classified or rejected from headers alone.
- **Persistent UID + UIDVALIDITY state per folder.** Resume from last-seen UID, never re-scan from scratch. If UIDVALIDITY changes, do a re-baseline pass with explicit user warning.
- **Backoff on errors:** Exponential backoff on socket close, `NO`, `BYE`, or "too many simultaneous" responses. Cap at 5-minute intervals, never retry-loop.
- **Background queue, not synchronous job.** A web request that triggers "scan 5 years" must enqueue and return; the queue worker drips through.
- **Visible progress + resumability** — UI shows "1,234 / 50,000 messages scanned, last UID 89234." Aborting and restarting picks up where it left off.

**Warning signs:**
- Synchronous HTTP request that scans IMAP.
- No "last UID" persistence per folder.
- Parallel connections to the same account.
- No backoff on connection errors — straight retry loop.
- "It worked on 100 emails" but never tested against 10,000.

**Phase to address:**
**Email-ingestion phase, before first real backfill.** Test with a real Gmail inbox at 10k+ messages or you will not encounter the failure modes.

---

### Pitfall 7: HTML email parsing fragility (per-locale, per-sender)

**What goes wrong:**
A receipt parser tuned on "Bedankt voor uw bestelling — €19.99" works perfectly until Spotify changes its email template, or sends the same user an English receipt because they switched their account language, or wraps the amount in a CSS-styled `<span>` that splits "€" and "19.99" across two text nodes. The parser silently extracts the wrong number (often €0.00 or the tax amount instead of the total).

**Why it happens:**
- HTML receipts are **marketing templates first, data carriers second**. Senders restructure them without notice.
- Locale variants of the same template differ in number format (`1.234,56` NL vs `1,234.56` EN), currency symbol placement, and date format.
- Many senders use ZWSP/NBSP characters that break naive regex.
- Some senders include `text/plain` parts that are far easier to parse — many importers ignore it in favor of HTML.
- Tracking-pixel and `<style>` blocks contain text that confuses regex matching.

**How to avoid:**
- **Prefer `text/plain` MIME part when available.** Fall back to HTML only when absent.
- **Per-sender extractors, not a universal one.** A small registry of `{sender_domain → extractor}` is honest about reality. Extractors should be small, testable classes with a recorded test fixture per real email.
- **Locale-aware number parsing.** Detect format from amount string (`,` vs `.` as decimal separator); never assume.
- **DOM extraction, not regex.** Use `Symfony\DomCrawler` against the rendered HTML. Find by structure (table cells with known labels) and surrounding text, not by raw regex.
- **Confidence score per extraction.** Below threshold → store the email as "unparsed receipt, awaits user mapping" rather than silently writing garbage.
- **Snapshot test corpus.** Save real (anonymized) email bodies as fixtures; if an extractor's output changes, the test fails before the bad parse hits the DB.
- **User-confirmable parses:** Show "We extracted €19.99 — is this right?" for low-confidence cases, and feed corrections back into the extractor's training data.

**Warning signs:**
- One regex trying to match all senders.
- No fixture-based tests of real email bodies.
- Parser writes a transaction even on partial extraction.
- No `unparsed_email` table.

**Phase to address:**
**Email-ingestion phase, second slice (after IMAP fetch works).** Start with 2-3 high-volume senders (e.g., Spotify, iCloud receipts), validate the pattern, then expand.

---

### Pitfall 8: Recurring detection that punishes legitimate change

**What goes wrong:**
Spotify charges €10.99 monthly, then announces a price hike to €11.49. The naïve detector either:
- Treats it as a **brand-new** recurring series (fragmenting the history)
- Fails to detect it at all (no 3-occurrence streak at the new amount yet)
- Treats it as a one-off
- Worse: the user cancels and rejoins at a promotional rate, and the detector treats the gap as termination.

Other variants: annual subscriptions detected as one-offs (no 3-month pattern), day-of-month drift (charge on the 1st falls on a weekend and posts on the 3rd), trial → paid transitions (€0.00 then €9.99 — different "series" to a naive detector), and bi-monthly utilities that miss the threshold.

**Why it happens:**
"Recurring" is usually implemented as "same amount, same cadence, ≥N occurrences." All three components fail in practice. Real-world recurrence is fuzzy on **all axes simultaneously**.

**How to avoid:**
- **Cluster by merchant identity first, amount second.** A merchant key (normalized counterparty IBAN, or normalized merchant string for cards) is far more stable than amount. Recurrence is "this merchant charged me at roughly-monthly intervals," with amount as a property of each occurrence, not a key.
- **Tolerate amount drift up to ~25%** within a series, with each drift event flagged as "price change detected (Y/n)" for user confirmation. Most price hikes are sub-25%; promo rates often are bigger but rare.
- **Cadence as a window, not a fixed period.** "Roughly every 28-35 days" for monthly, "roughly every 11-13 months" for annual. Day-of-month drift (1st vs 3rd) is normal.
- **Annual detection needs ≥18-24 months of history** before the second-occurrence confirms. Until then, mark candidates as "possibly annual" without false certainty.
- **Trials are explicitly modeled.** A €0 or sub-€1 charge from a sender that then bills a real amount in 7-30 days is a recognized trial→paid pattern. Don't fragment series across this transition.
- **User-correctable series membership.** Every detected series has a "this isn't recurring" / "this charge isn't part of this series" affordance. Feed corrections back.
- **Don't silently auto-classify.** New series get surfaced as "we think this is recurring — confirm?" rather than baked into forecasts on day one.

**Warning signs:**
- A `recurring_amount` column (singular) on a recurring series — implies amount is fixed.
- Series identified by `(merchant, amount)` rather than just `merchant`.
- No concept of "series occurrences" with per-occurrence amount.
- Annual subscriptions absent from the recurring view even after 13 months.

**Phase to address:**
**Recurrence-detection phase.** Build on top of a stable merchant-normalization layer. Don't ship before that layer exists.

---

### Pitfall 9: Cross-source matching breaks on merchant-name + FX divergence

**What goes wrong:**
PayPal records "Netflix.com" at €9.99. ICS records the same charge as "NETFLIX 866-579-7172 LOS GATOS US" at €10.07 (FX spread). The matcher either:
- Doesn't find a match (string distance too high, amount differs)
- Matches the wrong PayPal/ICS pair (a different €9.99 Netflix from last month)
- Matches but mis-attributes the funding source (PayPal → ASN when actually PayPal → ICS)

Refunds and partial settlements make it worse: a €30 purchase refunded €10 later. Now there's an outflow on one source and a partial inflow on another, with no obvious matching key.

**Why it happens:**
- Card networks rewrite merchant descriptors per-issuer (ICS especially adds location + phone).
- PayPal's intermediary role means the underlying card sees "PAYPAL *MERCHANTNAME" — sometimes truncated.
- FX is applied at different points: PayPal converts to EUR on its side; ICS converts at its rate, sometimes with a spread; both record EUR amounts that differ by 0.5-3%.
- Refunds reference the original transaction in PayPal but appear as standalone credits in ICS.

**How to avoid:**
- **Multi-key matching with explicit confidence.** A match candidate has scores for:
  - Date proximity (within ±3 days)
  - Amount proximity (within ±5% to tolerate FX spread)
  - Merchant string similarity (after aggressive normalization — strip phone numbers, location codes, "PAYPAL\*" prefix, lowercase, alphanumeric-only)
  - Reference-ID match (PayPal Transaction ID sometimes appears in ICS narrative — exploit it)
- **Auto-confirm only above a high threshold** (e.g., all four signals positive). Otherwise surface as "candidate match — confirm?"
- **Learn from confirmations.** A confirmed `(PayPal merchant, ICS descriptor)` pair becomes a known alias; future matches for that pair auto-confirm. **This is the project's explicit "learning loop" requirement and must persist past resets.**
- **Refund handling:** Model refunds as linked to their original transaction (negative amount, parent reference). Surface "unmatched refund" as a UI category rather than silently leaving it floating.
- **Never delete a non-match.** Keep low-confidence candidates persisted; the user may eventually confirm them.

**Warning signs:**
- A single boolean "matched" instead of confidence + candidate set.
- Exact-amount matching only.
- No merchant-alias / merchant-normalization table.
- The "learning loop" is not actually loop-shaped — corrections don't change future matches.

**Phase to address:**
**Chain-resolution phase.** Requires categorization/normalization to exist first; this is where it pays off.

---

### Pitfall 10: SQLite backups taken mid-write produce corrupt copies

**What goes wrong:**
The user sets up Time Machine, or a `cron` that copies `database.sqlite` to iCloud Drive, or just `cp database.sqlite backup.sqlite` from a terminal. With WAL mode enabled (the recommended default for Laravel/SQLite), the database is split across **three files** (`database.sqlite`, `database.sqlite-wal`, `database.sqlite-shm`). A plain `cp` of just the `.sqlite` file mid-write either:
- Misses committed-but-not-checkpointed transactions (data loss)
- Captures an inconsistent state (corrupt restore)
- Works fine "most of the time," failing silently when it matters

**Why it happens:**
WAL puts new pages in the `-wal` file until a checkpoint moves them into the main DB. Filesystem-level snapshots that don't capture all three files together are inconsistent. Time Machine's atomicity guarantees don't extend to "atomic across three open files mid-write."

**How to avoid:**
- **Use SQLite's online backup, not filesystem copy.** Either:
  - `sqlite3 database.sqlite ".backup /path/to/backup.sqlite"` — handles WAL correctly
  - Laravel command using `PDO` + the online backup API (or a wrapper like `staudenmeir/sqlite-backup`)
  - `VACUUM INTO '/path/to/backup.sqlite'` — produces a single-file consistent copy
- **Run a `php artisan db:backup` on a daily schedule** that writes a timestamped, consistent `.sqlite` to a backup dir. Filesystem backups (Time Machine) then snapshot the *backup directory*, where files are quiescent.
- **Forbid plain `cp` in operator docs.** State explicitly: never copy the live DB while the app is running.
- **Verify backups by reopening them.** A nightly job that opens the latest backup, runs `PRAGMA integrity_check`, and emails on failure.
- **Checkpoint regularly.** `PRAGMA wal_autocheckpoint` defaults are usually fine; explicit `PRAGMA wal_checkpoint(TRUNCATE)` before backup keeps WAL bounded.

**Warning signs:**
- A backup strategy that's just "the file is in iCloud Drive."
- No restore-test in the project — never opened a backup.
- WAL file grows unbounded (no readers leaving gaps, or no checkpoints).
- `PRAGMA journal_mode` not explicitly set.

**Phase to address:**
**Foundation phase (DB setup).** Backup-by-design before there's data to lose.

---

### Pitfall 11: Schema decisions that make single-user → multi-user a migration nightmare

**What goes wrong:**
v1 ships with no `user_id` on `transactions`, `card_statements`, `recurring_series`, `categories`, `merchant_aliases`, etc. — because there's only one user, why bother? Two years later, the user adds a partner. Now every query needs scoping, every table needs `user_id` backfilled (which one is "the original" for shared transactions?), every category mapping is global instead of per-user, and the URL structure assumes single-tenant. A six-month rewrite is the most likely outcome — or the feature is silently dropped.

**Why it happens:**
"YAGNI" applied without nuance. Single-user is the truth today, so why pay the cost? But the cost of a nullable `user_id` column with a default seed value is tiny; the cost of retrofitting it across 30 tables and every query is enormous.

**How to avoid:**
- **Add `user_id` to every domain table from day one**, defaulting to a single seed user (id=1). Cheap now, free later.
- **Add a global query scope** (Laravel `BelongsToUser` trait) that filters by `auth()->id()`. In single-user mode it's a no-op; in multi-user mode it's the security boundary.
- **No global lookups for user-scoped data.** `MerchantAlias::where('pattern', $x)->first()` becomes `auth()->user()->merchantAliases()->where(...)`. Even with one user.
- **Auth from day one, even trivially.** A single hardcoded "owner" user with a password is fine; what's not fine is `Auth::loginUsingId(1)` injected on every request with no concept of identity.
- **Don't share IMAP / category / merchant-normalization data across users.** Each user's normalization should be private; some users want "STARBUCKS" categorized as "coffee," others as "treat."
- **What stays global:** Currency tables, the merchant *registry* (without user-specific mappings), MT940 parser config. Things that are domain truth, not user preference.

**Warning signs:**
- `transactions` table has no `user_id`.
- Routes look like `/transactions/123` not `/users/{user}/transactions/123` or scoped via auth middleware.
- Service classes that take user-specific data without a user param.
- "We'll add multi-user later" with no design document for it.

**Phase to address:**
**Foundation / schema phase.** This is the hardest pitfall to retrofit. It costs almost nothing to prevent and almost everything to fix.

---

## Moderate Pitfalls

### Pitfall 12: Storing amounts in inconsistent scales

**What goes wrong:**
One importer stores `1.00` (decimal string), another stores `100` (integer cents), a third stores `1.0` (float). Reports sum across them and produce nonsense. Or one writes `€1.00` and another `1.00 EUR` and currency comparison fails.

**Why it happens:**
Different importers written at different times, different mental models. Lack of a centralized "amount" type/value-object.

**How to avoid:**
- Single internal representation: integer minor units + ISO 4217 currency code. Period.
- All importers funnel through a single `Money::fromExternal()` factory that normalizes.
- Database CHECK constraint: `amount_minor IS NOT NULL AND currency IS NOT NULL`.

**Warning signs:**
Mixed types in `amount` columns across migrations; ad-hoc parsing in importer code.

**Phase to address:** Schema phase.

---

### Pitfall 13: Laravel scheduler/queue silent failures on a local machine

**What goes wrong:**
The user closes their laptop, `cron` doesn't run while it's asleep, scheduled IMAP scans don't happen. Or `queue:work` is supposed to be running but isn't — the user starts it manually once and never again after reboot. The scheduler logs say "ran" but the queue never processed the dispatched job. The user thinks the app is working; it's silently doing nothing.

**Why it happens:**
- Local machines aren't always on.
- `php artisan schedule:run` requires a `cron` entry that the user must install — easy to miss.
- The scheduler dispatches jobs to the queue but doesn't run them; if `queue:work` isn't supervised, jobs queue up and nothing executes.
- The default Laravel config uses the `sync` queue driver in some scaffolds, which executes inline and masks the real architecture.

**How to avoid:**
- **`launchd` plist or `supervisord`, not raw `cron`.** macOS-native job control survives reboots, restarts on crash. Document it as part of setup.
- **Use `queue:listen` or `queue:work --max-time` in a supervised loop.** Auto-restart on death.
- **Health-check endpoint** (`/health/queue`, `/health/scheduler`) that the dashboard surfaces — "last successful scan: 3 hours ago" front-and-center.
- **Schedule cadence accepts gaps.** A "scan IMAP every 4 hours" schedule that missed yesterday catches up next time, not "scan everything I missed when I wake up."
- **`schedule:work` for dev only.** Document the prod-vs-dev difference.

**Warning signs:**
- No supervised process for queue worker.
- No "last run" timestamp visible to user.
- Setup doc doesn't mention `launchd` / supervisor.
- `QUEUE_CONNECTION=sync` in committed `.env.example` (masks all queue issues).

**Phase to address:** Operationalization phase (deploy/run setup), before any "ingest in background" feature ships.

---

### Pitfall 14: `.env` and IMAP credentials leaking via accidental commits or backups

**What goes wrong:**
The user adds IMAP app-passwords to `.env`, the file is committed to git in a moment of haste (or via a `.env.example` that was copied wrong), and credentials hit GitHub. Or `.env` is in the project root and Time Machine / iCloud Drive sync picks it up unencrypted. Or the SQLite DB containing financial history is in iCloud Drive's path.

**Why it happens:**
- `.env` is in `.gitignore` by default, but `git add -f` or `.env.local` or environment-specific variants can slip through.
- macOS dev directories often live inside `~/Documents` or `~/Desktop`, both iCloud-sync'd by default if iCloud Drive is enabled.
- The Laravel default doesn't enforce filesystem permissions on `.env`.

**How to avoid:**
- **Pre-commit hook** that scans staged files for high-entropy strings or known secret patterns (e.g., `truffleHog`, `gitleaks`). Block commit.
- **Project lives outside iCloud-sync'd paths.** `~/Development/diederik` (not `~/Documents/...`). Documented explicitly.
- **`.env` chmod 600** at install time, enforced by an `install` artisan command.
- **Document the threat:** the setup README states explicitly "this is a finance app; do not put it in iCloud Drive, Dropbox, or any sync'd folder."
- **Encryption at rest as an option.** `Crypt::encrypt()` the IMAP password field in config, with the encryption key in a separate file or macOS Keychain. Acceptable to defer, but the file-permission discipline is non-optional.
- **Browser-level**: set `Cache-Control: no-store` on all authenticated routes. Disable autocomplete on sensitive forms. No localStorage / IndexedDB for transaction data (use session/server state only).

**Warning signs:**
- `.env` writable by other users (`chmod 644` or worse).
- Project path under `~/Documents` or `~/iCloud Drive`.
- No pre-commit hook for secrets.
- Sensitive pages cacheable by the browser.

**Phase to address:** Foundation phase; documented in setup; pre-commit hook before first real `.env`.

---

### Pitfall 15: "What-if" forecast mutations leaking into persisted state

**What goes wrong:**
The user opens a what-if view and "cancels" a subscription to see the forecast impact. They navigate away. Later they notice the subscription is gone from the real forecast — the what-if mutation accidentally persisted. Or the forecast cache has stale data because new transactions arrived but the cache wasn't invalidated, so the dashboard shows yesterday's prediction as today's truth.

**Why it happens:**
- Eloquent models are mutable; modifying one in a "scenario" service that doesn't explicitly clone state is one `->save()` away from disaster.
- Forecast computations are expensive, so they're cached. Cache invalidation on "any new transaction" is easy to forget.

**How to avoid:**
- **What-if scenarios live in memory only.** A `Scenario` value object that wraps a list of mutations applied to a base forecast. Never has a `save()` method. Never touches Eloquent.
- **Forecast cache keyed on `max(transactions.updated_at)`** so any new data invalidates automatically. Cheap to compute, eliminates a class of stale-prediction bugs.
- **Visual indicator** of "as-of" time on every forecast — "Forecast as of 2 minutes ago" — makes staleness visible to the user before it becomes a trust problem.
- **Architectural separation:** a `RealForecast` and `ScenarioForecast` are different types. The compiler/IDE enforces non-mixing.

**Warning signs:**
- A `Forecast` model with both real and scenario data.
- Cache TTLs without invalidation on transaction insert.
- No "as-of" timestamp visible to user.
- Scenario UI that has a "save" button anywhere.

**Phase to address:** Forecasting phase.

---

### Pitfall 16: Auto-categorization that never gets corrected

**What goes wrong:**
The categorizer guesses "Albert Heijn" → "Groceries" on the first transaction. The user agrees, never corrects. Six months later, a different "AH To Go" purchase is categorized as "Groceries" but should be "Travel" (it was at a train station). The user grumbles, edits this one transaction. Next month, same merchant, same wrong category. The "learning loop" doesn't actually loop.

**Why it happens:**
Implementations often record corrections at the transaction level rather than at the rule level. The user fixes one transaction; the rule that produced the wrong category is untouched.

**How to avoid:**
- **Corrections update the rule, not (just) the transaction.** "User changed this merchant's category from Groceries to Travel" → propose updating the rule for *future* transactions: "Apply this to similar transactions? (Y / only this one / never)."
- **Per-merchant rules with context.** A merchant has a default category, but rules can match on amount range, day-of-week, or location context (when present).
- **Surface uncertainty.** When the categorizer is below 80% confidence, show the suggestion as a chip, not as a committed category. User taps to confirm.
- **Reversibility.** "I edited this rule" → undo. Without undo, users won't experiment.

**Warning signs:**
- Corrections affect single transactions only.
- A "global merchant → category" map without per-context rules.
- No notion of categorizer confidence.

**Phase to address:** Categorization phase.

---

### Pitfall 17: Migration safety with historical data

**What goes wrong:**
The user has 3 years of imported transactions. A migration in v0.7 changes `amount` from `REAL` to `INTEGER` (cents). The migration converts existing data with `cast(amount * 100 as integer)` — but float→int truncation silently corrupts amounts like `0.299999`. Or worse: the migration is destructive (drops a column) and there's no backup, no rollback path, no dry-run.

**Why it happens:**
- SQLite has limited `ALTER TABLE` support; "destructive" migrations are common (create new table, copy, drop old).
- Float-to-int conversions are not lossless.
- Local-app developers rarely think "production data exists" — but for the user, *all data is production*.

**How to avoid:**
- **Every migration creates a backup automatically.** A migration hook that runs `VACUUM INTO` before the schema change. If the migration fails, the backup is the rollback.
- **Test data conversion at scale.** A test fixture with thousands of representative real-shape transactions, run through every migration in sequence, asserting invariants (total cents == original total * 100, no nulls, etc.).
- **Forward-only migrations with explicit data integrity assertions** at the end of each migration: "after this migration, total_amount equals X."
- **Never store floats**, period (Pitfall 1). Eliminates an entire class of conversion migrations.
- **Dry-run mode.** `php artisan migrate --pretend --dry-run-data` (custom) reports what would change without writing.

**Warning signs:**
- Migrations that change column types without a backup step.
- No data-shape tests.
- A migration in the history that did float→int conversion (audit it; it's likely lossy).

**Phase to address:** Foundation phase (migration discipline); ongoing.

---

### Pitfall 18: Chain visualization that's pretty but useless

**What goes wrong:**
The dashboard renders an elegant Sankey diagram of "Income → ASN → PayPal → Netflix" with curved gradients. It's screenshot-worthy. The user looks at it, says "neat," and never opens it again. It doesn't tell them anything actionable: not "is this normal?", not "is something missing?", not "what should I do?"

**Why it happens:**
Designers (developer or otherwise) confuse "visualizing the data we have" with "answering the user's question." The user's question is rarely "show me the structure"; it's "tell me if I should worry."

**How to avoid:**
- **Each chain view answers a specific question.** "Why was this month's ICS bill €40 higher than last month?" → show the chain *highlighting the deltas*. Not the static structure.
- **Affordances at every node.** Each merchant in the chain has a "cancel this" / "categorize this" / "remind me before next charge" action. Visualization is a navigation tool, not a destination.
- **A glance-first dashboard.** Numbers that tell a story ("€127 in fixed payments this month, €12 more than last month, driven by Spotify increase"). The chain is a drill-down, not the headline.
- **Validate with the actual user** (this is a solo project — the developer is the user): "Did this view change a decision I made today?" If not, simplify or remove.

**Warning signs:**
- A complex visualization with no action affordances.
- Time spent on viz polish before the dashboard answers basic questions.
- No "did the user click anything?" usage data even informally.

**Phase to address:** Dashboard phase; iterate based on use.

---

### Pitfall 19: Cash-flow forecast that overstates its own accuracy

**What goes wrong:**
The forecast says "you'll have €1,234.56 on May 31st." The user trusts it (precision implies confidence). On May 28th the bank charges a surprise €40 fee and the forecast was off by 3%. The user loses trust in *all* future forecasts.

**Why it happens:**
Forecasts derived from "recurring charges + recurring income" present a single-point estimate. Real variance is invisible. Surprise charges, one-offs, and timing drift all add real uncertainty that the UI doesn't surface.

**How to avoid:**
- **Show a range, not a point.** "Estimated €1,180 – €1,260 on May 31st (based on 6 months of variance)." Honesty builds trust.
- **Confidence bands narrow over a shorter horizon.** Tomorrow's forecast is tight; next month's is wider.
- **Annotate what's included.** "This forecast covers known recurring charges. Excludes ad-hoc spending (avg €X/week)."
- **Round to round numbers in display.** €1,234.56 is false precision. €1,200 is honest.
- **Variance from past prediction visible.** "Last month we predicted €1,300, actual was €1,247 (4% over)." Builds calibration.

**Warning signs:**
- Single-point forecasts to the cent.
- No variance-from-prediction tracking.
- No "what's not included" caveat.

**Phase to address:** Forecasting phase.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|---|---|---|---|
| Skip `user_id` on tables, "we're single-user" | Less ceremony in queries | Full schema rewrite when adding partner | **Never** — this is the most-expensive shortcut to undo |
| Store amounts as `REAL` / float | Easier debugging in `php artisan tinker` | Corrupted balances, broken reconciliation, no fix without re-import | **Never** for any column representing money |
| Use `BasicAuth` middleware as v1 auth | Single line of config, no login screen | No password reset, no multi-user path, accidental commits leak credentials, no audit log | Only for first-week localhost-only spike; replace before persisting data |
| Skip `Webklex` pure-PHP driver, use `ext-imap` | Works out of the box on PHP 8.3 | Breaks on PHP 8.4, requires PECL build, no maintenance | Never for new projects in 2026+ |
| Synchronous IMAP scan from HTTP request | Simple controller, no queue setup | Times out at 30s on first realistic inbox; rate-limited | Only during dev with 10-message test inbox; queue from day one of real scans |
| Single-file `database.sqlite` in project root, no backup automation | Zero infra | First serious crash loses years of data | **Never** beyond the first commit |
| One regex for all email parsing | "Looks done" in 10 min | Silent wrong extractions forever | Never for production-bound parsers; OK as exploratory tool |
| Sync queue driver (`QUEUE_CONNECTION=sync`) | No worker to run | Masks every queue architectural issue; never validates the path | Dev only, never in committed `.env.example` |
| Skip migration backups | Faster iteration | One bad migration eats years of finance data | OK during pre-data dev; mandatory once any real transaction lands |
| Recurring detection by exact-amount match | Easy to implement | Price changes fragment series; trials never link to paid | Never beyond a "v0 prototype that we'll replace" |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|---|---|---|
| ASN MT940 export | Treating `:86:` narrative as stable transaction identity | Use `:61:` reference + counterparty IBAN; treat `:86:` as enrichment only |
| ASN CAMT.053 export | Importing CAMT *and* MT940 of the same period as separate sources | Pick one as canonical per account; use the other only to fill structured fields |
| ICS Cards CSV/Excel | Assuming amounts include the FX-converted EUR value cleanly | Verify per-row: original-currency amount, FX rate, EUR settled are often in separate columns or rows; preserve all three |
| PayPal CSV | Treating every CSV row as a transaction | Group by `Transaction ID` / `Reference Txn ID`; fees and currency conversions roll up |
| PayPal "Transfer to bank" | Treated as outflow | It's a funding-chain link to ASN/ICS — match it, don't double-count |
| Gmail IMAP | Parallel connections + fetch-all on backfill | Single sequential connection, server-side `SEARCH`, persistent UID resume |
| iCloud IMAP | Treating connection limits like Gmail's | iCloud's limits are undocumented and stricter; assume worst-case, throttle harder |
| Outlook/Hotmail IMAP | OAuth fallback when app-password isn't supported | Many Outlook accounts now require OAuth; document the app-password vs OAuth path per provider |
| Webklex/laravel-imap | Default driver may use `ext-imap` if present | Explicitly configure the pure-PHP protocol driver in config |
| Google Play receipts | Parsing the email body for amount | Google Play emails have an order ID; correlate via order ID, parse amount as secondary verification |
| iDEAL settlement payments | Matching by exact amount | Match by amount within ±€5 / ±2% across ±10 days; let user confirm ambiguous cases |
| SQLite WAL backup | `cp database.sqlite backup.sqlite` while app runs | Use `.backup` or `VACUUM INTO`; never plain `cp` of a live WAL DB |
| Laravel scheduler | Trusting `php artisan schedule:list` as proof of execution | Add explicit `onFailure()` callbacks + a "last successful run" timestamp visible in UI |
| Pest vs PHPUnit | Mixed-style tests when adding a new tool | Choose one (Pest is modern default for new Laravel projects); don't add PHPUnit cases beside Pest tests without a clear seam |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|---|---|---|---|
| Loading every transaction into a single Eloquent collection for a report | First page load is 200ms, slows to 4s by month 18 | Aggregate at the DB layer (`groupBy`, window functions); paginate; lazy-load chains | ~50k transactions (≈3 years of an active user) |
| Recomputing forecasts on every page view | Dashboard feels snappy at first, becomes sluggish | Cache forecast output keyed on `max(transactions.updated_at)`; invalidate on insert | When forecast horizon exceeds 60 days or transaction set crosses ~10k |
| Fan-out IMAP fetches | First backfill seems fast, then Gmail rate-limits and locks the account | Single connection, server-side filtering, sequential UID fetch with backoff | First realistic backfill (>5k messages) |
| WAL file growth without checkpoints | DB file is 5 MB but `-wal` file is 800 MB; query latency rises | Ensure reader-gap exists or run `PRAGMA wal_checkpoint(TRUNCATE)` on idle | When long-running readers (e.g., a kept-open analytics connection) block checkpoints |
| Regex parsing every email body | IMAP scan is 30s on 100 emails, 30min on 10k | DOM extraction on filtered subset; cache parsed receipts by message-id | At first real backfill |
| Recursive merchant-alias lookups | Categorization slow as alias table grows | Index `merchant_aliases.normalized_pattern`; preload into in-memory map for batch import | When alias count crosses ~1k |
| N+1 on chain visualization | Dashboard "chain view" loads slowly | Eager-load all chain links in one query; render from preloaded tree | Once chains depth > 2 (PayPal → ASN, or ICS → settlement → multiple ICS txns) |
| Full-text scan over historical data on filter | Search by merchant takes seconds | Indexes on `(user_id, occurred_at)`, `(user_id, merchant_normalized)` from day one | ~10k transactions |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---|---|---|
| `.env` committed to git with real IMAP password | Credentials in GitHub history forever; account compromised | Pre-commit hook (`gitleaks`); `.env` chmod 600; documented threat |
| Project in `~/Documents` or `~/iCloud Drive/*` | Unencrypted finance data syncs to Apple's servers | Document "do not put this project in a sync'd folder"; install script refuses to set up under iCloud paths |
| Time Machine backup of live SQLite | Corrupted backups; financial data in (potentially shared) Time Capsule | Run app's own `db:backup` to a known dir; let Time Machine snapshot the dump, not the live DB |
| Browser cache holding transaction pages | Family member opens browser, sees finances; back button reveals data | `Cache-Control: no-store, no-cache, must-revalidate` on all authenticated routes; `Pragma: no-cache` |
| Transaction data in `localStorage` / `IndexedDB` | Persists across browser sessions; survives logout; readable by any other browser tab/extension | Don't store transaction data client-side; keep state server-side; if you must, sessionStorage only and clear on logout |
| Autocomplete on category / amount fields | Browser autofill leaks past inputs into screenshots / shared screens | `autocomplete="off"` on sensitive form fields (browsers may ignore, but worth doing) |
| Logging request bodies that include amounts | Laravel logs at INFO level retaining transaction details | Sanitize structured logs; avoid logging `$request->all()` for any importer/transaction route |
| Hard-coded "owner" user with predictable password | Anyone reaching localhost (e.g., on shared Wi-Fi via dev tunnel) gets full finance access | Real password hash; rate-limit auth; bind to 127.0.0.1 only (not 0.0.0.0); document the binding |
| Trusting localhost = secure | Tunnels, port forwarding, malicious browser extensions all reach localhost | Real auth even on localhost; CSRF protection enabled; SameSite=Strict cookies |
| SQLite DB world-readable on disk | Another user on the same machine can `sqlite3 database.sqlite` and read everything | chmod 600 the DB file; document Mac multi-user setup explicitly |
| IMAP password stored in DB | DB backups carry credentials | Per project constraint: secrets in config file outside DB; chmod 600 the config |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---|---|---|
| Sankey chain viz with no actions | Looks impressive in screenshots, opens once, never used | Each chain element has affordances (cancel, categorize, remind, drill); chain viz is navigation, not destination |
| Forecast as single-point cent precision | Trust collapses on first surprise charge | Range with explicit confidence interval; round numbers; "excludes ad-hoc spend" caveat |
| Auto-category sticks; corrections fix one row only | User edits the same wrong category every month, gives up | Corrections offer to update the rule; show categorizer confidence; surface low-confidence suggestions as chips |
| "This month at a glance" actually shows last 31 days, not the calendar month | User mental model says "May 1 – May 31"; UI says "Apr 13 – May 12" | Calendar-month default; rolling-30-day as a toggle |
| Empty state shows zeros | New user (or after data wipe) sees "€0 income, €0 spending" → "is it broken?" | Empty state explains "no transactions yet — import a CSV or scan email" with action buttons |
| Loading state for IMAP scan is invisible | User starts scan, sees nothing, kicks it again, gets rate-limited | Persistent progress UI: "1,234 / 50,000 messages scanned, ETA 8 min"; cancel button; resumable |
| Drill into chain only works for "perfect" matches | Most chain entries don't drill; user gives up | Even partial / fuzzy matches should drill — show "candidate links" and let user confirm |
| Recurring view doesn't show "next charge" | User asks "when does this hit?" — has to do math | Each recurring row shows next predicted charge date and amount |
| Currency mixing in totals | "€127" total includes a $9.99 charge converted at today's rate, not at-the-time rate | Display original currency where it matters; only sum in EUR using *at-the-time* settled amounts |
| Multi-step import wizard with no abort | User starts upload, realizes it's the wrong file, can't back out | Every step has cancel; nothing persists until confirm step |
| "Did anything change?" not surfaced after a scan | User runs scan, dashboard looks identical, doesn't know if 2 or 200 transactions were added | Post-scan summary: "Added 23 transactions, matched 18 to existing receipts, 5 await categorization" |

---

## "Looks Done But Isn't" Checklist

- [ ] **CSV importer:** Often missing **re-import test** — verify uploading the same file twice produces zero new rows.
- [ ] **MT940 parser:** Often missing **multi-line :86: handling** — verify a transaction with a 5-line narrative parses to a single transaction.
- [ ] **CAMT.053 parser:** Often missing **batch entry support** — verify a batch entry (e.g., direct debits) parses to multiple child transactions, not one aggregate.
- [ ] **PayPal importer:** Often missing **fee row roll-up** — verify a payment with a fee row imports as one transaction with `fee` populated, not two.
- [ ] **PayPal importer:** Often missing **FX preservation** — verify a $10 charge imports with `original_amount=10`, `original_currency=USD`, `settled_amount_eur=9.07`, not just `9.07`.
- [ ] **IMAP scanner:** Often missing **UID-resume** — verify aborting a scan and restarting picks up at the last fetched UID, not from scratch.
- [ ] **IMAP scanner:** Often missing **UIDVALIDITY check** — verify a folder rebuilt server-side (UIDVALIDITY changed) triggers a re-baseline, not silent corruption.
- [ ] **Email parser:** Often missing **per-locale tests** — verify a Dutch and English receipt from the same sender both parse correctly.
- [ ] **Recurring detector:** Often missing **price-change handling** — verify Spotify €9.99 × 6 then €11.49 × 1 detects as one series with price change, not two.
- [ ] **Recurring detector:** Often missing **annual cadence** — verify a yearly subscription (≥13 months) shows up.
- [ ] **Chain resolver:** Often missing **refund linking** — verify a refund on PayPal links to its original outgoing transaction.
- [ ] **ICS settlement:** Often missing **partial-payment support** — verify two iDEAL payments covering one statement both link.
- [ ] **Forecast:** Often missing **uncertainty range** — verify forecast UI shows a band, not just a number.
- [ ] **Forecast:** Often missing **invalidation on new transaction** — verify dashboard reflects newly-imported transactions immediately, not after manual refresh.
- [ ] **Categorization:** Often missing **correction-feeds-rule loop** — verify correcting one transaction's category offers to update the rule.
- [ ] **Multi-currency:** Often missing **original-currency preservation** — verify USD/GBP charges retain their non-EUR amount.
- [ ] **Backup:** Often missing **restore test** — verify the most recent backup can be opened, `PRAGMA integrity_check` returns ok.
- [ ] **Auth:** Often missing **localhost binding** — verify `php artisan serve` binds to 127.0.0.1, not 0.0.0.0.
- [ ] **Queue:** Often missing **supervised process** — verify the queue worker auto-restarts after a kill -9.
- [ ] **Multi-user readiness:** Often missing **`user_id` columns on every domain table** — `grep -L user_id migrations/*.php` should be empty.
- [ ] **Idempotency:** Often missing **`unique(source, external_id)` constraint** — verify the DB enforces it, not just the application.
- [ ] **Money handling:** Often missing **DB-level type check** — verify no column with `amount` in its name has type `REAL`/`FLOAT`.

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---|---|---|
| Floats stored as amounts | HIGH | Re-import from source CSVs/exports; convert during re-import; throw away derived state (chains, categories, recurring series) and rebuild |
| Duplicates from non-idempotent import | MEDIUM | Identify duplicates via deterministic key; write a one-shot dedupe script; add `unique` constraint going forward; re-run derived state |
| Schema lacks `user_id` everywhere | HIGH | Migration to add nullable `user_id`, backfill with seed user, then make NOT NULL; audit every query for scoping; the further along the project, the worse this gets |
| `.env` leaked to git | MEDIUM | Rotate IMAP app-passwords immediately; `git filter-repo` to scrub history; force-push (lone-developer repo only); install pre-commit hook |
| SQLite WAL backup is corrupt | HIGH (if no good backup) | Pull source data back from original CSVs/exports/email; rebuild DB from scratch; loses user-confirmed mappings (categories, chain links) unless those were separately exported |
| Rate-limited on Gmail | LOW | Wait 1-24h; rebuild scanner to use single sequential connection; resume from last UID |
| Recurring detector fragments Spotify into two series | LOW | User merges via UI; merge feeds back into detector heuristics; one-row series-membership table makes this cheap |
| Bad migration corrupts amounts | HIGH (if no backup) | Restore pre-migration backup; rerun migration with fixed conversion logic; if no backup, re-import from source files |
| Forecast cache stale, user trusts wrong number | LOW | Add cache invalidation key; the bug-recovery is fixing the cache, not the data |
| Wrong category propagated for months | LOW | Bulk-recategorize tool: "apply this category to all transactions from this merchant"; rule-based corrections going forward |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---|---|---|
| 1. Floating-point on money | Foundation / schema | Grep migrations: no `REAL`/`FLOAT` on amount columns; unit test asserting `1+2` cents = `3` cents |
| 2. Unstable transaction identity | First ingestion (ASN MT940 likely) | Re-import same file twice → zero new rows; cross-format reconciliation test |
| 3. PayPal CSV roll-up | PayPal ingestion phase | Sum of net amounts equals balance delta; fee rows not standalone |
| 4. ICS bulk settlement | Chain resolution phase | Partial-settlement test, overpayment carry-forward test |
| 5. PHP 8.4 IMAP removal | Email-ingestion phase (driver choice) | CI matrix on PHP 8.3+8.4; `composer.json` lacks `ext-imap` requirement |
| 6. IMAP rate limits / backfill | Email-ingestion phase | Test against a 10k+ message inbox; resume test from killed worker |
| 7. HTML email parsing | Email-parsing phase | Per-sender fixture corpus; locale tests (NL + EN) |
| 8. Recurring detection brittleness | Recurrence-detection phase | Price-change test, annual cadence test, trial-to-paid test |
| 9. Cross-source matching | Chain-resolution phase | Confidence-scored candidates; merchant-alias persistence; FX-tolerance test |
| 10. SQLite WAL backup | Foundation phase (DB setup) | `db:backup` artisan command; nightly `PRAGMA integrity_check` on latest backup |
| 11. Single → multi-user schema | Foundation / schema | Every domain table has `user_id`; queries scoped via trait |
| 12. Inconsistent amount scales | Foundation / schema | Single `Money` value object; importers funnel through factory |
| 13. Scheduler/queue silent failures | Operationalization phase | Health endpoint with last-run timestamp; launchd plist documented |
| 14. `.env` / DB leaks | Foundation / setup | Pre-commit hook; project path not in sync'd folder; chmod 600 |
| 15. What-if state leaking | Forecasting phase | `Scenario` type without `save()`; cache invalidation key on transaction.updated_at |
| 16. Auto-cat without correction loop | Categorization phase | Correction → rule update prompt; per-merchant rule table |
| 17. Migration safety | Ongoing (every migration) | Auto-backup before migrate; data-shape tests in CI |
| 18. Chain viz aestheticism | Dashboard phase | Affordances on every chain node; dogfood the "did this change a decision?" test |
| 19. Forecast false precision | Forecasting phase | Range display, variance-from-prediction tracking |

---

## Sources

- [IMAP extension moved from PHP Core to PECL — PHP 8.4 (php.watch)](https://php.watch/versions/8.4/imap-unbundled) — HIGH
- [PHP: Removed Extensions (official PHP manual)](https://www.php.net/manual/en/migration84.removed-extensions.php) — HIGH
- [Webklex/laravel-imap GitHub & changelog](https://github.com/Webklex/laravel-imap) — HIGH
- [Webklex/laravel-imap CHANGELOG (PHP 8.4 compat)](https://webklex.github.io/laravel-imap/CHANGELOG.html) — HIGH
- [SQLite Write-Ahead Logging (official)](https://sqlite.org/wal.html) — HIGH
- [SQLite Forum: hot backup in WAL mode by copying](https://sqlite.org/forum/forumpost/2ea989bbe9) — HIGH
- [Ensuring Consistent Backups in SQLite WAL Mode](https://sqlite.work/ensuring-consistent-backups-in-sqlite-wal-mode-without-disrupting-writers/) — MEDIUM
- [PHP Floating Point Numbers (official manual)](https://www.php.net/manual/en/language.types.float.php) — HIGH
- [brick/money library (Money pattern in PHP)](https://github.com/brick/money) — HIGH
- [Why You Should Never Use Floats for Money in PHP (Samson Ojugo, Medium)](https://medium.com/@samsonojugo/why-you-should-never-use-floats-for-money-in-php-and-what-to-use-instead-156f19f01588) — MEDIUM
- [Gmail bandwidth limits (Google Workspace official)](https://support.google.com/a/answer/1071518) — HIGH
- [Gmail Sync limits (Google Workspace official)](https://support.google.com/a/answer/2751577) — HIGH
- [When Email Providers Change IMAP Limits (Mailbird)](https://www.getmailbird.com/email-provider-imap-limits-changes/) — MEDIUM
- [Gmail API quota / rate-limit guidance (Google for Developers)](https://developers.google.com/workspace/gmail/api/reference/quota) — HIGH
- [MT940 vs CAMT.053 Bank Statement Format Guide (invoicedataextraction.com)](https://invoicedataextraction.com/blog/mt940-camt053-bank-statement-format-guide) — MEDIUM
- [Practical Guide to CAMT.053 (sepaforcorporates.com)](https://www.sepaforcorporates.com/swift-for-corporates/a-practical-guide-to-the-bank-statement-camt-053-format/) — MEDIUM
- [MT940 documentation (mt940.readthedocs.io — community parser docs noting bank deviations including ASN)](https://mt940.readthedocs.io/en/stable/mt940.tags.html) — MEDIUM
- [PayPal CSV gist: separating Gross/Fee/Net (tobyspark)](https://gist.github.com/tobyspark/70fb97fce866afd1555b) — MEDIUM
- [PayPal Statement to CSV / reconciliation overview](https://www.statementstosheets.com/blog/paypal-statement-to-csv) — LOW
- [Laravel scheduler silent failures (mozex.dev)](https://mozex.dev/blog/17-5-laravel-scheduler-failures-that-only-show-up-in-production) — MEDIUM
- [Laravel scheduler GitHub issue thread (framework #23911)](https://github.com/laravel/framework/issues/23911) — MEDIUM
- [Subaio: how recurring-payment detection works (industry overview)](https://subaio.com/subaio-explained/how-does-subaio-detect-recurring-payments) — LOW
- Personal-experience / domain knowledge: PayPal CSV multi-row event log structure, ICS settlement asymmetry, MT940 bank-specific deviations, macOS-specific privacy concerns (iCloud Drive scope, Time Machine), Webklex driver selection — MEDIUM confidence; project-specific empirical validation required

---
*Pitfalls research for: local-only personal-finance aggregator (Laravel + SQLite + multi-source ingestion + IMAP) — "diederik"*
*Researched: 2026-05-12*
