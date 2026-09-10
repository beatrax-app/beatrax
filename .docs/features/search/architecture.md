# `Search` — architecture

The `Search` module gives the app full-text search over every retained
transaction: an FTS5 trigram index over counterparty name, description,
the reader's own transaction and split-leg notes and the tax note, kept
in lockstep with every write, powering both the `/transactions`
search-and-filter surface and the ⌘K command-palette server endpoint.

## What this module is for

Years of transaction history are only useful if a merchant name or
note can be found instantly. The words most worth finding are the ones
the reader wrote themselves, and those were the last to be indexed.
This module owns the denormalized search
document, the synchronous index writer, the typed-token query parser,
and the read path that composes FTS5 `MATCH`/`highlight()`/`snippet()`
with the existing filter dimensions (date, account, category,
counterparty, amount).

What the module explicitly does NOT do:

+ It never owns the canonical transaction data — `transactions` stays
  Ledger-owned; this module only maintains a derived search document.
+ It never introduces a second money type — amount-query matching
  reads `transactions.amount_minor`/`settled_amount_minor` directly.
  A typed amount — the `amount:` token, the `amount_min`/`amount_max`
  filters, and a bare number in the text box — is scaled at the
  **reader's own** currency through `BaseCurrency::forUser()`, not at a
  hard two decimals. A yen has no minor unit, so "20" became 2 000 of
  them: a JPY reader asking for "at least 20" lost every charge under
  ¥2 000, and the report row that opens this very list had already read
  the same figure as twenty yen. Pinned from both sides, by
  `AnAmountBoundIsReadAtTheReadersOwnScaleTest` here and by
  `ADrilldownCarriesTheFiltersTheFigureWasNarrowedByTest` in `Reports`.
