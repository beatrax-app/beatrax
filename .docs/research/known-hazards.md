# Known hazards

The pitfall catalogue the codebase actively guards against. Each entry is a
class of failure that would silently corrupt data, fragment history, or
quietly break a feature in production. The mitigations listed are the ones
the codebase currently enforces — via schema, arch tests, runtime guards, or
operator runbooks.

Every entry cross-links to the canonical document where the rule lives. When
the rule changes, update that document; this file is a hazard atlas, not a
duplicate of the rules.

## Money math

### Floating-point arithmetic on money is forbidden

The single most-damaging permitted-by-default mistake. PHP `float` and SQLite
`REAL` use IEEE-754 binary doubles; numbers like `0.1`, `0.01`, and `99.99`
have no exact base-2 representation. Once written to the database as a float,
the damage is irreversible — re-reading does not give back the originally
displayed value, balances drift by 1 cent across reports, and recurring-payment
detectors fail to match `9.99 + 9.99 + 9.99` against `29.97`.

**Mitigations the codebase enforces:**

- Every monetary amount is stored as a `BIGINT` minor-unit column, never `REAL`
  or `FLOAT`. ISO 4217 currency code lives in a sibling column so the scale
  is unambiguous.
- All arithmetic flows through `brick/money` value objects (immutable, exact,
  cross-currency operations forbidden).
- The `NoFloatMoneyArchTest` arch invariant grep-blocks any migration adding
  a `REAL`/`FLOAT`/`DOUBLE` column whose name contains `amount` / `total` /
  `balance` / `fee`.
- Display-layer conversion (integer cents → formatted decimal) happens only
  at the view boundary; the formatted string never round-trips back into PHP.

Canonical rule: [ADR 0009 — `brick/money`](../adr/0009-brick-money-multi-currency.md).

### Inconsistent amount scales across importers

Without a single chokepoint, one importer stores `1.00` (decimal string),
another stores `100` (integer cents), and a third stores `1.0` (float).
Reports sum across them and produce nonsense.

**Mitigation:** every importer funnels through a single `Money::fromExternal()`
factory that normalises to integer minor units + currency. Database CHECK
constraints enforce `amount_minor IS NOT NULL AND currency IS NOT NULL`.

## Idempotency and identity

### Unstable transaction identity breaks re-imports

Naive identity = `hash(date, amount, description)` fails the moment the user
re-uploads an overlap month or the bank rewrites the free-text narrative
between two exports of the same period. Two formats (MT940 vs. CAMT.053) of
the same period contain the same transactions with different field
representations.

**Mitigations the codebase enforces:**

- The v3 fingerprint composer is keyed on `(user_id, account_id, posted_at, booked_at, amount_minor, currency, counterparty_normalized)` — never on free-text description.
- Each source provides an `external_id` from the most-specific available
  field, in priority order: CAMT `EndToEndId` / `AcctSvcrRef` / `TxId` →
  MT940 Tag 61 reference + Tag 86 structured subfields → PayPal Transaction
  ID → ICS card reference → fingerprint fallback.
- Cross-format re-imports enrich existing rows via a source-format rank
  (camt053 > mt940 > csv) instead of duplicating. The `enriched_from`
  append-only provenance JSON column records every touch.
- Re-uploading the same file is always a no-op — `UNIQUE (source_id, external_id)`
  at the database layer guarantees it.

Canonical pipeline: [Ingestion pipeline](../architecture/ingestion-pipeline.md).

### PayPal CSV is an event log, not a transaction log

The same logical purchase appears as 3–5 rows: gross payment, fee row,
currency-conversion row, sometimes a hold/release pair, sometimes a
"Transfer to bank" sweep. A naïve importer that treats every row as a
transaction double-counts (gross + fee both recorded as outflows when fee
is part of gross), counts holds and releases as two real transactions,
or loses FX information by recording only the EUR net.

**Mitigations:**

- The PayPal adapter groups by `Transaction ID` and walks `Reference Txn ID`
  chains. Fee and currency-conversion rows roll up into a single logical
  transaction with `gross`, `fee`, `fx_rate`, and `net` fields.
- Both original-currency and EUR-settled amounts persist on the unified
  transaction (`original_amount` / `original_currency` / `settled_amount`).
- Authorisation / Hold / Reserve / Reversal events are filtered — they are
  informational, not real money movements.
- "Transfer to bank" rows surface to the chain resolver as funding-chain
  links rather than transactions.

Canonical surface: [Ingestion — PayPal](../features/ingestion/architecture.md).

## Chain resolution

### ICS bulk-settlement reconciliation cannot use exact-match logic

ICS sends a monthly statement (€523.47) and the user pays a round number via
iDEAL (€525.00), or pays on a different date than the statement period
boundary, or makes multiple smaller payments against one statement, or one
payment that covers two statements after a missed month. Exact-amount
matching collapses on the first overpayment.

**Mitigations:**

- `card_statement` is a first-class entity with period, line items, total,
  and open balance. Settlements are many-to-many: a payment can cover part
  of a statement; a statement can be paid in multiple instalments.
