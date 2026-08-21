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
  - `SenderMatcher::matches($input)`, `match($input):
    MatchOutcomeDto`, `priority(): int`. Tag
    `receipts.matcher`; matchers run highest-priority first.
- **Actions/**
  - `RecordReceipt::__invoke($input, $user): MatchOutcomeDto`
    — the single entry point. Dispatches the matcher;
    on hit, calls `ApplyEnrichments` (Import) and
    `RecordsStatementSummary` (Ledger).
  - `ApplyReceiptConflictResolution::__invoke($conflictId,
    $resolution, $user)` — the first-conflict toast handler.
- **DTOs/**
  - `MatcherInputDto` — `(emlBytes, headers, senderHints,
    messageId, ...)`.
  - `MatchOutcomeDto` — `(matched, parsedReceipt, enrichments,
    chainHints, statementSummary)`.
  - `ParsedReceiptDto` — the matcher's structured output.
  - `ChainHintPayload/FundedByCardPayload`,
    `ChainHintPayload/RefundOfPayload` — typed chain-hint
    payloads.
- **Events/**
  - `ChainHintDetected` — `(transactionId, payload, userId)`.
    Consumed by `Chains::CreateChainLinkFromHint`.
  - `ReceiptConflictDetected` — `(conflictId, userId)`.
    Consumed by the in-app toast.
- **Pipeline/**
  - `EmlMimeReader::parse($bytes)`, `EmlHeaderProfile`,
    `MboxIterator::iter($file)`, `MboxHeaderProfile`,
    `FileDropEmlBlobStore::store($path)`,
    `ReceiptSourceAdapter` — the per-source / per-format
    pre-parse layer.
  - `ParsedMimeMessage` — typed MIME parse result.
- **Services/**
  - `ReceiptConflictQuery::pending($user)` — pending
    conflicts.

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

- `RecordReceipt::__invoke($input, $user)` — the single
  sanctioned entry point.
  1. `MatcherRegistry::for($input)` — returns the
     highest-priority matcher that `matches($input)`.
  2. Matcher emits `MatchOutcomeDto`.
  3. If matched: `ApplyEnrichments` (Import) strengthens
     `source_ref` on the matched transactions;
     `RecordsStatementSummary` (Ledger) writes the per-period
     summary; per-hint, raise `ChainHintDetected`.
  4. If no matcher: log + return `MatchOutcomeDto::miss()`.
- `MatcherRegistry::for($input)` — iterates the
  priority-sorted matcher list; first `matches($input)` true
  wins.
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
       → MatcherRegistry::for picks PayPal / ICS / Google Play
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
       → FileDropEmlBlobStore::store($path)
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
