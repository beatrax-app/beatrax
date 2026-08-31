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
│   │   ├── ReceiptFileShape.php
│   │   ├── ParsedMimeMessage.php
│   │   ├── FileDropEmlBlobStore.php
│   │   └── ReceiptSourceAdapter.php
│   └── Services/
│       └── ReceiptConflictQuery.php
├── Internal/
│   ├── MatcherRegistry.php
│   ├── ReceiptLedgerBridge.php
│   ├── Matchers/
│   │   ├── PaypalReceiptMatcher.php
│   │   ├── IcsReceiptMatcher.php
│   │   ├── GooglePlayReceiptMatcher.php
│   │   └── ReceiptBodyText.php
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
  - `ApplyReceiptConflictResolution::__invoke(User $user,
    ReceiptConflictChoice $choice): int` — returns how many
    conflicts the chosen policy resolved. The parameter is the
    enum, not its value: every producer already held one, and the
    string signature made each of them unwrap it so the receiver
    could re-parse it and throw on a value none of them could have
    produced.
- **DTOs/**
  - `MatcherInputDto` — `(id, userId, source, providerMessageId,
    senderEmail, senderName, subject, internalDate, emlPath)`,
    plus `MatcherInputDto::toInboxMessageDto()`, which is how the
    registry hands a matcher the shape `canHandle()` expects.
  - `MatchOutcomeDto` — `(kind, parsed, skipReason,
    unmatchedReason, matcherKey)`, a sum type built through
    `MatchOutcomeDto::parsed()`, `MatchOutcomeDto::skipped()` and
    `MatchOutcomeDto::unmatched()`. `skipped` is "I own this
    sender and this message is not a transaction"; `unmatched` is
    "no matcher claimed it". `matcherKey` is stamped by the
    registry via `fromMatcher($key)` and is null only for the
    no-matcher-claimed case.
  - `ParsedReceiptDto` — typed receipt structure.
  - `ChainHintPayload/*` — `FundedByCardPayload(cardLast4,
    cardKind)`, `RefundOfPayload(originalChargeRef)`.
- **Enums/**
  - `MatchOutcomeKind` — `parsed` / `skipped` / `unmatched`, plus
    `toInboxStatus(): InboxMessageStatus`. The map used to be
    prose in this enum's own comment and hand-rolled three times
    over: an if/elseif chain in `RecordReceipt`, another in
    `ProcessFetchedInboxMessagesJob`, and a ternary beside it.
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
  - `ReceiptFileShape::of(string $localPath): ?SourceFormat` — which of the
    two transports a file is, read off its own head against the two header
    profiles. The upload screen sets the format from it and `ParseStage`
    refuses a file that contradicts the declared one; neither trusts the pick,
    because reading an archive as a single message keeps only its first
    message.
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
  It stamps the answering matcher's own `key()` onto the outcome
  it returns, which is where `inbox_messages.matcher_key` and
  `file_imports.matcher_key` come from; each matcher used to write
  that same string a second time into an untyped `raw_payload` and
  both callers `is_string()`-guessed it back out of a `mixed`.
  `MatcherRegistry::supportedKeys()` is the audit list.
- `Internal/Matchers/PaypalReceiptMatcher` — parses PayPal
  receipt HTML / text; extracts the merchant, the per-line
  items, the funding-card hint when present.
- `Internal/Matchers/IcsReceiptMatcher` — parses ICS monthly
  statement notification HTML; emits per-card-statement
  summary + per-line items.
- `Internal/Matchers/GooglePlayReceiptMatcher` — parses Google
  Play purchase receipts; emits the per-item rollup
  (subscription rebill, in-app purchase, etc.). Its synthetic
  own-IBAN resolves to the `kind='google_play'` Account
  `Import::EnsureGooglePlayAccountAction` mints; before that
  action existed nothing could create one, so a Play receipt
  parsed, stamped its audit row `parsed`, and reached the ledger
  on neither path.
- `Internal/Matchers/ReceiptBodyText` — the injected collaborator
  the three matchers share: `plainText()` (entity-decode, strip
  tags, collapse whitespace), `amountMinor()` (a currency validity
  gate over `MoneyInput::tryToMinor`, which is told no currency at
  all), and the pair that keeps an anchor and its parse naming one
  currency — `currencyMarkers()` (the regex alternation of every
  glyph `Money::SYMBOLS` writes plus every `Currency` case) and
  `currencyMarked()` (that capture back as an ISO code). See
  [reading a receipt at the currency it names](architecture.md#reading-a-receipt-at-the-currency-it-names).
  Deliberately an object, not a trait: a trait reading the using
  class's promoted private properties makes them read as unused to
  this repo's static analysis.
- `Internal/ReceiptLedgerBridge::bridge(ParsedReceiptDto $parsed,
  User $user, ?int $importRunId, SourceFormat $sourceFormat): ?int`
  — the canonical write `RecordReceipt` deliberately leaves to its
  caller. Resolves the synthetic own-IBAN to an Account (no Account,
  no write — the reader is asked to name it in the preview wizard),
  adopts or opens the hourly `inbox-handoff` `ImportRun`, and returns
  the run id so a walk shares one. `ProcessFetchedInboxMessagesJob`
  and `ScanInboxDropFolderJob` are its two callers; the second used
  to discard the outcome and import nothing.
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
