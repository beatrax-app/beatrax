# `Receipts` — architecture

The `Receipts` module takes raw `.eml` messages (from
`EmailScan`'s blob store or from a user-dropped file) and matches
them to the corresponding canonical transactions in the ledger.
Each matcher (PayPal, ICS, Google Play) extracts the per-line
breakdown, the merchant memo, and any chain hints (this PayPal
charge was funded by the user's ASN card; this ICS line is a
refund of a prior charge), then enriches the matched
`transactions` via `Import::ApplyEnrichments` and writes a
`statement_summaries` row via `Ledger::RecordsStatementSummary`.

## What this module is for

The user's bank statement says "PAYPAL 18.42 EUR"; the user's
PayPal receipt says "Domino's pizza in Eindhoven, ordered Friday
night, paid via Boldking IBAN". Matching the two unlocks the
detail the dashboard surfaces (the merchant string, the per-line
items, the funding chain). This module is the matcher.

The same matchers also extract chain hints: a PayPal receipt
carrying "Funded by your Visa card ending in 1234" produces a
`ChainHintDetected` event the
[`Chains`](../chains/architecture.md) module consumes to insert a
candidate chain link. Drop-in `.eml` files (the user forwarded
themselves the receipt and saved the `.eml`) follow the same path
as inbox-fetched messages — the `ReceiptSourceAdapter` is the
unifying surface.

What the module explicitly does NOT do:

- It never connects an inbox. `EmailScan` owns the OAuth + the
  `.eml` blob persistence; this module consumes the blobs.
- It never persists transactions. Enrichments flow through
  `Import::ApplyEnrichments`; statement summaries flow through
  `Ledger::RecordsStatementSummary`.
- It never matches without a registered sender. The matcher
  registry is a tag-discovered list (PayPal / ICS / Google Play
  in v1.0.0); unknown senders are logged and skipped.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `SenderMatcher::canHandle($msg)`, `match($emlRaw):
    MatchOutcomeDto`, `key(): string`, `priority(): int`. Tag
    `receipts.matcher`; matchers run highest-priority first.
- **Actions/**
  - `RecordReceipt::__invoke($emlBytes, $user,
    $sourceFilename): MatchOutcomeDto` — the single entry
    point, taking the raw RFC 822 bytes. Dispatches the matcher;
    on hit, calls `ApplyEnrichments` (Import) and
    `RecordsStatementSummary` (Ledger).
  - `ApplyReceiptConflictResolution::__invoke($user,
    ReceiptConflictChoice $choice, int $conflictId)` — the
    first-conflict toast handler. It takes the user's chosen policy
    as the enum every producer already holds, never its string
    value, plus the id of the ONE conflict the toast rendered, and
    returns 1 or 0 for it. A reconciled row is refused the way every
    sibling transaction writer refuses one, and a row it did
    rewrite is announced with `Sync::TransactionMutated` after the
    commit — see below.
- **DTOs/**
  - `MatcherInputDto` — `(id, userId, source, providerMessageId,
    senderEmail, senderName, subject, internalDate, emlPath)`. It
    carries a PATH, not bytes; `toInboxMessageDto()` is what the
    registry hands `canHandle()`.
  - `MatchOutcomeDto` — `(kind, parsed, skipReason,
    unmatchedReason, matcherKey)`, a sum type with `parsed()`,
    `skipped()` and `unmatched()` constructors plus
    `fromMatcher($key)`, which the registry applies to whatever
    the answering matcher returned. `kind` is a
    `MatchOutcomeKind`, and `MatchOutcomeKind::toInboxStatus()`
    is the one place that map is written down.
  - `ParsedReceiptDto` — the matcher's structured output.
  - `ChainHintPayload/FundedByCardPayload`,
    `ChainHintPayload/RefundOfPayload` — typed chain-hint
    payloads.
- **Events/**
  - `ChainHintDetected` — `(sourceTransactionId, hintType,
    hintPayload, evidence, userId)`. Consumed by
    `Chains`' `CreateChainLinkFromHint`.
  - `ReceiptConflictDetected` — `(transactionId, userId, field,
    receiptValue, csvValue, importRunId)`. Consumed by the in-app
    toast, which renders straight off the event rather than
    re-reading a row.
- **Pipeline/**
  - `EmlMimeReader::read($bytes)`, `EmlHeaderProfile`,
    `MboxIterator::iterate($file)`, `MboxHeaderProfile`,
    `FileDropEmlBlobStore::put($path, $bytes)`,
    `ReceiptSourceAdapter` — the per-source / per-format
    pre-parse layer.
  - `ParsedMimeMessage` — typed MIME parse result.
- **Services/**
  - `ReceiptConflictQuery::latestForUser($user)` — the most
    recent pending conflict as `(conflictId, transactionId, field,
    storedValue, incomingValue, sourceFormat)`, or null. The id is
    part of the projection because the toast's buttons answer that
    conflict and no other.

`Internal/` houses the implementation:

- **Internal/MatcherRegistry** — tag-discovered matcher list,
  sorted by `priority()` descending at register time.
- **Internal/ReceiptLedgerBridge** — the second half of a parsed
  outcome, which `RecordReceipt` deliberately does not do: resolve
  the synthetic own-IBAN to an Account, adopt or open the hourly
  inbox-handoff `ImportRun`, and write through
  `ReceiptSourceAdapter` → `Import::NormalizeStage` →
  `Ledger::RecordsTransactions`. Both the inbox job and the
  drop-folder scan reach it here; the scan used to discard the
  outcome instead, which moved the file to `processed/` and left
  the ledger empty.
- **Internal/Matchers/** — `PaypalReceiptMatcher`,
  `IcsReceiptMatcher`, `GooglePlayReceiptMatcher`. Each
  parses its sender's HTML / text body, extracts the per-line
  receipt structure, returns a `MatchOutcomeDto`. All three
  inject `ReceiptBodyText`, the collaborator holding the
  HTML-to-text pass and the currency-gated amount parse the three
  used to duplicate verbatim — a collaborator rather than a trait,
  because a trait reading a using class's promoted private
  properties reports them unused. Each spells its own synthetic
  IBAN through `Ingestion`'s `SyntheticIban` enum.
- **Internal/Jobs/ProcessFetchedInboxMessagesJob** — the per-user
  consumer of `inbox_messages` rows still `fetched`.
- **Internal/Jobs/ScanInboxDropFolderJob** — the per-user scan of
  `storage/app/inbox-drop/{userId}/`, dispatched by
  `Internal/Console/ScanInboxDropFolderCommand`.
- **Internal/Console/ScanInboxDropFolderCommand** — `receipts:scan-drop-folder`,
  the only dispatcher of that job. It reads the per-user
  `auto_import_drop_folder` opt-in itself, so a tick costs nothing for a
  reader who never turned the folder on.
- **Internal/Listeners/HandleFileOpenedFromOs** — filters
  `Desktop::FileOpenedFromOs` by `.eml` / `.mbox` extension;
  persists into `Desktop::PendingFileIntent`.
- **Internal/Listeners/DispatchChainHintsFromReceipt** —
  listens for `Import::TransactionImported`; reads any
  attached chain hints from the canonical row's
  `auto_category_provenance` or similar; dispatches one
  `ChainHintDetected` per hint.
- **Internal/Http/Livewire/ReceiptConflictToast** — the
  first-conflict toast surfacing
  `ReceiptConflictDetected`.

## Key services + events

- `RecordReceipt::__invoke($emlBytes, $user)` — the single
  sanctioned entry point.
  1. `MatcherRegistry::dispatch($input, $emlBytes)` — walks
     the priority-sorted list and returns the outcome of the
     first matcher whose `canHandle($msg)` claims the message.
  2. Matcher emits `MatchOutcomeDto`.
  3. If matched: `ApplyEnrichments` (Import) strengthens
     `source_ref` on the matched transactions;
     `RecordsStatementSummary` (Ledger) writes the per-period
     summary; per-hint, raise `ChainHintDetected`.
  4. If no matcher claims it: `dispatch` returns
     `MatchOutcomeDto::unmatched()` and `RecordReceipt` stamps
     the `file_imports` row `status = unmatched`, leaving
     `matcher_key` NULL. Nothing is logged and nothing throws.
  5. If the same bytes were recorded before, the row is left as it
     stands and the matcher's outcome is returned anyway. Silence
     there left a file the drop-folder scan had taken unconfirmable
     through the wizard afterwards, so the receipt was importable by
     neither path; `FingerprintStage` is what decides a duplicate.
- `MatcherRegistry::dispatch($input, $emlBytes)` — iterates the
  priority-sorted matcher list; the first `canHandle($msg)` true
  wins and its `match($emlRaw)` outcome is returned verbatim.
- `DispatchChainHintsFromReceipt::handle($event)` — listens
  for `TransactionImported`; raises one `ChainHintDetected`
  per attached hint. The chain-link FK on the from-side is
  always valid because this listener runs AFTER the
  canonical transaction was persisted.

## Data flow

The inbox-fetched receipt path:

```
EmailScan::IncrementalScanJob persists InboxMessage
  → call RecordReceipt with MatcherInputDto
       → MatcherRegistry::dispatch picks PayPal / ICS / Google Play
       → matcher returns MatchOutcomeDto, stamped by the
         registry with the answering matcher's key()
       → ApplyEnrichments (Import)
       → RecordsStatementSummary (Ledger)
       → per chain hint: dispatch ChainHintDetected
            → Chains::CreateChainLinkFromHint
                 → INSERT chain_links (hint variant)
```

The drop-an-.eml path:

```
User drops .eml onto the app
  → Desktop::FileOpenedFromOs($path)
  → Receipts::HandleFileOpenedFromOs (extension filter)
  → Desktop::PendingFileIntent::remember($path)
  → user logs in (if needed) → /desktop/file-staging
  → user clicks "Start import" → Desktop::FileStagingPage
     redirects to the import wizard
       → FileDropEmlBlobStore::put($path, $bytes)
            → ReceiptSourceAdapter parses bytes
            → call RecordReceipt
```

The receipt-conflict path (a parsed receipt disagrees with an
existing categorisation):

```
RecordReceipt detected a conflict
  → INSERT pending_enrichment_conflicts (Categorization-owned)
  → dispatch ReceiptConflictDetected
       → ReceiptConflictToast renders (amounts quoted as money, see below)
       → user picks resolution
            → ApplyReceiptConflictResolution
                 → write resolution; clear conflict
                 → dispatch Sync::TransactionMutated (post-commit)
```

## When a message is matched

In the request that records it, always. `RecordReceipt` calls
`MatcherRegistry::dispatch` inline and writes the answer onto the
`file_imports` row before it returns; no job is queued and no scheduler
tick stands between a message arriving and its status being final. There
is also no second pass — a row that lands `unmatched` is never re-asked,
so widening a matcher does not revisit what it could not read before.

That matters most on a phone. Of the three callers of `RecordReceipt`,
`receipts.process-fetched-inbox-messages` is desktop-only by decision —
`MobileBackgroundSchedule::desktopOnly()` names it, because it consumes
`inbox_messages` rows an inbox pipeline the phone never runs is what
writes. The upload wizard works there because matching is synchronous: a
receipt uploaded on a phone is matched during the upload, not left
waiting for a worker the device does not run.

`receipts.scan-drop-folder` was in that list until a phone was read with
the switch enabled under copy promising a scan every five minutes. It is
a `Schedule::command()` in `MobileBackgroundSchedule::requiredOnDevice()`
now, and the settings copy branches on
`Modules\Core\Public\Services\UserDataPathService::platform()` because
the device's runner clamps five minutes to fifteen and its OS treats even
that as a floor.

`RecordReceipt` also takes an optional `ReceiptCaptureLog`. It collects
one `CapturedReceipt` per message — sender, subject, the message's own
`Date`, the outcome and the answering matcher key — for a caller that has
to report on the drop afterwards. Nothing joins `file_imports` to the
import run that wrote it, so a caller that does not collect them as they
go cannot find them again.

## Reading a receipt at the currency it names

Every matcher used to spell its currency twice — once as a literal inside
the regex that found the figure, once as the code the digits were then
parsed at — and the glyph lists were written before JPY was seeded.
PayPal's conversion anchor accepted `[€$£]`, so a `Conversion to JPY: ¥ 1250`
leg was not matched at all and the receipt settled in its native currency
instead; its labelled anchor accepted no glyph, so `Bedrag: ¥ 1250` fell
through to nothing and a code-less total was denominated at the **reader's
base**; ICS parsed at `Currency::Eur` whatever the mail said; Google Play's
settled leg required `(€… EUR)`, so a store billing `(¥1,250 JPY)` settled
in USD.

One alternation now serves all three anchors — `ReceiptBodyText::currencyMarkers()`,
built from `Money::SYMBOLS` plus the `Currency` cases — and
`currencyMarked()` turns whatever it captured back into the code the figure
is parsed at. It is deliberately closed to the codes this app names rather
than open to `[A-Z]{3}`: the ICS and PayPal anchors are matched against a
whole message body, where a bare three-letter class reads
`Referentienummer: ABC123` as an amount.

The reader's base survives in exactly one place, `nativeFromLabelled()`: a
PayPal total carrying no code and no glyph is denominated by nothing the mail
says, and the reader's own money is the last thing left to name it with.

## Resolving a conflict is a ledger write

`ApplyReceiptConflictResolution` rewrites `transactions` columns the
reader can see and the dedup tuple composed over them, so it carries the
two obligations every other transaction writer in this repo carries.

- **It refuses a reconciled row.** `TransactionStatusQuery::locksEdits()`
  is consulted under the same row lock the recompose reads, the way
  `SetTransactionNote`, `ReassignCounterparty`, `UpdateTransactionCategory`
  and `Tax::TagTransaction` consult it. The frozen value stands, whatever
  the policy said. The pending row still clears: the toast mounts off
  whatever pending row exists, so a conflict no policy can ever resolve
  would raise it on every render with nothing the reader could press to
  be rid of it. The refusal is logged rather than silent.
- **It announces what it wrote.** One `Sync::TransactionMutated`
  (`mutationType: 'edit'`) per rewritten row, carrying the resolved
  column in PLAINTEXT — `OpLogWriter` seals a sensitive value itself —
  together with `counterparty_normalized`, `normalization_version`,
  `fingerprint` and `fingerprint_version`. Announcing only the field
  would leave the peer holding the resolved value under the fingerprint
  it no longer matches, so re-importing that statement there inserts a
  duplicate instead of matching. Two conflicts on one row merge into one
  announcement: the later pass read the earlier one's write, so its
  recomposed tuple is the one describing both.

The dispatch happens AFTER the surrounding transaction commits, never
inside it — `tests/Contracts/DispatchAfterCommitArchTest.php` enforces
that, because the listener writes an op-log a rollback cannot reach.

- **It quotes money as money.** An `amount_minor` conflict holds the two
  stored integers, and the toast printed them into its own sentence: "a
  different amount (“-3199”) than the statement (“-3200”)" — a count of
  minor units offered to the reader as a figure, at no stated currency and
  at a scale a yen does not have. `ReceiptConflictQuery` now carries the
  transaction's currency for the stored side, and the incoming value of any
  `currency` conflict held on the same row for the receipt's, because a
  receipt that disagrees about the amount often disagrees about the
  currency in the same breath. The component renders both through `Money`
  under keys named apart from its own properties: Livewire merges public
  properties over a `render()`'s data, so `receiptValue` in the view array
  never reached the view.
- **It answers exactly the conflict the reader was shown.** The toast
  names one conflict and quotes its two values; the action takes that
  conflict's id and resolves it alone. It used to resolve every
  outstanding conflict for the user, so consenting to one change bought
  every change, including ones the reader had never seen. The policy
  write is what answers the copy's "for future conflicts" question —
  `ApplyEnrichments` reads `users.receipt_conflict_resolution` and
  applies it to conflicts that have not happened yet, which is the only
  thing a stored policy should reach. Answering also re-offers the next
  outstanding conflict rather than dismissing the toast, so a backlog
  clears one informed press at a time.
- **It moves the amount columns as a set.** An amount lives in
  `transactions` four times over — the native pair the fingerprint is
  composed over, and the settled pair every balance, budget, forecast and
  ledger row sums — plus the `fx_rate_used` relating them. Rewriting
  `amount_minor` alone left the fingerprint saying €31.99 while the whole
  rest of the app read €25.00. `Ledger::TransactionAmount` is the single
  value written: `withAmountMinor()` carries the settled leg with it on a
  single-currency row, leaves the bank's own conversion standing on a
  cross-currency one — its magnitude, re-signed to whatever direction the
  edited native leg now has, since one movement written in two currencies
  cannot be a debit in one and a credit in the other — and re-derives the
  stored rate from whichever pair results. Every column it holds is in the UPDATE and in the announced
  `dirtyFields`, so no peer can end up holding half the change. The
  auto-applied arm carried the same defect and now shares the same
  value: `Import::ApplyEnrichments` builds a `TransactionAmount` from
  the locked row whenever the resolved fields touch `amount_minor` or
  `currency` and writes `toColumns()`, so a `prefer_receipt` policy
  resolving a conflict the reader never sees moves the same set the
  button does. The fingerprint it recomposes reads the native leg off
  that value rather than off the raw resolved field, which is what
  keeps the two arms hashing the same tuple.
- **A recomposed fingerprint that collides is an answer, not a crash.**
  The recompose can land on a tuple the ledger already holds, which means
  the receipt is describing a transaction that is already there. The
  `UniqueConstraintViolationException` used to escape as an unresolvable
  toast: the same conflict re-rendered and the same button threw again.
  It is now caught at the UPDATE — the stored row stands, the conflict
  still clears, nothing is announced, and the collision is logged.
