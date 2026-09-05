# Ingestion pipeline

Every transaction Beatrax ever shows the user enters the system through the
same pipeline. The pipeline is orchestrated by
`Modules\Import\Internal\Pipeline\ImportPipeline` and the per-stage classes
that hang off it. Import types — CSV (in whichever dialect the reader's bank
exports, resolved from a preset), CAMT.053, MT940, card-statement PDF, PayPal
CSV, Gmail receipts, Microsoft Graph receipts, `.eml`/`.mbox` drop-in — feed in
at the parse stage; everything past parse is uniform.

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
live under `Modules/Ingestion/Internal/Adapters/` (one subdirectory per
source family: `Banking/`, `Csv/`, `Ics/`, `Paypal/`, plus `.eml` and
`.mbox` paths in `Modules/Receipts/`). `Modules/Import/Internal/Parsers/`
holds the payment-type hinters, not the readers.

Each adapter yields a sequence of `SourceRow` value objects — the
unnormalised, parser-specific shape of one input line. Adapters know about
their format's quirks (PayPal's UTF-8 BOM, ICS's Windows-1252 encoding, a
positional CSV's fixed column order). They know nothing about the canonical
schema.

Every CSV dialect is now a preset whose format id already names it —
`ing-nl-csv` is the ING dialect, `asn-csv` the ASN one — so no import needs a
separate dialect hint. `BankCsvFormatHint` survives only as a required-argument
guard on the public contract for `asn-csv`; it carries no information the format
id does not, and removing it is blocked on test call sites outside this module.

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

