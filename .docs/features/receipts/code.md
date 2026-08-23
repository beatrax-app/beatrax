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
│   │   ├── MatchOutcomeKind.php
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
  - `SenderMatcher::key(): string` — the stable lowercase-kebab
    id persisted on `inbox_messages.matcher_key` for audit and
    re-parse traceability.
  - `SenderMatcher::priority(): int` — higher sorts earlier;
    sender-specific matchers use 100, a future generic fallback
    would use 0.
  - `SenderMatcher::canHandle(InboxMessageDto $msg): bool` — the
    authoritative filter, over `EmailScan`'s inbox-message DTO
    rather than a Receipts type. Dispatch stops at the first
    matcher returning true and never consults the rest.
  - `SenderMatcher::match(string $emlRaw): MatchOutcomeDto` —
    takes the raw message, not a DTO, because a matcher that
    claimed the headers still has to read the body.
    Tag `receipts.matcher`; matchers are pure — no DB reads, no
    per-call state.
- **Actions/**
  - `RecordReceipt::__invoke(string $emlBytes, User $user,
    ?string $sourceFilename = null): MatchOutcomeDto` — sole
    entry point.
  - `ApplyReceiptConflictResolution::__invoke(User $user, string
    $choice): int` — returns how many conflicts the chosen policy
    resolved.
- **DTOs/**
  - `MatcherInputDto` — `(id, userId, source, providerMessageId,
    senderEmail, senderName, subject, internalDate, emlPath)`,
    plus `MatcherInputDto::toInboxMessageDto()`, which is how the
    registry hands a matcher the shape `canHandle()` expects.
  - `MatchOutcomeDto` — `(kind, parsed, skipReason,
    unmatchedReason)`, a sum type built through
    `MatchOutcomeDto::parsed()`, `MatchOutcomeDto::skipped()` and
    `MatchOutcomeDto::unmatched()`. `skipped` is "I own this
    sender and this message is not a transaction"; `unmatched` is
    "no matcher claimed it".
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
  - `ChainHintDetected` — `(int $sourceTransactionId,
    ChainHintType $hintType, object $hintPayload, string
    $evidence, int $userId)`. `hintPayload` is a typed sub-DTO
    from `ChainHintPayload/`, deconstructed with `instanceof`
    rather than array access.
  - `ReceiptConflictDetected` — `(int $transactionId, int $userId,
    string $field, ?string $receiptValue, ?string $csvValue,
    ?int $importRunId)`. It carries the conflict itself, not a
    row id: the toast renders from the event.
- **Pipeline/** — pre-parse layer: MIME reader, header
  profiles, mbox iterator, drop-in blob store, source
  adapter.
- **Services/**
  - `ReceiptConflictQuery::latestForUser(User $user):
    ?array{transactionId, field, storedValue, incomingValue,
    sourceFormat}` — the SINGLE most recent
    `pending_enrichment_conflicts` row for the user, or null.
    Not a list and not a DTO: its one consumer is
    `ReceiptConflictToast::mount()`, which shows one conflict
    at a time, so there is no `PendingConflictDto` and no
    pending-list read. Every read is scoped by `user_id`, so a
    foreign conflict cannot surface. `storedValue` /
    `incomingValue` come back JSON-decoded to a string, since
    the column stores the scalar encoded.

## Internal services

- `Internal/MatcherRegistry::dispatch(MatcherInputDto $input,
  string $emlRaw): MatchOutcomeDto` — walks the priority-sorted
  matcher list and returns the outcome of the first matcher whose
  `canHandle()` claims the message; `MatchOutcomeDto::unmatched()`
  when none does. There is no lookup method that hands the matcher
  back to the caller: the registry dispatches, so no caller can
  hold a matcher and call it on a message it did not claim.
  `MatcherRegistry::supportedKeys()` is the audit list.
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