- Tolerant matching: ±€5 or ±2% of the statement total within a ±10-day
  window. Ambiguity surfaces to the review queue; the resolver does not
  auto-decide.
- Overpayments become carry-forward credit on the next statement, not
  reconciliation failures.
- Refunds after statement close stay attached to the statement they belong
  to (purchase date) but flow into the next settlement amount.

Canonical surface: [Chain resolution](../architecture/chain-resolution.md).

### Cross-source matching must tolerate merchant rewrites + FX divergence

PayPal records "Netflix.com" at €9.99. ICS records the same charge as
"NETFLIX 866-579-7172 LOS GATOS US" at €10.07 (FX spread). String distance
alone fails; exact-amount matching fails; auto-confirmation on weak signals
mis-attributes the funding source.

**Mitigations:**

- Multi-key matching with explicit confidence — weighted scores for date
  proximity (±3 days), amount proximity (±5% for FX), merchant string
  similarity after aggressive normalisation (strip phone numbers, location
  codes, `PAYPAL*` prefix), and reference-ID hits when PayPal Transaction
  ID appears in ICS narrative.
- Auto-confirm only above a high threshold; everything else surfaces as a
  review-queue candidate.
- Confirmations feed a known-counterparty-IBAN alias table — future matches
  for that pair auto-confirm. Corrections persist past resets.
- Refunds model as linked to their original transaction (negative amount,
  parent reference); unmatched refunds surface as a UI category.

## Email scanning

### `ext-imap` removal silently kills IMAP ingestion

PHP 8.4 unbundled `ext-imap` (moved to PECL, backed by 20-year-unmaintained
c-client). A `brew upgrade php` in a year drops the user onto PHP 8.4 and
IMAP scanning silently fails on the next launch — the user finds out weeks
later when a forecast is wrong because no receipts have ingested.

**Mitigations:**

- The codebase never used `ext-imap`. The `email-scan` module ships against
  the Gmail API + Microsoft Graph API exclusively; `webklex/php-imap` is
  the IMAP escape hatch and runs pure-PHP only.
- A composer/lock arch invariant gates against `ext-imap` ever entering the
  dependency graph.

Canonical surface: [Email scan](../features/email-scan/architecture.md).

### IMAP rate-limiting on historical backfill

Gmail allows up to 15 simultaneous IMAP connections per account but with
hard bandwidth limits (2.5 GB/day download). Aggressive parallel
backfills are the primary trigger for rate-limiting; iCloud and Outlook
have similar undocumented limits.

**Mitigations:**

- Single connection per account, sequential UID fetch. No parallelisation.
- Server-side filtering via `SEARCH` on date range + known sender domains
  before fetching matched UIDs.
- Envelope + structure first; bodies only when matched (most messages can
  be classified or rejected from headers alone).
- Persistent `UID` + `UIDVALIDITY` state per folder. Resume from last-seen
  UID; never re-scan from scratch. `UIDVALIDITY` change triggers an explicit
  re-baseline with a user warning.
- Exponential backoff on `NO` / `BYE` / "too many simultaneous" responses,
  capped at 5-minute intervals.
- Background queue, not synchronous job. UI shows progress + last UID per
  folder.

### HTML email parsing fragility

Receipt parsers tuned on a Dutch template silently extract the wrong number
(often €0.00 or the tax amount) when the sender restructures the template,
sends in English because the user switched account language, wraps the
amount in a CSS-styled `<span>` that splits "€" and "19.99" across two text
nodes, or uses ZWSP / NBSP characters that break naive regex.

**Mitigations:**

- Per-sender extractors, never a universal one. A small `{sender_domain →
  extractor}` registry is honest about reality.
- Prefer `text/plain` MIME part when available; fall back to HTML only when
  absent.
- DOM extraction via `Symfony\DomCrawler`, not regex. Find by structure
  (table cells with known labels) and surrounding text.
- Locale-aware number parsing (detect format from the amount string; never
  assume).
- Confidence score per extraction. Below threshold → store the email as
  "unparsed receipt, awaits user mapping" rather than silently writing
  garbage.
- Snapshot test corpus of anonymised real emails per sender; an extractor's
  output change fails the test before the bad parse hits the database.

## Recurring detection

### Recurring detection that punishes legitimate change

The naïve "same amount, same cadence, ≥N occurrences" detector treats a
Spotify price hike from €10.99 to €11.49 as a brand-new series, fails to
detect annual subscriptions until the second year, mis-handles
trial → paid transitions, and breaks on day-of-month drift when the 1st
falls on a weekend.

**Mitigations:**

- Cluster by merchant identity first, amount second. The merchant key
  (normalised counterparty IBAN, or normalised merchant string for cards)
  is the recurrence axis; amount is a per-occurrence property.
- Amount drift up to ~25% tolerated within a series; each drift event
  flags as "price change detected" for user confirmation.
- Cadence as a window — "roughly every 28–35 days" for monthly, "roughly
  every 11–13 months" for annual. Day-of-month drift is normal.
- Annual detection needs ≥18–24 months of history before second-occurrence
  confirms; candidates surface as "possibly annual" without false certainty.
