# Ingestion pipeline

Every transaction Beatrax ever shows the user enters the system through the
same pipeline. The pipeline is orchestrated by
`Modules\Import\Internal\Pipeline\ImportPipeline` and the per-stage classes
that hang off it. Source formats — ASN CSV, ASN CAMT.053, ASN MT940, ICS PDF,
PayPal CSV, Gmail receipts, Microsoft Graph receipts, `.eml`/`.mbox`
drop-in — feed in at the parse stage; everything past parse is uniform.

This document describes the pipeline stages, the idempotency contract that
governs duplicate handling, and the post-commit boundary that separates
preview from confirm.

The two structural decisions this pipeline operates inside are
[ADR 0001](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0001-modular-architecture.md) (module split) and
[ADR 0002](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0002-di-only-rule.md) (constructor injection only).

## Stages, in order

The pipeline runs per source-row inside a try-catch that converts per-row
exceptions into ERROR-status `PreviewRowDto` entries — a single bad row never
aborts the whole preview.

### 1. Parse (`ParseStage`)

`Modules\Import\Internal\Pipeline\Stages\ParseStage` dispatches to the
format-specific adapter registered with `SourceAdapterRegistry`. Adapters
live under `Modules/Import/Internal/Parsers/` (one subdirectory per source:
`Asn/`, `Ics/`, `Paypal/`, plus `.eml` and `.mbox` paths in
`Modules/Receipts/`).

Each adapter yields a sequence of `SourceRow` value objects — the
unnormalised, parser-specific shape of one input line. Adapters know about
their format's quirks (PayPal's UTF-8 BOM, ICS's Windows-1252 encoding, ASN
CSV's per-bank dialect-hint requirement). They know nothing about the
canonical schema.

The pipeline refuses any CSV import without an explicit `BankCsvFormatHint`
— the CSV file's own headers are not enough to disambiguate the dialect
reliably, and that guard lives at the public-contract boundary so even
programmatic callers can't skip it.

For the `eml`/`mbox` formats, `ParseStage` instead drives the receipt path:
it reads the file bytes and hands them to `RecordReceipt`, which persists a
`file_imports` row, stores the `.eml` on disk, runs the matcher dispatch,
and transitions the row status. On a `parsed` outcome the stage bridges the
resulting `ParsedReceiptDto` to a `SourceTransactionDto` via
`ReceiptSourceAdapter`; `skipped`/`unmatched` outcomes yield nothing. The
`mbox` arm iterates the archive via `MboxIterator` and runs the same
per-message flow for each contained message. The `User` is required only
for these receipt arms (to scope the `file_imports` row per-user); the
CSV/CAMT/MT940/PDF arms ignore it.

### 2. Account resolution

For each `SourceRow`, the pipeline asks the injected `AccountResolver` to
identify the user's account that owns the row (by own-IBAN for bank rows,
by PayPal-account email for PayPal rows, by ICS-card-number for ICS rows).

- `KnownAccount` → continue with the resolved `account_id`.
- `UnknownAccount` → the row is converted to an ERROR-status
  `PreviewRowDto` carrying an `UnknownIban` prompt, deduplicated across the
  preview. The wizard renders these prompts as "name this IBAN as an account
  to continue."

### 3. Normalize (`NormalizeStage`)

