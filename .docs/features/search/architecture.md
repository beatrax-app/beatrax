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
    "did you mean" string.
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
  `counterparty_name`/`description` (decrypting via
  `Sync::SensitiveColumnCodec` when encryption is enabled — the FTS
  body must always be plaintext, never ciphertext, per the [disclosed
  plaintext-shadow design](../sync/sensitive-columns-at-rest.md#what-this-does-not-fix))
  plus the WHOLE-TRANSACTION tax note, concatenates them
  separated by `chr(12)` (a form-feed — not trigram-indexable, so it
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
  seven classes across five modules do:

  | Caller | Module | When |
  |---|---|---|
  | `IndexTransactionOnImport` | Search | a row lands from an import |
  | `TagTransaction` / `UntagTransaction` | Tax | a tax note is written or cleared |
  | `DeleteTransaction` | Ledger | a transaction is permanently deleted |
  | `StripAsnDescriptionDelimiters` | Ledger | the delimiter sweep rewrites a description |
  | `CashBookPage` | CashBook | a manual entry is deleted |
  | `SearchIndexRefresher` | Sync | a merged op changed a transaction |
  | `OpLogRebuilder` | Sync | history is replayed from the op log |

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
+ `SearchQuery::search(...)` — parses typed tokens via `QueryParser`,
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
  is left out rather than indexed as an empty body. Exits non-zero with
  a warning when the indexed count doesn't match the transaction count,
  so neither a partial run nor a skipped row is silently treated as
  complete.

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
  → SearchTokenFilters::merge folds those tokens into the SearchFilters
       → account: → resolveAccountNamesToIds (prefix LIKE on accounts.name)
       → category: → resolveCategoryNameToIds (prefix match in PHP, on the
            resolved display name AND the stored name)
  → SearchQuery::resolveCandidateIds
       → textQuery >= 3 chars → FTS5 MATCH (escaped, ANDed per word)
       → textQuery <  3 chars → bounded decrypt-then-substring scan
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