**An archive fails one document at a time.** A statement is one continuous
period, so a read that stops part-way through one is refused whole (see
[What may not be confirmed](#what-may-not-be-confirmed)). An `.mbox` is a
concatenation of independent documents, and message 400 failing says nothing
about messages 1 to 399 — there is no gap it could be hiding, because there is
no continuity to gap. So the `mbox` arm carries its own per-message guard:
anything raised by `RecordReceipt` for one message is logged, recorded on the
run's `ReceiptCaptureLog` as an unreadable ordinal, and the read moves to the
next message. `MboxIterator` does the same for a message that overruns
`UploadLimits::MAX_MESSAGE_BYTES` — the buffer is dropped, the ordinal is
recorded, and the scan resumes at the next `From ` delimiter — but only for a
caller that handed it a `ReceiptCaptureLog`. A caller with nowhere to record a
skipped ordinal still gets `MboxReadException`, because a quietly shorter
archive is the failure this is fixing: `ScanInboxDropFolderJob` has no preview
to report on, and its answer stays a quarantined file in `failed/` the reader
can see. `ImportPipeline` turns each recorded ordinal into an ERROR row
carrying `ImportFailureReason::MessageUnreadable`, so a skipped message is
counted, listed and reported through the same machinery a bad CSV line uses —
which is what leaves the rest of the archive confirmable.

The two exceptions the guard re-raises are `BlindIndexKeyUnavailableException`
and `SensitiveColumnKeyUnavailableException`. Neither is the document's fault
and both recur for every message, so swallowing them per-message would report
an app-locked run as four hundred unreadable receipts.

The `.eml` arm stays file-fatal, because a single message *is* the file: there
is no other document in it to save.

Before either arm reads a byte, `ReceiptFileShape::of()` (Receipts) answers
which of the two transports the file actually is, off its own head: mboxrd puts
a literal "From " at the start of the file and a single message never opens with
one. A file that disagrees with the declared format raises
`ReceiptFormatMismatchException`, which implements `NamesAFormatMismatch` and
`MessageNamesNoUserData`, so the preview renders it as a refusal naming what the
file is and which format to pick. This is the only arm that needs its own check:
every other format reaches `HeaderSniffer` through its adapter, and the receipt
arms bypass the registry entirely.

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

1. Produces `counterparty_normalized` through
   `Ledger\Public\Services\CounterpartyKey`, which normalises the name
   (lowercase, diacritic strip, punctuation collapse, 80-char truncate) and
   then, for a user with at-rest encryption enabled, replaces it with a keyed
   one-way digest. For a user who has never enabled encryption it stays the
   plain normalised name, deliberately: every other column of theirs is
   plaintext too. See
   [Which columns are encrypted at rest](../features/sync/sensitive-columns-at-rest.md).
2. Substitutes the literal `_no_counterparty` sentinel when the
   counterparty name is null/empty/punctuation-only — the composite
   UNIQUE on `transactions` requires NOT NULL to catch duplicates that
   lack a usable name. The sentinel is never keyed: it names no merchant,
   and two guards downstream compare a stored value against it.
3. Maps the amount sign to `Transaction.type` (positive → income,
   negative → expense, zero → adjustment); future transfer-pair
   detection overrides this for matched cross-account flows.
4. Hands the pair to `Ledger\Public\ValueObjects\TransactionAmount::relate()`,
   which is the only place the settled columns and the rate between them
   are derived. It substitutes the native amount + currency into the
   settled pair when the source DTO omits `settledAmountMinor`/
   `settledCurrency` (every EUR-native row). When the source supplies a
   different settled currency, the settled leg takes its **magnitude**
   from the source and its **sign** from the native leg — one movement
   written twice has one direction, and a source that books each leg by
   the balance IT moved supplies a settled credit for a native debit. It
   then derives `fxRateUsed = settled / native` via `Brick\Math\BigDecimal`
   at scale 8 with HALF_UP rounding — float arithmetic is forbidden on the
   money path since the `decimal(18,8)` column requires exact precision —
   which is therefore never negative. The two legs can also differ at the
   SAME currency, which carries no rate and no sign rule: a bank fee
   charged on top of the merchant's amount leaves the native figure as
   what was charged and the settled figure as what the account paid. See
   [Generic CSV (bank presets)](../features/ingestion/architecture.md#generic-csv-bank-presets).

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
   `TransactionType` value, unless step 2 already flagged a transfer.
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
stage evaluates the per-user categorization rules in `priority` order and
folds the actions of every rule that fired, so the last matching rule's
category is the one that lands; when no fired rule carries a category
action the per-user merchant memory answers instead (see
[Categorization](categorization.md)). With neither, the transaction is
left uncategorized and the user triages it later.

### 7. Counterparty resolution (`ResolvesCounterparties`)

Another public-contract injection. `Modules\Counterparties\Public\Pipeline\ResolvesCounterparties`
walks the seven-step precedence chain and upserts the matching
`counterparties` row, attaching the resulting `counterparty_id` to the
`CanonicalTransaction`. This sits between auto-category and the
fingerprint boundary specifically so the resolved `counterparty_id`
rides along into the persisted transaction.

### 8. Fingerprint (`FingerprintStage`)

The post-commit boundary. The stage computes the v3 fingerprint over
(`user_id`, `account_id`, `posted_at` as a date, `booked_at` as a datetime,
`amount_minor`, `currency`, `counterparty_normalized`) — the tuple
`FingerprintComposer::composeTuple()` hashes, in that order. `description` is
**not** among them, which is why `EnrichmentConflictField::Description` is the
one conflict field answering false to `isFingerprintInput()`: enriching it
needs no fingerprint recompose, while the other three do.
The stage then classifies the canonical transaction against the existing
`transactions` table:

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
resolve per the user's `receipt_conflict_resolution` policy. Those four
names are `Modules\Import\Public\Enums\EnrichmentConflictField`, and
the stage keys its conflicts from the enum rather than from literals, so
the set it emits and the allow-lists that later accept a stored
`field_name` as an UPDATE column cannot drift apart. Because
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

### What may not be confirmed

Ahead of both phases, `ConfirmImport` reads the preview head's
`confirmRefusal()` and throws `ImportNotConfirmableException`. There are
three refusals, tested in this order:

- **`FileDidNotReadInFull`** — the head carries a `fileFailureReason`.
  The read stopped where the adapter raised, so the entries past that
  point are neither present nor failed: they are absent. A CAMT.053
  message that lost its 500th entry to a missing `BookgDt` still yields
  499 importable rows, and confirming those would file a fortnight of a
  month-long statement as though it were the whole thing. Checked first
  because no later reading of the same run removes it.
- **`AccountsToName`** — rows landed in accounts the reader has not
  named yet.
- **`NothingImportable`** — rows were read and not one of them can be
  imported.

`PreviewWizard` calls the same predicate to disable its button, but the
rule is the action's: the wizard is not the only caller. A scheduled
Enable Banking sync confirms without a reader present, and a window whose
rows all failed as `UnknownAccount` used to flip to `confirmed` having
written nothing — after which `RunImport`'s idempotency key refused to
re-fetch that window and every transaction in it was gone.

Anything that *offers* a run for confirming has to read the same rule, or
it promises an import the confirm then refuses.
`BuildConsolidatedPreviewQuery::buildSection()` is the second such place:
the first-run wizard commits every run of every `Ready` section inside one
transaction, so a refused run does not fail alone — it takes every statement
staged beside it down with it, and the step has no per-run discard to escape
by. It therefore reads the refusal itself, off
`PreviewSectionSummary::$confirmRefusal`, rather than restating a piece of it:
restating one of the three is how a run waiting for an account name, and a run
whose every row failed, were both still being offered. A run the confirm would
refuse is left out of the section whole — its rows are not counted, its sample
is not shown, and its id is not in `importRunIds` — and so is a run whose
preview cache has gone, which the confirm cannot read at all. The section
counts what it left out in `leftOutRunCount` and names one reason in `error`,
because a count that is simply lower than what the reader uploaded says
nothing. A section with nothing left reads `Error`; one holding a refused run
beside a file that read cleanly is `Ready` on the clean file's rows.

A statement with no rows in it is the one refusal that is not a file left out:
there was nothing in it to leave, so it is counted apart and a section of
nothing but empty statements still reads `Empty` rather than sending the reader
after a file that is already as complete as it will ever be.

`FirstImportStep::confirmEachStagedRun()` closes the same gap at the write
boundary.
Every run it hands over was offered because the query said the confirm would
take it, so a refusal arriving there means that stopped being true in between —
a preview cache that expired mid-review. It catches
`ImportNotConfirmableException` and `PreviewExpiredException` per run, logs the
run id, and carries on; the run keeps its `previewed` status and the reader can
upload it again. Anything else still rolls the batch back, because anything
else is not a statement about one run.

`OpenBankingSyncRunner` records the refusal as a failed attempt and does
**not** advance `last_successful_sync_at`, so the window stays open and
the rows land on the next run once the reader names the account. Whether
the failure goes back to the queue's retry envelope depends on the
refusal: `ConfirmRefusal::anotherReadCouldDiffer()` is false for the two
that would only be repeated, and true for `FileDidNotReadInFull`, where
the walk stopped on something — a dropped connection, a page the API
would not serve twice — that the backoff exists to ride out. A window the
bank returned nothing for never reaches the confirm at all —
`OpenBankingFetchService` treats an empty fetch as the successful sync it
is.

### Post-commit dispatch ordering

After the transaction above commits, `ConfirmImport` runs a two-stage
post-commit block — skipped entirely on the re-confirm short-circuit:

- **Stage A** (always, when `$dispatchChain` is true):
  `StatementDerivedRecords::promoteFor()` promotes every ICS-kind
  `statement_summaries` row written under this import run into a
  `card_statements` row via `UpsertsCardStatements`, then anchors the
  account's opening balance via
  `AnchorsStartingBalanceFromStatements`. Both are idempotent — the
  upsert on `UNIQUE(user_id, account_id, period_start, period_end)`, the
  anchor on a `whereNull` guard that leaves a reader's own override
  alone — and both are deliberately decoupled from the
  inserted/enriched gate, so a re-import where every transaction is a
  fingerprint-duplicate still recovers a hand-deleted `card_statements`
  row. **`RunImport` calls the same collaborator from its
  content-hash short-circuit**, because the ordinary way a reader asks
  for that recovery is to upload the same bytes again — and that path
  never reaches `ConfirmImport` at all.
- **Stage B** (gated on `$result->inserted > 0 || $result->enriched > 0`):
  inserts a `pending` `chain_resolution_runs` row (so the results
  page the confirm redirects to has a status to display on its very
  first render, before its poll has ticked once), then dispatches
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
ERROR-status `PreviewRowDto` entries. The row carries an
`errorReason` — an `ImportFailureReason` — and the view translates
that; the exception's own message never reaches the screen.
A caught message names internal classes and, for
`BlindIndexKeyUnavailableException`, the reader's own user id, so it
goes to the application log via the injected `LoggerInterface` and
nowhere else. A developer reading `/dev/logs` sees which adapter or
stage threw; the reader sees a sentence about their file.

`BlindIndexKeyUnavailableException` has its own catch arm ahead of the
general one, because it is the one failure the reader can act on: the
app lock is engaged, and unlocking and re-uploading fixes it.

Adapter-level exceptions (bad header, encoding mismatch, malformed XML)
are caught at the outermost try-catch. They are **not** a row. Parsing
stops where they are raised, so rows past that point are absent from
`rows` rather than present-and-failed, and the failure is reported on
`ImportPreviewResult` as `fileFailureReason` plus an optional
`fileFailureDetail`. The detail is the parser's own wording and is
carried only when the exception implements
`Core\Public\Support\MessageNamesNoUserData` — the sniffer's "this CSV
is missing the 'Bedrag bij / af' column" is the case worth keeping,
because it names the likely cause and what to do about it.

Reported as a row, this failure rendered as a table row whose every
cell was an em-dash, above an enabled "Confirm import" — a transaction
that did not exist, on the one screen whose job is to let the reader
check before committing. The preview now refuses to offer a confirm
that would write nothing, and says the file was only read part-way when
some rows did arrive before the stop.

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