+ It never writes its own shape check for a money string. Three regexes
  used to gate a typed figure on `\d{1,2}` before the parser saw it —
  one in `QueryParser` for the `amount:` token, two in `SearchQuery` —
  so a yen reader's `"12.50"` cleared the gate, failed the parse behind
  it, and the `?? 0` that caught the null searched for an amount of
  zero; a dinar's `amount:12.500-13.000` was truncated to `12.50`.
  `MoneyInput::tryToMinor()` IS the gate for the bare-number branch, and
  the token regex takes its fraction width from
  `MoneyInput::decimalPlaces()` at the reader's base
  ([minor units](../ledger/minor-units-and-zero-decimal-currencies.md#the-box-has-to-invite-the-shape-it-accepts)).
  `AYenReaderTypingAnAmountSearchesThatAmountTest` covers both.
+ It never blocks a write on indexing failure being swallowed — the
  writer never catches; a failed FTS upsert rolls back the same
  import-chunk transaction that produced it, so the index and the
  table can never silently diverge.

## Module boundary

`Public/` exposes the cross-module surface:

+ **Contracts/**
  + `SearchIndexWriterContract::upsertForTransaction($id, $actorUserId)`
    / `deleteForTransaction($id, $actorUserId)` — lives in Public so
    the Tax module's `TagTransaction` action can reindex a transaction
    after a tax-note edit without crossing into Search Internal.
  + `SearchResultsProvider::paletteSections($user, $query)` — the
    palette-section contract `CommandPaletteModal` (DevMode) injects
    as nullable, without ever importing Search Internal.
+ **Dto/**
  + `SearchFilters` — mirrors `TransactionsList`'s `#[Url]` filter
    property set (accounts/categories/counterparties/date/amount).
  + `SearchResultPage` — mirrors `TransactionListPage`, extended with
    aggregate totals in the reader's own base currency, the codes those
    totals left out for want of a rate (`isPartial()` /
    `unconvertedList()`, rendered in the search strip), and an optional
    "did you mean" string. The two totals are bucketed by
    [`MoneyFlow`](../ledger/architecture.md#moneyflow--the-one-definition-of-spend-income-and-net)
    like every other money figure in the app. They were bucketed by the
    amount's sign, which is the one thing that rule exists to prevent:
    both legs of one internal move landed in the strip, one on each side,
    so moving EUR 500.00 between two of the reader's own accounts read as
    EUR 500.00 out and EUR 500.00 in.
  + `SearchRowDto` — mirrors `TransactionRowDto`, extended with
    sentinel-marked `highlightedCounterparty`/`snippet` HTML.
+ **Services/**
  + `SearchQuery::search(...)` / `palette(...)` — the read entry point.
  + `FtsHealthCheck` — exposes index-vs-table row-count health to
    Core's `DoctorCommand` without it reaching into Search Internal.

`Internal/` houses the implementation:

+ **Internal/Services/SearchIndexWriter** — the synchronous writer
  implementing `SearchIndexWriterContract`.
+ **Internal/Services/QueryParser** — extracts `account:`/`after:`/
  `before:`/`amount:`/`category:` typed tokens; the remainder becomes
  the FTS text query. `parse()` takes the reader's currency because the
  `amount:` token's fraction is that currency's.
+ **Internal/Services/SearchTokenFilters** — resolves what `QueryParser`
  pulled out of the query into filter values: account and category names
  to ids (`NO_SUCH_ID` when a token matches nothing, so an unresolvable
  token narrows the search rather than widening it to the whole history),
  and an `amount:` token to its min/max pair at the reader's own scale.
+ **Internal/Services/EntityNameSearch** — name-only search across
  counterparties, categories, goals, pots, and recurring series for
  the palette's entity section. Goals, pots and series are `LIKE`-
  matched in SQL; counterparties are decrypt-then-resolve-then-substring
  matched in PHP because `display_name` is ciphertext once encryption is
  enabled *and*, for a row the app had to name itself, stores English
  while the reader sees a translation (see
  [the app's own words](../counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name));
  categories are resolve-then-substring matched in PHP because a
  default category's stored name is English while the reader sees a
  translation (see [category display names](../ledger/category-display-names.md)).
  Both the stored word and the reader's are matched, and only the
  reader's is shown. The counterparty read is one statement over the
  reader's own rows, and resolving the app's word adds neither a
  statement nor a row —
  `Modules/Search/tests/Feature/APlaceholderCounterpartyIsFoundByTheReadersOwnWordTest.php`
  pins that at 1 statement / 601 rows over 1001 counterparties.
+ **Internal/Services/DidYouMeanSuggester** — a single levenshtein-
  based spelling suggestion when a query returns zero FTS results.
+ **Internal/Services/PaletteSectionComposer** — composes
  `SearchQuery::palette()` + `EntityNameSearch::query()` into the
  `SearchResultsProvider` contract shape. `palette()` returns its hits
  and the full hit count together: reading the count from a second
  search ran the whole query twice on every keystroke.
+ **Internal/Services/LikeNeedle** — the one place a `LIKE` pattern is
  built, because SQLite gives `LIKE` no escape character unless the
  predicate declares one. Emitting the escaped pattern and the
  `ESCAPE` clause from the same call is what stops the two separating.
+ **Internal/Listeners/IndexTransactionOnImport** — subscribes to
  `Import::TransactionImported`; calls the writer synchronously in the
  same DB transaction as the canonical insert.
+ **Public/Http/Livewire/PaletteSearchEndpoint** — the ⌘K palette's
  server-backed search action, mounted in both the main and dev-shell
  layouts.
+ **Internal/Console/ReindexSearchCommand** — `search:reindex`, the
  chunked full-rebuild recovery tool for any index/table desync.

## Key services + events

+ `SearchIndexWriter::upsertForTransaction($id, $actorUserId)` — reads
  `counterparty_name`/`description`/`note` (decrypting via
  `Sync::SensitiveColumnCodec` when encryption is enabled — the FTS
  body must always be plaintext, never ciphertext, per the [disclosed
  plaintext-shadow design](../sync/sensitive-columns-at-rest.md#what-this-does-not-fix))
  plus the WHOLE-TRANSACTION tax note and one field per split leg's
  note, concatenates them separated by `chr(12)` (a form-feed — not trigram-indexable, so it
  can never produce a false cross-field match), and upserts both
  `transaction_search_docs` and the FTS5 virtual table inside one DB
  transaction. `deleteForTransaction` mirrors this on permanent
  deletion. Both methods verify the caller-supplied `$actorUserId`
  against the row's owner before touching anything — a forged
  transaction id can never touch another user's index doc.
+ `IndexTransactionOnImport::handle($event)` — the import path's
  caller, running synchronously so a transaction is searchable the
  instant the import commits (no queue dependency). It is not the only
  one. **Every write that changes indexed text has to reindex**, and
  fourteen classes across eight modules do:

  | Caller | Module | When |
  |---|---|---|
  | `IndexTransactionOnImport` | Search | a row lands from an import |
  | `TagTransaction` / `UntagTransaction` | Tax | a tax note is written or cleared |
  | `DeleteTransaction` | Ledger | a transaction is permanently deleted |
  | `SetTransactionNote` | Ledger | the reader writes or clears a transaction note |
  | `SaveTransactionSplit` | Ledger | legs are saved or unsplit, carrying leg notes |
  | `StripAsnDescriptionDelimiters` | Ledger | the delimiter sweep rewrites a description |
  | `ApplyEnrichments` | Import | a receipt's name or description wins a conflict at import |
  | `ApplyReceiptConflictResolution` | Receipts | the reader answers a held conflict with the receipt's value |
  | `EntityChangeApplier` | Migration | a re-run migration restates a description |
  | `CashBookPage` | CashBook | a manual entry is deleted |
  | `SearchIndexRefresher` | Sync | a merged op changed a transaction |
  | `OpLogRebuilder` | Sync | history is replayed from the op log |
  | `SearchIndexRepair` | Search | a keyless process refused a body and a keyed one is here |

  A write that skips the writer leaves the index stale, and nothing
  but a search that no longer finds the row will say so. The list is
  held to the code by `Modules/Search/tests/Unit/TheDocNamesEveryWriterCallerTest.php`,
  which fails when a new caller is not named here.

  **"The tax note" means the whole-transaction tag, and both writers
  now say so.** `tax_transaction_tags` also holds one row per tagged
  SPLIT LEG, and the only writer of a leg tag (`ManagesSplitEditor`)
  passes no note at all. Neither index writer named which row it wanted:
  `SearchIndexWriter` took whichever `first()` returned and
  `ReindexSearchCommand` looped them all into a map, keeping the last —
  so `search:reindex` overwrote the note with the leg's null and a row
  the app had indexed stopped being findable by the words on it. Both
  now filter `transaction_split_id IS NULL`, which is the same predicate
  `RuleApplier::writeTaxTag()` reads a current tag through, and which
  lets the partial `tax_tags_whole_tx_unique` index answer.
  `ALegTagMustNotEraseTheTransactionsOwnTaxNoteTest` pins both writers
  in both tag-write orders.

  **A leg's own note is a field of its own, and the legs are ordered.**
  A transaction can carry any number of legs, so the body's field count
  is the row's rather than the schema's: each leg contributes one field,
  in `sort_order` then `id` order, appended after the tax note. One
  joined string would let a needle span two legs, which is the same
  thing the form-feed exists to prevent between the other fields.
  `sort_order` is reassigned on every save and does not identify a leg
  on its own, so the id breaks its ties — and both writers apply that
  ordering through `SplitLegNotes::ordered()`, because a rebuild that
  chose its own order would rewrite every split transaction's document
  for no reason and leave the FTS `'delete'` of the old body matching
  nothing. `ANoteTheReaderWroteIsFoundByItsOwnWordsTest` asserts the two
  bodies are byte-identical across a `search:reindex`.
+ `SearchQuery::search(...)` — parses typed tokens via `QueryParser`,
  resolves a candidate rowid set (FTS5 `MATCH` when the text query is
  ≥3 characters; a bounded `LIKE` over that same indexed body
  otherwise, since FTS5's trigram tokenizer needs a 3-character
  minimum — both arms therefore search one corpus, and a needle does
  not change what it is matched against at the third character),
  applies the
  existing filter dimensions with per-dimension ownership validation,
  and returns a cursor-paginated `SearchResultPage` with
  `highlight()`/`snippet()` HTML built from sentinel markers
  (`\x02`/`\x03`) so the surrounding text is HTML-escaped before the
  sentinels become real `<mark>` tags — the raw FTS output is never
  rendered unescaped.
+ `ReindexSearchCommand` (`search:reindex`) — partitions users into
  rebuildable and blocked (a user whose columns are encrypted and whose
  key material this process does not hold is refused outright, before
  anything is deleted), deletes `transaction_search_docs` **for the
  rebuildable users only**, chunks through their transactions (500 rows
  at a time) rebuilding the denormalized body, then issues a single FTS
  `'rebuild'` — the table is external-content, so that regenerates every
  posting from the docs table and a skipped user's untouched rows come
  back exactly as they were. There is no `'delete-all'`.
  A single row whose column the codec **blanks** — ciphertext under an
  epoch this device lacks, on a user whose current epoch does open —
  is left out rather than indexed as an empty body; a leg note is judged
  by the same rule as the columns beside it, so one unreadable leg
  refuses the whole document rather than indexing a body missing a
  field. Exits non-zero with
  a warning when the indexed count doesn't match the transaction count,
  so neither a partial run nor a skipped row is silently treated as
  complete.

## The columns the body is composed from, and the guard over them

`Modules/Search/Public/Support/SearchedColumns` names them once —
`transactions.counterparty_name`, `transactions.description`,
`transactions.note`, `transaction_splits.note` and `tax_transaction_tags.note`
— and `SearchIndexWriter`, `ReindexSearchCommand` and every writer that has to
ask "did I touch one?" read the set from there rather than restating it.
`transaction_splits` is a source table like the other two, so
`Sync::SearchDocumentRows` tracks index freshness for it as well: without that,
a leg note edited on one device syncs while the receiving device's document
still answers for the words the leg used to carry.

The doc table above only names the classes that already call the writer; it
cannot see one that writes an indexed column and calls nothing, which is how
three of them shipped. `tests/Contracts/AWriteToASearchedColumnRefreshesItsIndexArchTest.php`
closes that direction. Its subject is every call to
`SensitiveColumnCodec::encryptAttrs()` / `::encryptValue()` naming one of those
tables, because sealing is the mandatory door into an at-rest-encrypted column:
a writer cannot reach one of the five without passing through it, not even a
writer that names its column through an enum rather than a literal. A seal site
whose table argument the scanner cannot read is reported rather than assumed
innocent. Each site either reaches `SearchIndexWriterContract` or is pinned with
the reason its write leaves the document still describing the row.

## A document that outlives its transaction

The body is a plaintext shadow, so a document left behind by a deleted row is a
deleted purchase's merchant name still readable on disk — and still answering a
`MATCH`. A database cascade deleted transactions without telling the writer
until it was removed tree-wide, and removing the cause left the residue: one
real install carried 102 such documents, and a search for one of their
merchants returned 13 rows that do not exist.

`search:reindex` would clear them — it drops a user's documents before rebuilding
them — and cannot here, because it skips a user whose columns a console run
holds no key for, which on an encrypted desktop is every user. So a forward
migration removes them instead, through `SearchIndexWriterContract` rather than
a `DELETE`: `transaction_search_fts` is external-content, so deleting the row
alone leaves the terms behind, which is the whole of what is being removed.

`beatrax:doctor` reports the count, so an install that grows one says so.

## A column this process cannot read

The index body is a plaintext shadow of five sealed columns —
`transactions.counterparty_name`, `transactions.description`,
`transactions.note`, every `transaction_splits.note` on the row and the
whole-transaction `tax_transaction_tags.note`. It is the only searchable copy
of them, which is why the shadow is disclosed rather than hidden.

The last three of those are free text the reader wrote, which makes them the
most sensitive thing in the shadow and worth stating plainly: **a transaction
note and a split-leg note are stored a second time, in the clear, in
`transaction_search_docs.search_body`**, on the same disk and inside the same
backup as the sealed original. AEAD over a column FTS5 has to tokenize matches
nothing, so the choice is a searchable note or a private one; the product
decision is that a note nobody can find is not worth writing, and the copy is
disclosed rather than hidden. `SensitiveFieldRegistry::knowinglyPlaintext()`
carries the same statement where an at-rest audit will read it. Nothing else
widens: `transactions.raw_payload`, the mailbox columns and the counterparty
identity columns are not composed into the body and this change did not put
them there.

Widening the composition does not widen what is already on disk. Every note
written before it is in the ledger and in no body, and nothing rebuilds one on
its own: no later write touches the row, a sync does not, and `search:reindex`
cannot on an encrypted desktop, where a console run holds no app-lock key.
`2026_09_10_000001_index_the_notes_written_before_the_index_read_them` walks the
transactions that carry a note — their own or a leg's — through
`SearchIndexWriterContract`, so there is one composer of the body rather than a
second one written in SQL. It is also what makes a keyless run of that migration
safe: handed a note it cannot open, the writer leaves the stored body alone and
queues the coordinate, and the next unlocked request finishes it.

`SensitiveColumnCodec::decryptValue()` never throws. Handed ciphertext it holds
no epoch for, it returns the empty string with `decrypted: false`. That is the
right answer for a screen, which renders a blank cell, and the wrong one for
this index, which wrote the blank over the words.

`sync:serve` is a console daemon with no app-lock key, and a peer's catch-up is
replayed inside it, so every transaction the merge touched was re-indexed from
columns that process could not open. Measured across two pairing runs on two
real devices: **99 of 148** bodies emptied in the first and **5 of 148** in the
second, against **0 of 148** taken minutes before pairing and **0 of 21** for an
account that never synced. The desktop answered *"No transactions match"* for a
merchant its own ledger still held, while the phone that had just synced from it
found the row. Nothing threw, so the refresher's catch never fired and no row
anywhere recorded that it had happened.

Both writers now read a source column through one collaborator,
`SearchSourceText::read()`, which returns null for exactly the shape the codec
blanks. `ReindexSearchCommand` already refused such a row and left the count
short; `SearchIndexWriter` wrote it. One index with two answers was the defect,
so the rule lives in one place.

On a refusal `SearchIndexWriter` writes nothing at all. The stored doc is left
as it stands — a stale body still finds the row, an emptied one finds nothing —
and the coordinate is recorded in `search_index_repairs`, which holds
`(user_id, transaction_id, requested_at)` and no ledger content, because the
content is precisely what the refusing process could not read. One warning per
user per process names the refusal in the log, and the owed count reaches
`FtsHealthCheck`, so the doctor probe can report an index whose row count
matches the table and whose bodies are waiting for a key.

`SearchIndexRepair` drains that queue, `DRAIN_LIMIT` coordinates at a time,
through the same writer — so a row still unreadable is re-queued by the writer
rather than judged a second time by something that could disagree with it. It
runs from `Core::SealedLedgerRecovery`, beside the op-log re-projection and the
plaintext-residue re-seal, for the reason those two live there: on a desktop a
web request is the only thing that holds the app-lock key.

A pass that could not open a row stamps `failed_fingerprint` with the caller's
own keyring hash, and the gate skips a row already answered under it. Without
that bound a column sealed to an epoch whose wrap never reached this device is
re-decrypted on every request forever and recovers nothing — the same
recurrence, and the same cure, as
[telling "not yet openable" apart from "never openable here"](../sync/sensitive-columns-at-rest.md#telling-not-yet-openable-apart-from-never-openable-here).
The fingerprint is read without the app-lock key and changes whenever an epoch
is appended, replaced or rewrapped, so the arrival of the missing wrap is what
reopens the question.

The migration that creates the table seeds it from the damage already on disk —
an empty body over a transaction that still carries a description, a
counterparty name or a whole-transaction note — so an index emptied before this
shipped repairs itself on the next unlocked request. Without that seed there is
nothing to find it: neither a later sync nor time rebuilds a body, and
`search:reindex` cannot, because a console run holds no key either.

## Data flow

The synchronous index-on-import path:

```
Import::RecordTransactions inserts a transactions row
  → dispatch TransactionImported (same DB transaction)
  → Search::IndexTransactionOnImport::handle
       → SearchIndexWriter::upsertForTransaction
            → decrypt counterparty_name / description / note (if encrypted)
            → build search_body = counterparty + FF + description + FF + note
                 + FF + tax note + FF + one field per split leg's note
            → upsert transaction_search_docs
            → FTS 'delete' old posting (if a doc row already existed)
            → FTS insert new posting
       → any failure here rolls back the whole import chunk
```

The read path (⌘K palette or `/transactions` search mode):

```
User types a query
  → QueryParser::parse extracts account:/after:/before:/amount:/category: tokens
  → SearchTokenFilters::merge folds those tokens into the SearchFilters
       → account: → resolveAccountNamesToIds (prefix LIKE on accounts.name)
       → category: → resolveCategoryNameToIds (prefix match in PHP, on the
            resolved display name AND the stored name)
  → SearchQuery::resolveCandidateIds
       → textQuery >= 3 chars → FTS5 MATCH (escaped, ANDed per word)
       → textQuery <  3 chars → bounded LIKE on transaction_search_docs.search_body
  → buildBaseQuery + applyFilters (ownership-validated per dimension)
  → cursor pagination (posted_at, id) DESC
  → loadHighlights (FTS highlight()/snippet(), sentinel-marked)
  → zero results + query >= 4 chars → DidYouMeanSuggester::suggest
```

A typed `account:` or `category:` token that resolves to no id narrows
to **nothing**, via a sentinel id no row can hold. It used to drop the
filter instead, so an unresolvable term returned the reader's whole
history — which looks exactly like a filter that worked, and is worse
than an empty result because nothing about it says the term was not
understood.

## Related

+ [Category display names](../ledger/category-display-names.md) — why
  `categories.name` cannot be matched on its own, and the seam that
  answers what the reader actually sees.
+ [`Ledger` architecture](../ledger/architecture.md) — the
  `transactions` table this index is derived from.
+ [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md) —
  what the index writer and `search:reindex` have to decrypt, and what
  it means when a value comes back blank.
+ [The op-log merge rules](../sync/op-log-merge-rules.md#the-tables-a-search-document-is-built-from)
  — the three source tables a replay has to mark dirty for a receiving
  device's index to stay in step with the rows it just merged.
