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
  - `ApplyReceiptConflictResolution::__invoke($user, $choice)` —
    the first-conflict toast handler. It takes the user's chosen
    policy, not a conflict id, and returns how many pending
    conflicts that policy resolved: the toast asks once and the
    answer applies to the whole backlog.
- **DTOs/**
  - `MatcherInputDto` — `(id, userId, source, providerMessageId,
    senderEmail, senderName, subject, internalDate, emlPath)`. It
    carries a PATH, not bytes; `toInboxMessageDto()` is what the
    registry hands `canHandle()`.
  - `MatchOutcomeDto` — `(kind, parsed, skipReason,
    unmatchedReason)`, a sum type with `parsed()`, `skipped()`
    and `unmatched()` constructors.
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
    recent pending conflict, or null.

`Internal/` houses the implementation:

- **Internal/MatcherRegistry** — tag-discovered matcher list,
  sorted by `priority()` descending at register time.
- **Internal/Matchers/** — `PaypalReceiptMatcher`,
  `IcsReceiptMatcher`, `GooglePlayReceiptMatcher`. Each
  parses its sender's HTML / text body, extracts the per-line
  receipt structure, returns a `MatchOutcomeDto`.
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
       → matcher returns MatchOutcomeDto
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
       → ReceiptConflictToast renders
       → user picks resolution
            → ApplyReceiptConflictResolution
                 → write resolution; clear conflict
```
