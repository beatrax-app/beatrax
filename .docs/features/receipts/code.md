# `Receipts` — code

The file-level map for the module.

## Directory layout

```
Modules/Receipts/
├── Public/
│   ├── Contracts/
│   │   └── SenderMatcher.php
│   ├── Actions/
│   │   ├── RecordReceipt.php
│   │   └── ApplyReceiptConflictResolution.php
│   ├── Dto/
│   │   ├── MatcherInputDto.php
│   │   ├── MatchOutcomeDto.php
│   │   ├── ParsedReceiptDto.php
│   │   └── ChainHintPayload/
│   │       ├── FundedByCardPayload.php
│   │       └── RefundOfPayload.php
│   ├── Enums/
│   │   ├── ChainHintType.php
│   │   └── ReceiptConflictChoice.php
│   ├── Events/
│   │   ├── ChainHintDetected.php
│   │   └── ReceiptConflictDetected.php
│   ├── Pipeline/
│   │   ├── EmlMimeReader.php
│   │   ├── EmlHeaderProfile.php
│   │   ├── MboxIterator.php
│   │   ├── MboxHeaderProfile.php
│   │   ├── ParsedMimeMessage.php
│   │   ├── FileDropEmlBlobStore.php
│   │   └── ReceiptSourceAdapter.php
│   └── Services/
│       └── ReceiptConflictQuery.php
├── Internal/
│   ├── MatcherRegistry.php
│   ├── Matchers/
│   │   ├── PaypalReceiptMatcher.php
│   │   ├── IcsReceiptMatcher.php
│   │   └── GooglePlayReceiptMatcher.php
│   ├── Listeners/
│   │   ├── HandleFileOpenedFromOs.php
│   │   └── DispatchChainHintsFromReceipt.php
│   └── Http/Livewire/
│       └── ReceiptConflictToast.php
├── Database/
│   └── Migrations/
│       ├── 2026_05_17_010001_create_file_imports_table.php
│       ├── 2026_05_17_010002_add_matcher_key_to_inbox_messages.php
│       └── 2026_05_17_010008_add_matcher_key_to_file_imports.php
├── Routes/
│   ├── web.php
│   └── console.php
├── Resources/views/
├── Providers/
│   └── ReceiptsServiceProvider.php
└── tests/
    └── Feature/
```

## Public API

- **Contracts/**
  - `SenderMatcher::matches(MatcherInputDto $input): bool`,
    `match(MatcherInputDto $input): MatchOutcomeDto`,
    `priority(): int`. Tag `receipts.matcher`.
- **Actions/**
  - `RecordReceipt::__invoke(MatcherInputDto $input, User
    $user): MatchOutcomeDto` — sole entry point.
  - `ApplyReceiptConflictResolution::__invoke(int $conflictId,
    string $resolution, User $user): void`.
- **DTOs/**
  - `MatcherInputDto` — `(emlBytes, headers, senderHints,
    messageId, sourceKind)`.
  - `MatchOutcomeDto` — `(matched, parsedReceipt,
    enrichments, chainHints, statementSummary)`. Static
    `::miss()` for no-match.
  - `ParsedReceiptDto` — typed receipt structure.
  - `ChainHintPayload/*` — `FundedByCardPayload(cardLast4,
    cardKind)`, `RefundOfPayload(originalChargeRef)`.
- **Enums/**
  - `ChainHintType` — `funded_by_card` / `refund_of` /
    `unknown`; the vocabulary of
    `raw_payload['chain_hints'][]['hint_type']`, distinct from
    Chains' own `ChainLinkKind`.
  - `ReceiptConflictChoice` — `prefer_receipt` /
    `prefer_first_write`.
- **Events/**
  - `ChainHintDetected` — `(sourceTransactionId, hintType,
    hintPayload, evidence, userId)`; `hintType` is a
    `ChainHintType`.
  - `ReceiptConflictDetected` — `(conflictId, userId)`.
- **Pipeline/** — pre-parse layer: MIME reader, header
  profiles, mbox iterator, drop-in blob store, source
  adapter.
- **Services/**
  - `ReceiptConflictQuery::pending(User $user):
    list<PendingConflictDto>`.

## Internal services

- `Internal/MatcherRegistry::for(MatcherInputDto $input):
  ?SenderMatcher` — iterates the priority-sorted matcher list;
  first `matches($input)` true wins.
- `Internal/Matchers/PaypalReceiptMatcher` — parses PayPal
  receipt HTML / text; extracts the merchant, the per-line
  items, the funding-card hint when present.
- `Internal/Matchers/IcsReceiptMatcher` — parses ICS monthly
  statement notification HTML; emits per-card-statement
  summary + per-line items.
- `Internal/Matchers/GooglePlayReceiptMatcher` — parses Google
  Play purchase receipts; emits the per-item rollup
  (subscription rebill, in-app purchase, etc.).
- `Internal/Listeners/HandleFileOpenedFromOs::handle($event)`
  — filters by `.eml` / `.mbox` extension; persists path into
  `Desktop::PendingFileIntent`.
- `Internal/Listeners/DispatchChainHintsFromReceipt::handle($event)`
  — handles `Import::TransactionImported`; if the row carries
  chain hints, dispatches `ChainHintDetected` per hint.
- `Internal/Http/Livewire/ReceiptConflictToast` — the
  first-conflict toast UI.

## Models + migrations

The module's domain model is the `file_imports` table; this
module does not own an Eloquent model class for it (a read-side
DTO + a raw query is the project's chosen shape here).

Migrations:

- `2026_05_17_010001_create_file_imports_table.php` — the
  per-file-drop audit row + matcher_key lookup column.
- `2026_05_17_010002_add_matcher_key_to_inbox_messages.php`
  — links an `InboxMessage` to the matcher that processed it.
- `2026_05_17_010008_add_matcher_key_to_file_imports.php` —
  same column on `file_imports`.

The `pending_enrichment_conflicts` table is owned by
[`Categorization`](../categorization/code.md); this module
reads + writes it through `ReceiptConflictQuery` /
`ApplyReceiptConflictResolution`.

## Provider wiring

`ReceiptsServiceProvider::register()`:

- Tag-loops `PIPELINE_FQNS` (every Public Pipeline class) as
  singletons.
- Tag-loops `MATCHER_FQNS` under `receipts.matcher`. Each
  binding is gated by `class_exists()` so a missing class
  skips gracefully.
- Singletons `RecordReceipt`,
  `DispatchChainHintsFromReceipt`,
  `HandleFileOpenedFromOs`,
  `ApplyReceiptConflictResolution`,
  `ReceiptConflictQuery`.
- Wraps `MatcherRegistry` in a factory closure that pulls
  every tagged matcher and sorts by `priority()` descending.

`ReceiptsServiceProvider::boot()`:

- Loads migrations, web/console routes, views (all
  file-/dir-existence guarded).
- Registers two Livewire components under the `receipts.*`
  namespace.
- Subscribes `DispatchChainHintsFromReceipt` to
  `Import::TransactionImported`.
- Subscribes `HandleFileOpenedFromOs` to
  `Desktop::FileOpenedFromOs` (the extension filter is
  inside the listener).