`Modules\Import\Public\Pipeline\NormalizeStage` is the only public-contract
stage — other modules MAY invoke it directly. It converts the
parser-specific `SourceRow` into a `CanonicalTransaction` DTO carrying
booked-at, amount (`brick/money` per [ADR 0009](https://github.com/beatrax-app/spec/blob/main/00-overview/decisions/0009-brick-money-multi-currency.md)),
currency, counterparty name + IBAN, raw description, account FK, and user
FK, via four steps:

1. Normalises the counterparty name through `FingerprintComposer`
   (lowercase, diacritic strip, punctuation collapse, 80-char truncate).
2. Substitutes the literal `_no_counterparty` sentinel when the
   counterparty name is null/empty/punctuation-only — the composite
   UNIQUE on `transactions` requires NOT NULL to catch duplicates that
   lack a usable name.
3. Maps the amount sign to `Transaction.type` (positive → income,
   negative → expense, zero → adjustment); future transfer-pair
   detection overrides this for matched cross-account flows.
4. Substitutes the native amount + currency into the settled pair when
   the source DTO omits `settledAmountMinor`/`settledCurrency` (every
   EUR-native row). When the source supplies a different settled
   currency, the canonical row carries both legs verbatim AND derives
   `fxRateUsed = settled / native` via `Brick\Math\BigDecimal` at scale 8
   with HALF_UP rounding — float arithmetic is forbidden on the money
   path since the `decimal(18,8)` column requires exact precision.

### 4. Transaction-type classification (`ClassifyTransactionType`)

Sets the `transaction_type` enum (`income`, `expense`, `transfer`) based on
amount sign plus contextual signals from the source format. Income is a
first-class type, not a "negative expense" — this matters for cash-flow
forecasting downstream. `ClassifyTransactionType` is a pure pre-load
transformer — it never queries `transactions` and never re-types rows the
adapter already classified as `refund`/`fee`/`adjustment`. Its algorithm:

1. Preserve already-classified rows (`refund`/`fee`/`adjustment` pass
   through unchanged).
2. Two-arm cross-account-IBAN check, both scoped by `$user->id`: the alias
   bridge (Arm A, `ResolvesKnownCounterpartyIban`) maps real institution
   IBANs (PayPal Luxembourg, ICS at ABN AMRO) to the user's synthetic-IBAN
   account; the literal own-IBAN match (Arm B) catches transfers between
   two of the user's own accounts. Either arm firing sets
   `transfer_out`/`transfer_in` by amount sign.
3. PayPal source-format event-type map (`PaypalCsvEventTypeMap`) — reads
   the first event's `type` from `rawPayload.events` and maps it to a
   `Transaction::TYPES` value, unless step 2 already flagged a transfer.
   An unmapped parent event type falls through to step 4 (a genuinely
   unknown PayPal event is a user-data condition, not a bug — the adapter
   already raises a typed exception for anything genuinely unmappable at
   parse time).
4. Subtractive income rule: positive amount AND type not already one of
   `transfer_in`/`transfer_out`/`refund`/`fee` → `income`.
5. Default: leave `NormalizeStage`'s amount-sign-derived type untouched.

### 5. Payment-type classification (`PaymentTypeClassifierStage`)

Sets the `payment_type` enum (`ideal`, `direct_debit`, `card_payment`,
`transfer`, `paypal`, ...) using the per-adapter `*Hinter` classes registered
in `Modules/Import/Internal/Parsers/` (see
[Payment-type hinters](../features/import/architecture.md#payment-type-hinters)
for the per-source lexeme/code tables).

The `paymentTypeHinterContract` arch invariant requires every `*Hinter`
class under `Modules/Import/Internal/Parsers/` to implement the
`PaymentTypeHinter` contract, which is what makes this stage's plug-in
extension point safe. The stage is pure/stateless and bound as a
singleton; it emits no per-row log line — the resolved verdict lives on
the returned `CanonicalTransaction`'s `paymentType` property and survives
into the `PreviewRowDto`, so a 500-row import produces zero classifier log
lines and keeps `/dev/logs` tailable.

### 6. Auto-category (`AppliesAutoCategory`)

The pipeline calls into `Modules\Categorization\Public\Contracts\AppliesAutoCategory`
— a public-contract injection point. The implementation lives in
`Modules\Categorization\Internal\Pipeline\ApplyAutoCategoryStage`. The
stage walks the per-user categorization rules in specificity order and
applies the highest-scoring rule above the ≥40% confidence gate (see
[Categorization](categorization.md)). If nothing clears the gate the
transaction is left uncategorized; the user triages it later.

### 7. Counterparty resolution (`ResolvesCounterparties`)

Another public-contract injection. `Modules\Counterparties\Public\Pipeline\ResolvesCounterparties`
walks the seven-step precedence chain and upserts the matching
`counterparties` row, attaching the resulting `counterparty_id` to the
`CanonicalTransaction`. This sits between auto-category and the
fingerprint boundary specifically so the resolved `counterparty_id`
rides along into the persisted transaction.

### 8. Fingerprint (`FingerprintStage`)

The post-commit boundary. The stage computes the v3 fingerprint over
(`account_id`, `booked_at`, `amount_minor`, `currency`, normalised
counterparty + description) and classifies the canonical transaction
against the existing `transactions` table:

- **NEW** — no existing row matches the fingerprint. The row is queued for
  insert.
- **DUPLICATE** — an exact-shape match already exists. The row is dropped;
  the preview shows it as DUPLICATE.
- **ENRICHED** — a weaker-source row already exists (e.g. an earlier CSV
  row enriched by a stronger CAMT row). The pipeline queues a
  `PendingEnrichment` that UPDATE-s the existing row with the stronger
  `source_ref` and appends a provenance entry to the `enriched_from` JSON
  column.

The classification yields a `PreviewRowDto` per source row plus three
output lists — `canonical` (NEW rows ready to insert), `enrichments`
(UPDATE work items), and `unknownIbans` (deduplicated account-resolution
prompts).

`FingerprintStage`'s fingerprint lookup filters explicitly by `user_id`
(rather than relying on `BelongsToUser`'s global scope, which falls
through to "no scope" in unauthenticated CLI/queue/test contexts), so a
fingerprint owned by a different user can never mark the current user's
preview row as a duplicate or get enriched. Ranking is delegated to
`SourceRefRanker` so the preview-time classifier and the write-time
`ApplyEnrichments` share one canonical ordering — this is what closes the
preview-then-confirm TOCTOU window. Statement-vs-statement collisions
(both sides a bank-statement format) always drop as DUPLICATE without
upgrading `source_ref` — re-importing the same period as CAMT.053 after
CSV must not silently re-flag every row ENRICHED; receipt-driven
enrichment (at least one side a receipt format) is unaffected.

When the disposition is ENRICHED, the stage additionally fetches the
existing row's `counterparty_name`/`description`/`currency`/`amount_minor`
and compares them to the incoming row (case-insensitive for strings,
uppercase for currency, exact-int for amount), recording any disagreement
on `EnrichedDisposition::$conflictingFields` for `ApplyEnrichments` to
resolve per the user's `receipt_conflict_resolution` policy. Because
`counterparty_name`/`description` are ciphertext at rest for an encrypted
user, the stored value is decrypted before this compare — otherwise
ciphertext could never equal the incoming plaintext and every
receipt-vs-statement re-import would register a false-positive conflict.

## Preview vs Confirm

The `ImportPipeline::preview()` method runs every stage above without
mutating the `transactions` table. The result is the wizard's preview
screen: per-row NEW/DUPLICATE/ENRICHED/ERROR status, plus the unknown-IBAN
prompts the user must answer before they can confirm.

`ConfirmImport` is a separate action. When the user clicks Confirm, the
action replays the `canonical` list through
`Modules\Ledger\Public\Contracts\RecordsTransactions` (one INSERT per
row, inside a transaction per import-batch) and the `enrichments` list
through `Modules\Ledger\Public\Contracts\AppliesEnrichments` (one UPDATE
per work-item).

This separation matters: the preview is read-only, fast, and safe to
re-run; the confirm step is the single write boundary. Reviewers and arch
tests know there are no other paths into the `transactions` table from the
Import module.

## Confirm: bounded recorder and post-commit dispatch

`ConfirmImport` deliberately does NOT wrap the whole confirm in one
transaction. A full-year confirm must not run as a single unbounded DB
transaction, so the write boundary splits into two phases:

1. **Recorder phase (no outer transaction).** `RecordsTransactions` commits
   in bounded per-chunk transactions, each idempotent via the transaction
   fingerprint (`insertOrIgnore`). Wrapping this phase in an outer
   transaction would demote those commits to savepoints and re-form the
   year-sized transaction this design avoids. A crash after this phase
   leaves committed rows that a re-run safely (idempotently) completes.
2. **Enrichment + status-flip phase (one transaction).** Applying
   enrichments, updating the `ImportRun` counts, and flipping `status` to
   `confirmed` all run inside a single `DB::transaction()`, so a committed
   `confirmed` status ALWAYS implies the enrichment writes fully landed.
   A mid-enrichment failure rolls both back together, leaving the run on
   its previous status; a re-confirm then replays the enrichments from a
   clean slate (`AppliesEnrichments`'s own per-row rank/exact-ref
   short-circuits make even a partial-then-replay sequence a no-op for
   rows already applied).

Re-confirming an already-`confirmed` run short-circuits before either
phase: it returns a zero-action result derived from the persisted counts
(zero inserts, the file's original inserted+duplicate count reported as
duplicates), so a wizard refresh/back-button can never double-import or
double-enrich.

### Post-commit dispatch ordering

After the transaction above commits, `ConfirmImport` runs a two-stage
post-commit block — skipped entirely on the re-confirm short-circuit:

- **Stage A** (always, when `$dispatchChain` is true): promotes every
  ICS-kind `statement_summaries` row written under this import run into a
  `card_statements` row via `UpsertsCardStatements`. The upsert is
  idempotent (`UNIQUE(user_id, account_id, period_start, period_end)`),
  deliberately decoupled from the inserted/enriched gate so it also
  recovers a manually-deleted `card_statements` row on a re-import where
  every transaction is a fingerprint-duplicate.
- **Stage B** (gated on `$result->inserted > 0 || $result->enriched > 0`):
  inserts a `pending` `chain_resolution_runs` row (so the wizard's
  `wire:poll` has something to display on its first tick), then dispatches
  the chain resolver and the recurring-detection sweep.

Both dispatches run strictly AFTER the transaction commits, never inside
the closure — the queue driver does not share the SQLite transaction
frame, so an in-transaction dispatch would let a worker observe stale
state. Callers that batch several `ConfirmImport` invocations inside
their own outer transaction pass `$dispatchChain = false` and dispatch
once themselves after that outer transaction returns; the default `true`
preserves the single-run behaviour every other caller expects.

## Idempotency contract

The whole pipeline is safe to re-run. Re-uploading the same ASN CSV file
the day after the first import:

1. Parse yields the same `SourceRow` sequence.
2. Normalise yields the same `CanonicalTransaction` DTOs.
3. Fingerprint classifies every row as DUPLICATE, since the matching
   fingerprints already exist.
4. The `canonical` list is empty; nothing inserts.

Across formats the answer depends on what kind of file arrives. Two
*statements* colliding — an ASN CAMT.053 over the same period as an ASN
CSV — classify as DUPLICATE and never enrich: `FingerprintStage` requires
a receipt format on one side before it will accept a `source_ref`
upgrade, so re-uploading one period in two statement formats cannot add a
second `source_format` to the audit chain. `CrossFormatDedupTest` locks
that in both directions.

ENRICHED is the receipt path. When one side is a receipt and the incoming
`source_ref` both exists and outranks the stored one, the enrichment path
updates the existing row in place and the `enriched_from` JSON column
captures the provenance trail, so the "this row was originally imported
as CSV on 2026-04-12, then enriched by the PayPal receipt on 2026-05-01"
history survives.

This is the "Idempotency" project constraint (see PROJECT.md): same
source plus same transaction never duplicates. The fingerprint stage
plus the `enriched_from` append-only column is how the constraint is
mechanised.

## Per-row error handling

Per-row exceptions inside the try-catch around stages 4-8 produce
ERROR-status `PreviewRowDto` entries with the exception message
surfaced to the user. The full stack trace lands in the application
log via the injected `LoggerInterface` — a developer reading
`/dev/logs` sees which adapter or stage threw, while the wizard shows
only the user-facing message.

Adapter-level exceptions (bad header, encoding mismatch, malformed XML)
are caught at the outermost try-catch and produce a single ERROR row
covering the whole file, so the wizard can render its preview screen
rather than 500-ing.

## Statement metadata side-channel

CAMT.053 and MT940 carry statement-level metadata (opening balance,
closing balance, period dates) the row-by-row pipeline does not see.
After the row loop completes, the pipeline asks the adapter for its
`statementMetadata()` value and persists it via the injected
`RecordsStatementSummary` writer — a one-row-per-statement-period
write to the `statement_summaries` table.

CSV adapters return `null` from `statementMetadata()` (CSV carries no
period boundary). Receipt-path formats (`.eml`, `.mbox`) are excluded
from the writer call because each receipt is its own logical record
with no opening/closing balance.

## What this pipeline does not do

- **It does not detect transfers or recurring patterns.** Those are
  downstream modules (`Transfers`, `Recurring`) that read from the
  populated `transactions` table on their own schedule.
- **It does not resolve chains.** Chain resolution is a per-user job
  scheduled after a successful import; see
  [Chain resolution](chain-resolution.md).
- **It does not categorise post-hoc rule additions.** A new
  categorization rule applies to new imports, not to past transactions.
  The triage surface lets the user retro-categorise on demand.

## Where to look in the code

- `Modules/Import/Internal/Pipeline/ImportPipeline.php` — the
  orchestrator.
- `Modules/Import/Internal/Pipeline/Stages/` — the per-stage classes
  (`ParseStage`, `ClassifyTransactionType`, `PaymentTypeClassifierStage`,
  `FingerprintStage`).
- `Modules/Import/Public/Pipeline/NormalizeStage.php` — the only
  public-contract stage.
- `Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php`
  — auto-category implementation.
- `Modules/Counterparties/Internal/Resolver/CounterpartyResolverService.php`
  — counterparty seven-step precedence.
- `Modules/Ingestion/Public/Services/SourceAdapterRegistry.php` — the
  adapter registry the parse stage dispatches through.
- `tests/Contracts/IdempotencyContractTest.php` — the test that proves
  re-importing the same file is a no-op.