- Trials explicitly modelled — a €0 / sub-€1 charge from a sender that
  bills a real amount within 7–30 days is a recognised trial → paid
  pattern; the series does not fragment across the transition.
- The recurring module never auto-applies. New series surface as
  "we think this is recurring — confirm?" rather than baking into forecasts
  on day one. Canonical: [Recurring](../features/recurring/architecture.md).

## Operational data integrity

### SQLite backups taken mid-write produce corrupt copies

With WAL mode enabled, the database is split across three files
(`database.sqlite`, `database.sqlite-wal`, `database.sqlite-shm`). A plain
`cp` of just the `.sqlite` file mid-write either misses
committed-but-not-checkpointed transactions or captures an inconsistent
state. Time Machine, iCloud Drive, and `cron` `cp` jobs all hit this.

**Mitigations:**

- `php artisan db:backup` uses `VACUUM INTO`, which produces a single-file
  consistent copy regardless of WAL state.
- A pre-backup smart-skip on `PRAGMA data_version` avoids redundant writes
  when nothing has changed since the last backup.
- The backup writer chmods 0600 and writes a sidecar manifest; restore
  verifies both before touching the live database.
- Restore is a triple-rail destructive command (`--confirm
  --force-maintenance` + typed app name). The default refuses to run.
- The setup guide forbids putting the project under iCloud Drive / Dropbox
  / any sync'd folder.

Canonical procedure: [Operator recovery](../runbooks/operator-recovery.md).

### Laravel scheduler / queue silent failures on a local machine

`cron` doesn't run while the laptop is asleep. `queue:work` started
manually once and never again after reboot silently queues jobs forever.
The scheduler logs say "ran" but the queue never processes the dispatched
job. The user thinks the app works; it silently does nothing.

**Mitigations:**

- `launchd` plists for the scheduler, queue, and IMAP-idle workers — macOS
  native job control survives reboots and restarts on crash. The
  `beatrax:install --launchd` command materialises the plist templates.
- The desktop build runs the queue worker as a NativePHP `ChildProcess`
  child of the Electron main process — the worker exists exactly as long
  as the app window exists.
- `/health` surfaces "last successful scan" / "queue depth" / "failed jobs"
  front-and-centre; the `SystemAlertsBanner` lifts critical drift to the
  top of every page.
- Schedule cadence accepts gaps. A "scan every 4 hours" schedule that
  missed yesterday catches up next time; it does not back-fill on wake.
- `QUEUE_CONNECTION=sync` never lands in committed `.env.example` — it
  masks all queue issues during development.

Canonical decision: [ADR 0007 — Database queue driver](../adr/0007-database-queue-driver.md).

## Multi-user readiness

### Single-user → multi-user retrofit is a six-month rewrite if `user_id` isn't on every table from day one

Shipping with no `user_id` on `transactions`, `card_statements`,
`recurring_series`, `categories`, `merchant_aliases`, etc. — because there's
only one user, why bother? — turns a two-year-later "add a partner" feature
into a six-month rewrite or a quietly dropped feature.

**Mitigations:**

- Every domain table carries a `user_id` column from day one — nullable in
  v1 single-user mode, NOT NULL after multi-user activation. The
  `UserIdColumnArchTest` arch invariant enforces it.
- The `BelongsToUser` trait + `UserScope` apply a global query scope. In
  single-user mode it's a no-op; in multi-user mode it's the security
  boundary.
- Cross-user access returns 404, not 403 — the route-model binding scopes
  the lookup, so the requesting user can't tell whether another user's
  resource exists.
- No global lookups for user-scoped data. `MerchantAlias::where('pattern',
  $x)->first()` becomes `$user->merchantAliases()->where(...)`.
- The known-good user-scoped data: currency tables, the merchant *registry*
  (without user-specific mappings), MT940 parser config. Things that are
  domain truth, not user preference.

Canonical decision: [ADR 0008 — Multi-user via `BelongsToUser`](../adr/0008-multi-user-belongstouser.md).

## Secrets and credential hygiene

### `.env` and OAuth credential leaks

`.env` lands in git via `git add -f`, a `.env.local` variant, or a
`.env.example` copied wrong. macOS dev directories under `~/Documents` /
`~/Desktop` are iCloud-sync'd by default. The SQLite file in iCloud Drive's
path leaks financial history to a sync provider.

**Mitigations:**

- The project lives under `~/Development/<name>`, never under any sync'd
  path. The setup guide is explicit.
- `.env` chmods to 0600 at install time.
- The pre-push hook scans for high-entropy strings + known secret
  patterns; CI runs `gitleaks` on every PR.
- OAuth secrets land in the `oauth_secrets` table encrypted by `APP_KEY`,
  keyed by `user_id`. Each user's tokens are unreadable from another user's
  session (verified by an arch invariant against any direct read of the
  table outside the `OAuthSecretsRepository`).
- A snapshot arch test (`SecretsInLivewireSnapshotTest`) reflects over
  every Livewire component and forbids any public property whose name
  matches a registered secrets-tagged column.
- Authenticated routes set `Cache-Control: no-store`; sensitive forms
  disable autocomplete. No transaction data lands in `localStorage` or
  `IndexedDB`.
