# `Search` — architecture

The `Search` module gives the app full-text search over every retained
transaction: an FTS5 trigram index over counterparty name + description
+ tax note, kept in lockstep with every write, powering both the
`/transactions` search-and-filter surface and the ⌘K command-palette
server endpoint.

## What this module is for

Years of transaction history are only useful if a merchant name or
note can be found instantly. This module owns the denormalized search
document, the synchronous index writer, the typed-token query parser,
and the read path that composes FTS5 `MATCH`/`highlight()`/`snippet()`
with the existing filter dimensions (date, account, category,
counterparty, amount).

What the module explicitly does NOT do:

- It never owns the canonical transaction data — `transactions` stays
  Ledger-owned; this module only maintains a derived search document.
- It never introduces a second money type — amount-query matching
  reads `transactions.amount_minor`/`settled_amount_minor` directly.
- It never blocks a write on indexing failure being swallowed — the
  writer never catches; a failed FTS upsert rolls back the same
  import-chunk transaction that produced it, so the index and the
  table can never silently diverge.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `SearchIndexWriterContract::upsertForTransaction($id, $actorUserId)`
    / `deleteForTransaction($id, $actorUserId)` — lives in Public so
    the Tax module's `TagTransaction` action can reindex a transaction
    after a tax-note edit without crossing into Search Internal.
  - `SearchResultsProvider::paletteSections($user, $query)` — the
    palette-section contract `CommandPaletteModal` (DevMode) injects
    as nullable, without ever importing Search Internal.
- **Dto/**
  - `SearchFilters` — mirrors `TransactionsList`'s `#[Url]` filter
    property set (accounts/categories/counterparties/date/amount).
  - `SearchResultPage` — mirrors `TransactionListPage`, extended with
    settled-EUR aggregate totals and an optional "did you mean" string.
  - `SearchRowDto` — mirrors `TransactionRowDto`, extended with
    sentinel-marked `highlightedCounterparty`/`snippet` HTML.
- **Services/**
  - `SearchQuery::search(...)` / `palette(...)` — the read entry point.
  - `FtsHealthCheck` — exposes index-vs-table row-count health to
    Core's `DoctorCommand` without it reaching into Search Internal.

`Internal/` houses the implementation:

- **Internal/Services/SearchIndexWriter** — the synchronous writer
  implementing `SearchIndexWriterContract`.
- **Internal/Services/QueryParser** — extracts `account:`/`after:`/
  `before:`/`amount:`/`category:` typed tokens; the remainder becomes
  the FTS text query.
- **Internal/Services/EntityNameSearch** — name-only `LIKE`/decrypt-
  then-substring search across counterparties, categories, goals,
  pots, and recurring series for the palette's entity section.
- **Internal/Services/DidYouMeanSuggester** — a single levenshtein-
  based spelling suggestion when a query returns zero FTS results.
- **Internal/Services/SearchResultsProviderImpl** — composes
  `SearchQuery::palette()` + `EntityNameSearch::query()` into the
  `SearchResultsProvider` contract shape.
- **Internal/Listeners/IndexTransactionOnImport** — subscribes to
  `Import::TransactionImported`; calls the writer synchronously in the
  same DB transaction as the canonical insert.
- **Internal/Http/Livewire/PaletteSearchEndpoint** — the ⌘K palette's
  server-backed search action, mounted in both the main and dev-shell
  layouts.
- **Internal/Console/ReindexSearchCommand** — `search:reindex`, the
  chunked full-rebuild recovery tool for any index/table desync.

## Key services + events

- `SearchIndexWriter::upsertForTransaction($id, $actorUserId)` — reads
  `counterparty_name`/`description` (decrypting via
  `Sync::SensitiveColumnCodec` when encryption is enabled — the FTS
  body must always be plaintext, never ciphertext, per the disclosed
  plaintext-shadow design) plus any tax note, concatenates them
  separated by `chr(12)` (a form-feed — not trigram-indexable, so it
  can never produce a false cross-field match), and upserts both
  `transaction_search_docs` and the FTS5 virtual table inside one DB
  transaction. `deleteForTransaction` mirrors this on permanent
  deletion. Both methods verify the caller-supplied `$actorUserId`
  against the row's owner before touching anything — a forged
  transaction id can never touch another user's index doc.
- `IndexTransactionOnImport::handle($event)` — the one production
  caller of the writer; runs synchronously so a transaction is
  searchable the instant the import commits (no queue dependency).
- `SearchQuery::search(...)` — parses typed tokens via `QueryParser`,
  resolves a candidate rowid set (FTS5 `MATCH` when the text query is
  ≥3 characters; a bounded decrypt-then-substring scan otherwise, since
  FTS5's trigram tokenizer needs a 3-character minimum and a
  ciphertext column can no longer be matched in SQL), applies the
  existing filter dimensions with per-dimension ownership validation,
  and returns a cursor-paginated `SearchResultPage` with
  `highlight()`/`snippet()` HTML built from sentinel markers
  (`\x02`/`\x03`) so the surrounding text is HTML-escaped before the
  sentinels become real `<mark>` tags — the raw FTS output is never
  rendered unescaped.
- `ReindexSearchCommand` (`search:reindex`) — truncates
  `transaction_search_docs` + issues an FTS `'delete-all'`, then
  chunks through every transaction (500 rows at a time) rebuilding the
  denormalized body, then issues an FTS `'rebuild'`. Exits non-zero
  with a warning when the indexed count doesn't match the transaction
  count, so a partial run is never silently treated as complete.

## Data flow

The synchronous index-on-import path:

```
Import::RecordTransactions inserts a transactions row
  → dispatch TransactionImported (same DB transaction)
  → Search::IndexTransactionOnImport::handle
       → SearchIndexWriter::upsertForTransaction
            → decrypt counterparty_name / description (if encrypted)
            → build search_body = counterparty + FF + description + FF + note
            → upsert transaction_search_docs
            → FTS 'delete' old posting (if a doc row already existed)
            → FTS insert new posting
       → any failure here rolls back the whole import chunk
```

The read path (⌘K palette or `/transactions` search mode):

```
User types a query
  → QueryParser::parse extracts account:/after:/before:/amount:/category: tokens
  → SearchQuery::resolveCandidateIds
       → textQuery >= 3 chars → FTS5 MATCH (escaped, ANDed per word)
       → textQuery <  3 chars → bounded decrypt-then-substring scan
  → buildBaseQuery + applyFilters (ownership-validated per dimension)
  → cursor pagination (posted_at, id) DESC
  → loadHighlights (FTS highlight()/snippet(), sentinel-marked)
  → zero results + query >= 4 chars → DidYouMeanSuggester::suggest
```
