# `Receipts` — architecture

The `Receipts` module takes raw `.eml` messages (from
`EmailScan`'s blob store or from a user-dropped file) and matches
them to the corresponding canonical transactions in the ledger.
Each matcher (PayPal, ICS, Google Play) extracts the per-line
breakdown, the merchant memo, and any chain hints (this PayPal
charge was funded by the user's ASN card; this ICS line is a
refund of a prior charge), then enriches the matched
`transactions` via `Import::ApplyEnrichments`. No statement
summary is written anywhere on that path: a receipt is its own
record, with no opening or closing balance and no statement
period.

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
  `.eml` blob persistence; this module consumes the blobs. The way in
  is `InboxMessageQuery` and the file on disk, never a provider client
  or an OAuth surface, and `noEmailFetchFromReceipts` fails the build
  on any file under `Modules/Receipts/` that imports one.
- It never inserts a transaction, and rewrites one only where the reader
  asked it to. Enrichments flow through `Import::ApplyEnrichments`. The
  one exception is `ApplyReceiptConflictResolution`, which applies the
  answer a reader gave to a conflict `ApplyEnrichments` had already
  recorded — see [Resolving a conflict is a ledger
  write](#resolving-a-conflict-is-a-ledger-write). `crossModuleRawTableWrites`
  pins that file and that table by name, so a second writer fails the
  build rather than joining it.
- It never records a statement summary, and cannot reach the writer
  that would. `RecordsStatementSummary` has one injection site in the
  tree — `ImportPipeline`'s constructor — and this module references
  that pipeline nowhere. The pipeline's own
  `persistStatementMetadata()` returns before it asks anything of a
  format `SourceAdapterRegistry` does not hold, and no receipt format
  is in it: `ParseStage` reads receipts on a separate arm, because a
  receipt file carries no account. `statement_summaries` belongs to
  Ledger and Receipts is not pinned for it, so a raw write from here
  fails `crossModuleRawTableWrites` as well.
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
    on hit, the outcome reaches the ledger as an enrichment
    through `ApplyEnrichments` (Import). No statement summary is
    written.
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
  `Categorization::AppliesAutoCategory` →
  `Counterparties::ResolvesCounterparties` →
  `Ledger::RecordsTransactions`, stamping the occurrence ordinal off
  the ledger on the way (see
  [the occurrence ordinal](../../architecture/ingestion-pipeline.md#the-occurrence-ordinal)).
  The count is taken outside the recorder's transaction, so a refused
  insert is recounted and retried only while the recount moved — see
  [a refused receipt insert](../../architecture/ingestion-pipeline.md#a-refused-receipt-insert),
  which also says why a blind `ordinal + 1` would write the purchase
  twice.
  The two stages between the
  normaliser and the recorder are the ones a wizard upload gets from
  `ImportPipeline`: without them the same message arrived
  uncategorised and with no counterparty row when the inbox fetched
  it, and categorised with one when the reader uploaded it. Both the
  inbox job and the drop-folder scan reach it here; the scan used to
  discard the outcome instead, which moved the file to `processed/`
  and left the ledger empty.
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
  `UserDataPathService::appPath('inbox-drop/{userId}')`, dispatched by
  `Internal/Console/ScanInboxDropFolderCommand`. It asked the container
  (`$app->storagePath('app/inbox-drop/…')`) until 2026-09-09: the same
  directory on a checkout, on the desktop and on Android, and a different
  one on iOS, where the shell announces a storage root that is not the
  durable store. `UserDataLocations` answers `appPath()` for this folder,
  so the deletion procedure and the export were walking the other tree.
  Its two failure lines name the file as `basename($path)` under a
  `filename` key, never the whole path: this is the one import surface
  where the reader supplies the name themselves, and the shipped log
  channel redacts that key and not `path`
  ([why](../../conventions/invariants-from-shipped-failures.md#a-file-name-the-reader-chose)).
  The `.error.txt` sidecar beside the quarantined file still keeps the
  whole message — it is the reader's own diagnostic, inside their own
  0700 data directory, and the only account of the refusal they get.
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
     `source_ref` on the matched transactions; per-hint, raise
     `ChainHintDetected`. No statement summary is written —
     a receipt has no statement period to write one for.
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
through to nothing and a code-less total was denominated at the reader's base
(which the section below has since removed); ICS parsed at `Currency::Eur`
whatever the mail said; Google Play's settled leg required `(€… EUR)`, so a
store billing `(¥1,250 JPY)` settled in USD.

One alternation now serves all three anchors — `ReceiptBodyText::currencyMarkers()`,
built from `Money::SYMBOLS` plus the `Currency` cases — and
`currencyMarked()` turns whatever it captured back into the code the figure
is parsed at. It is deliberately closed to the codes this app names rather
than open to `[A-Z]{3}`: the ICS and PayPal anchors are matched against a
whole message body, where a bare three-letter class reads
`Referentienummer: ABC123` as an amount.

Closed on **both** ends of an anchor, not just the mark in front of the
figure. Google Play's settled leg took its marker from the alternation and
its code from a bare `[A-Z]{3}` under `/i`, which is the shape of an item
line as much as of a denominated figure: `Item: Strava Premium (30 day)`
answered before the `(€12,07 EUR)` further down the same mail and settled
the receipt at `-3000 USD` — not the figure, not the currency, and not a
leg the message states anywhere. `preg_match()` returns the first match in
the body, so a shape that can be read out of prose does not merely add a
reading, it displaces the real one.

### A total the message denominated with nothing is a miss

The reader's base used to survive in one place, `nativeFromLabelled()`: a PayPal
total carrying no code and no glyph was denominated at `users.base_currency`.
That column is a **reporting** preference — what roll-ups render in
([B10](https://github.com/beatrax-app/spec/blob/main/10-functional/features/b-ledger/b10-multi-currency.md)
bounds it to the roll-up and leaves accounts their own money) — and a preference
is not a fact about anybody's money. It is also mutable, so one message had as
many readings as the picker in `/settings` has options, and a re-parse after the
reader changed it gave a different answer from the first.

The currency does not label the figure, it **scales** it. Measured on
`Bedrag: 1250` under four reporting currencies, one message and one set of
bytes: `EUR 1 250,00`, `USD 1 250.00`, `GBP 1 250.00` — and `JPY 1 250`, a
hundredth of the others in major units, because a yen has no minor unit.
`Bedrag: 12,50` was a booked transaction for a euro reader and a **miss** for a
yen one, the decimal being a shape JPY cannot hold.

So the anchor is gone and the fallback with it. A figure under one of PayPal's
own labels with no code and no glyph against it is recorded as a miss naming
`unmarked_total` — the outcome
[A5-R3](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a5-receipt-matching.md)
already reserves for a message nothing can read, which keeps the bytes on
`file_imports` for a matcher that reads more of the format later. ICS and Google
Play already worked this way: both require a mark before they will read a figure
at all, and neither ever used the value handed to them. `SenderMatcher::match()`
no longer takes one, so no matcher can reach for a reporting preference again.
Requiring a mark is not the same as reading the total, though, and the next
section is where that came apart.

This is the same shape as [an account denominated by its
reader](../import/an-account-is-denominated-by-its-statement.md), one layer
earlier — and with nothing to fall back *to*, because a receipt's total is the
receipt's own figure and the sender either printed its money or did not.

### A total is the figure its sender labelled

Requiring a mark before a figure is read says which figures are *readable*. It
does not say which one is the **total**, and every one of the three anchors was
matched against the whole body, so `preg_match` returned whichever readable
figure the sender had printed highest. A receipt prints several. Measured, one
line added to a shipped fixture's shape in each case:

| Sender | Body | Booked | The charge |
|---|---|---|---|
| PayPal | `Subtotaal: EUR 10,74` above `Bedrag: EUR 12,99` | −€10,74 | −€12,99 |
| PayPal | `Item price: $ 5.00 USD` above `Bedrag: EUR 12,99` | −$5.00 **USD** | −€12,99 |
| PayPal | `Je PayPal-saldo: EUR 0,00` above `Bedrag: 1250` | −€0,00 | a miss |
| ICS | `Uw bestedingslimiet is EUR 2.500,00` above `Bedrag: EUR 46,20` | −€2 500,00 | −€46,20 |
| Google Play | `Tax: $1.00 USD` above `Total: $12.99 USD` | −$1.00 | −$12.99 |
| Google Play | `Price: $11.99 USD (€11,14 EUR)` above `Total: $12.99 USD (€12,07 EUR)` | −$11.99 / €11,14 | −$12.99 / €12,07 |

The third row is the section above being bypassed rather than a case of its own.
`unmarked_total` is only reached where `extractCharge()` found nothing, so one
denominated line anywhere in the body was enough to have a total the message
marked with nothing replaced by a figure that is not it, at a currency it never
named. The guard held only for a receipt printing exactly one figure.

So the anchors are labelled. `ReceiptBodyText::underLabel($labels, $figure)`
composes each sender's own total labels in front of the figure pattern it
already had — PayPal `Transactiebedrag|Totaalbedrag|Bedrag|Totaal|Amount|Total`,
ICS `Bedrag|Amount`, Google Play `Order total|Total` and then `Price|Amount` for
a receipt stating no total. Its lookbehind is the label's own: **`Subtotaal` is
not `Totaal` and `Subtotal` is not `Total`**, and reading either books the
pre-tax figure. A longer label a sender does spell — PayPal's
`Transactiebedrag` — is listed rather than reached by substring, because that is
the only way to admit one and refuse the other.

Google Play's conversion moved onto the anchor with it. The settled leg was a
separate search for the first bracket in the body, which is how a total settled
at the price line's euros; the two now come out of one match, so a leg can only
belong to the figure it was printed beside.

### A receipt whose only part is html

ICS and Google Play resolve a body as `textBody` or else
`ReceiptBodyText::plainText($htmlBody)`. PayPal took `textBody ?? htmlBody` and
handed the **markup** to its anchors: `&euro;` is not the glyph
`currencyMarkers()` holds, and a tag standing between a label and its value
defeats `Transaction ID:`, `Aan:` and the total anchor alike. A receipt PayPal
delivered as html only was therefore `unmatched` with **no reason recorded** —
the charge reached no ledger and the `file_imports` row said nothing about why.
It resolves its body the same way its two siblings do now.

`plainText()` also breaks at every block and cell boundary before the tags go.
`strip_tags()` joins what the markup kept apart, so a minified receipt table
came back as one line — `AMAZON.COMBedrag:€ 46,20Referentienummer:XYZ123` — and
that fused the merchant name with everything printed under it. It is also what a
labelled anchor cannot survive: a label run together with the value above it is
no longer in front of anything. Real receipt mail is minified, so the two halves
of this are one fix.

## A direction nothing states is not a charge

Every matcher negated the figure it read, unconditionally. A receipt confirms a
payment, so a receipt's figure is money out — except that a card issuer and a
wallet both mail on the way back too, and a credit notification therefore
booked as a **second outgoing charge**: the money came back and the ledger
recorded it leaving again, so one purchase was charged twice and every balance,
budget and forecast over that row was out by twice the credit. Google Play was
the only matcher that refused one, and it refuses on the word `refund` in a
subject rather than on anything it read.

The wording is the whole problem, so it was researched before it was coded, and
the two senders came back differently.

**ICS states its direction, and this repository already holds the proof.**
`Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt` is the redacted
extraction of a real Mijn ICS statement: every figure on it is printed positive
with `Af` or `Bij` beside it, on the transaction rows and on the four summary
columns alike — `606,96 Bij` for a payment received, `43,71 Af` for a charge.
`IcsPdfAdapter` has read that grammar since it shipped; the notification matcher
never did. It does now, case-sensitively and anchored on the end of the figure's
own line, because `bij` is also the commonest Dutch preposition and
`Bedrag: € 12,00 bij Albert Heijn` is a purchase.

**ICS's refund *wording* could not be verified at all**, and the reason is worth
recording: ICS's transaction alerting is a paid SMS product —
[icscards.nl](https://www.icscards.nl/mijn-card/card-alerts) answers *"Nee, we
versturen Card Alerts alleen via sms"* — and the only two alert types it
documents are `Hoog bedrag` and `Online besteding`, both purchases. No
refund-notification type is offered, so there is no published refund mail to
learn the vocabulary from. The words a Dutch credit could use
(`terugbetaling`, `creditering`, `gecrediteerd`, `storno`, `terugboeking`,
`retour`, `terugstorting`, `terugvragen`) are therefore in the matcher **only to
refuse with**: a body carrying one and printing no marker beside its figure is
withheld as `ics_direction_unstated` rather than charged. Being wrong about one
of those words then costs a miss; being wrong the other way costs money. A
notification that states no direction in either form is withheld under the same
reason — the only wording admitted as proof of a charge is `aankoop`, whole-word,
which cannot name a credit in any reading. That is why five test bodies that had
never stated a direction gained the `Af` their real counterparts print.

**PayPal's direction could not be established in either language**, and its
floor is all it gets. PayPal publishes no transactional email copy: the wording
of a refund mail is not on paypal.com, not in the developer documentation, and
every "sample PayPal refund email" indexed elsewhere is a phishing specimen. The
[status labels](https://www.paypal.com/nl/cshelp/article/wat-betekent-de-status-van-mijn-betaling-of-betaalverzoek-op-mijn-paypal-rekening-help668)
are PayPal's own (`Terugbetaald` — *"De ontvanger heeft je betaling
teruggestort"*), PayPal's own
[returns form](https://www.paypalobjects.com/webstatic/nl_NL/mktg/consumer/pages/returns/Formulier_NL_temporary.pdf)
calls the mail *"de e-mail van PayPal met de bevestiging van de
terugbetaling"*, and `terugvordering` is
[its word for a chargeback](https://www.paypal.com/nl/cshelp/article/wat-is-een-terugvordering-en-waarom-heb-ik-er-een-gekregen-help607)
rather than for a refund. What none of them separates is a refund the reader
**received** from one they **issued** — and those two move money in opposite
directions. A refund the reader issued is a payment; a refund they received is
not. So PayPal never books a credit: any of that vocabulary in the subject or
the body is `paypal_direction_unstated`, and the figure is not booked in either
direction. The IPN taxonomy confirms the shape a producer would need —
a refund carries its own `txn_id` plus the original payment's
[`parent_txn_id`](https://developer.paypal.com/api/nvp-soap/ipn/IPNandPDTVariables/)
— but an event name is not an email, and nothing is implemented off it.

Both reasons are withholdings in the sense
[A5](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a5-receipt-matching.md)
already reserves: no enrichment, no exception, and the bytes stay on
`file_imports` for a matcher that reads more of the format later. Both senders
also have a twin ingestion format — the ICS statement PDF and the PayPal
activity CSV — so a withheld notification costs the merchant detail a receipt
adds, never the transaction.

`ChainHintType::RefundOf` finally has a producer, on the one path that can name
what it reverses: an ICS credit stating `Oorspronkelijke transactie` emits it
with that reference. It has to be labelled as the original, because
`REFERENCE_REGEX` matches on a substring and would otherwise read
`Oorspronkelijk referentienummer` as the credit's own. A credit naming no
original is still booked with the right sign and no hint — the sign is the
money, the hint is only the pairing, and
[B5](https://github.com/beatrax-app/spec/blob/main/10-functional/features/b-ledger/b5-chain-resolution.md)
resolves that pairing against `transactions.source_ref`, which every ICS
statement row leaves null.

### The euro column is the settled leg on both sides of one charge

An ICS card bills in euros. The statement prints a foreign charge in two
columns — `Bedrag in vreemde valuta` and `Bedrag in euro's`, `50,00 USD` and
`43,71` on the same row — and `IcsPdfAdapter` stores the first as the native leg
and the second as the settled one, which is what the card actually moved.

The notification matcher stored the **foreign** figure as both legs. So one
foreign charge was a different amount of money depending on which source
imported it: `-5000 USD / -5000 USD` from the mail against
`-5000 USD / -4371 EUR` from the statement covering it. The settled leg is the
pair every balance, budget and forecast sums —
[`TransactionAmount::relate()`](../../architecture/ingestion-pipeline.md) owns
both legs and the rate between them — and it is **outside** the fingerprint, so
the two
rows deduplicated against each other perfectly while holding two different
figures, and whichever arrived second was the one the reader saw.

The statement is the source of truth for the settled leg, because it reads the
column the issuer prints. The matcher now agrees with it: a euro total settles
in itself, a foreign total settles at the figure under the mail's own euro
label, and a foreign total with no euro figure anywhere is **withheld** as
`ics_euro_leg_unstated`. There is no third option — the euro amount is not
derivable from the foreign one without the rate the issuer applied, and the
statement PDF imports that charge correctly either way.

`FingerprintParityTest` pairs a foreign row now, not only the domestic one, and
compares the settled leg as well as the native. The gap is why nothing caught
this: the one ICS pair it held was a EUR row, where the two legs are the same
figure and the contract holds however either side denominates them.

## When a total is the thing that disagrees

Until the receipt's total was allowed to differ from the statement's, an
`amount_minor` conflict could reach the reader from exactly one state: a
row whose stored fingerprint no longer describes its own amount, which
Sync's per-field `applyFieldMerge()` can produce and which
`RederiveFingerprintOnMergedRows`, `beatrax:rederive-fingerprints` and
`FingerprintHealthCheck` exist to repair. That route is a guard — it turns
a corrupted row into a recorded disagreement rather than a silent
overwrite — and it is still there.

There is a second, intended route now. The amount is hashed into the
fingerprint, so a receipt printing a cent more than the statement hashed
to nothing stored and was written as a **second transaction** for one
purchase. `Import`'s `NearTotalMatch` answers the question the exact
lookup cannot: with every other term of the tuple equal and the currency
equal case-normalised, a total inside a band of the figure the receipt
states is the same event, and the difference between the two is a
disagreement to record rather than a row to insert. The band, what it
refuses, and why it is the match rather than the comparison that widened
are in [the ingestion
pipeline](../../architecture/ingestion-pipeline.md#a-total-inside-the-band).

Two consequences land here:

- The worked example below is reachable. A receipt reading EUR 13.00
  against a statement row of EUR 12.99 now raises an `amount_minor`
  conflict instead of a duplicate charge, which is what
  [A5](https://github.com/beatrax-app/spec/blob/main/10-functional/features/a-ingestion/a5-receipt-matching.md)'s
  edge-case table has always said should happen.
- `EnrichmentConflictField::Currency` stays reachable only from the
  corrupted-row route. The band is arithmetic on bare minor units, so it
  never reaches across currencies; a receipt naming a different code is a
  different transaction, and a receipt naming the same code in another
  case is the same one with nothing to disagree about.

What still produces a second row is a receipt the **inbox** path bridged:
`ReceiptLedgerBridge` reaches `RecordsTransactions` directly and never
asks `FingerprintStage` anything, so a near total inserted there is
untouched by this. That seam is named in the module boundary above and is
not closed.

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

## The toast asks two questions, not one

Every conflict the toast has ever shown had a receipt on the incoming
side: `ApplyEnrichments::resolutionFor()` settled a statement-incoming
disagreement as `prefer_first_write` without asking. [A row restating one
the ledger already
holds](../../architecture/ingestion-pipeline.md#a-reference-the-ledger-already-holds)
broke that assumption — its figure is written nowhere else, so it reaches
the reader with `resolution` NULL and a statement on both sides.

`ReceiptConflictQuery` answers which of the two it is, as
`incomingIsReceipt`, from `SourceRefRanker::isReceiptFormat()` rather than
re-deriving the split beside it: the copy has to name the same side of it
that the write-time policy did. Note what that rules out — a CSV preset id
such as `asn-csv` is not a `SourceFormat` case at all, so a predicate
written as "is this one of the receipt cases" and a predicate written as
"is this not a statement" disagree about it.

`ReceiptConflictToast::$restated` is the negation, and the view picks
between two key sets:

| receipt incoming | restatement |
|---|---|
| `conflict.title` | `conflict.restated_title` |
| `conflict.heading_different` / `heading_cleaner` | `conflict.heading_restated` |
| `conflict.body` (`:receipt`, `:statement`) | `conflict.restated_body` (`:incoming`, `:stored`) |
| `conflict.use_receipt` | `conflict.use_restated` |
| `conflict.keep_statement` | `conflict.keep_stored` |

Both sets drive the same two actions and therefore the same stored policy:
`prefer_receipt` means "take the value the import brought" and
`prefer_first_write` means "keep the value on record". The column is named
for receipts because receipts were once the only source that disagreed;
the restatement copy asks the question in those terms instead, and storing
the answer is what stops the same restatement being offered on every later
statement of that period.

`pending_enrichment_conflicts.incoming_source_format` holds an import
run's source format, never a matcher key — eleven fixture rows across ten
test files had `paypal-receipt` in it, which is
`PaypalReceiptMatcher::key()` and not a format any import writes. Harmless while nothing read the column; it
chooses the reader's copy now.

## The conflict heading names a field the sentence never sees

`conflict.heading_different`, `conflict.heading_cleaner` and
`conflict.heading_restated` take the field name as
`:field` and put an adjective in front of it. That works in English, where the adjective
does not inflect. It does not work in a language that agrees the adjective with the noun's
gender, because the template cannot know which noun it will receive — `field.amount_minor`,
`.currency`, `.description`, `.counterparty_name` or `.default`.

Two locales already solve it by agreeing with a word the template owns:

| locale | template | agrees with |
|---|---|---|
| `de` | `Ein E-Mail-Beleg hat beim Feld :field einen anderen Wert` | `Wert`, fixed |
| `fr` | `Un reçu par e-mail enregistre :field autrement` | nothing — adverb, and the nouns carry their own article |
| `nl` | `Een e-mailbon heeft bij :field een andere waarde` | `waarde`, fixed |

**Still carrying the broken shape: `hr`, `sr`, `sl`.** Each inflects for a masculine noun
while `valuta` is feminine in the accusative — `drugačiji valutu`, `drugačen valuto`. The
remedy is the same restructuring, and it needs someone who reads the language; it has not
been guessed at here. `en` is unaffected.

One wart is shared with `de` and left alone: when the field is unrecognised, `field.default`
is itself the fixed noun, so the sentence reads `bij waarde een andere waarde` (German:
`beim Feld Wert einen anderen Wert`). It is a fallback for a field the match arm does not
name, and narrowing the diff mattered more than the stutter.
