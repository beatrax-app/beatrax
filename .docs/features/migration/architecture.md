# `Migration` — architecture

The `Migration` module is a one-shot (and optionally repeatable) importer for a
user's full budget history from a competing personal-finance tool — YNAB4,
nYNAB ("New YNAB"/"Plan"), or Actual Budget. The user uploads a ZIP export from
the wizard at `/migrations`, the export is parsed into a common intermediate
representation, staged for review, and — on confirm — promoted into the same
domain tables (`categories`, `accounts`, `transactions`, `envelope_assignments`,
`goals`) every other ingestion path in Beatrax writes to. A later "Check for
updates" pass can re-import a newer export of the same source and reconcile it
against what was already promoted, via a 3-way merge.

## What this module is for

Competing tools export a user's years of categorization/budgeting/transaction
history in incompatible shapes. Migration reads three of those shapes (YNAB4's
loose two-CSV folder, nYNAB's near-identical two-CSV export, and Actual's
SQLite database dump) and funnels them through the SAME public writers the
rest of the app uses — `EnvelopeWriter`, `RecordTransactions`,
`SaveTransactionSplit`, `GoalWriter` — so a migrated household's data behaves
identically to data imported any other way.

## Module boundary

- **Public/Contracts** — `ParsesMigrationSource`: the stable swap seam every
  format parser implements (`format()` + `parse()`). `StartMigrationRun`
  resolves the correct implementation by the user's declared source product.
- **Public/Actions** — the four write/read entry points: `StartMigrationRun`
  (parse + stage, no domain write), `ConfirmMigration` (promote staged rows +
  apply conflict resolutions), `DiscardMigrationRun` (truncate staging,
  including an abandoned-run sweep), `CheckForUpdates` (reconciliation entry
  point for a newer export of an already-confirmed source).
- **Public/Dto** — the ten-type intermediate representation (`MigrationBatch`
  and its per-entity DTOs), the `PreviewSummary`/`MigrationConfirmResult`
  read-models, and `ConflictDto` (the 3-way-merge conflict shape).
- **Public/Exceptions** — typed exceptions for "corrupt/unrecognized file",
  "already confirmed", "already discarded".
- **Internal/Parsers** — `AbstractYnabParser` (shared YNAB4/nYNAB parsing body)
  with its two one-line format subclasses, `ActualParser` (SQLite-backed), and
  their `Support/` helpers (amount-string parsing, CSV column mapping, split
  reconstruction, transfer matching, ZIP extraction).
- **Internal/Pipeline** — `StagingWriter` (batch → staging tables),
  `PromoteStagingToDomain` (staging → domain, the fixed six-writer order),
  `ThreeWayMergeResolver` + `EntityChangeApplier` + `ConflictRow`/
  `ConflictValueCodec`/`MergeDecision`/`PromoteResult` (the reconciliation
  machinery).
- **Internal/Services** — `SourceMapWriter` (the persistent per-entity dedup
  key) and `ActualSqliteReader` (read-only second SQLite connection).
- **Internal/Http/Livewire** — the four wizard pages: `MigrationsIndex`,
  `NewMigration` (upload), `PreviewMigration` (confirm/discard/resolve
  conflicts), `MigrationResults`.

## The parse → stage → preview → confirm pipeline

1. **`StartMigrationRun`** creates a `migration_runs` row (`status: 'parsed'`),
   resolves the matching `ParsesMigrationSource` implementation, parses the
   already-extracted export directory into a `MigrationBatch`, and hands it to
   `StagingWriter`. No domain table is touched — staging IS the preview state.
   Any throw during parse-or-stage deletes the just-created run row; every
   `migration_staging_*` FK cascades off `migration_run_id`, so a partial
   failure leaves nothing behind.
2. **`StagingWriter`** lands the batch's bounded collections (categories,
   accounts, payees, budget assignments, goals, already-known-unmapped items)
   directly, and streams the batch's lazy `Generator<MigrationTransactionDto>`
   into `migration_staging_transactions` in bounded chunks (mirrors
   `RecordTransactions`'s `CHUNK_SIZE = 500` convention) — a multi-year export
   never materializes wholly in PHP memory. A split-parent DTO flattens into a
   parent row plus one row per leg; a synthesized leg external id
   (`"{parent}/leg-{n}"`) covers legs that carry no source-native id.
   `settled_amount_minor`/`settled_currency` mirror `amount_minor`/`currency`
   verbatim at staging time — this importer never computes or fabricates FX.
   All writes are parameterized query-builder `insert()` calls; a source
   row's string content is never interpolated into SQL.
3. **`PreviewSummaryBuilder`** is a pure read model over `migration_staging_*`
   (never a domain table) computing the five mapped counts (categories,
   accounts, counterparties, transactions, budget months) plus a grouped
   unmapped-items summary (`extra`/`conflict`). Every read is `user_id`-scoped;
   a foreign-owned run resolves to a 404 via `ModelNotFoundException`, never a
   403. `transactionsCount` counts distinct staged transactions — a split
   parent counts once (its legs are additional rows excluded from this count).
   `budgetMonthsCount` counts DISTINCT `period_start` values ("how many months
   of budget history will import"), not the total assignment-row count. The
   `unmapped` result always carries both group keys, even when empty, so the
   wizard can render "everything mapped cleanly" only when every count is
   genuinely zero rather than a missing key; `'conflict'` is always empty for a
   first-time parse (only a reconciliation re-run populates it). Every unmapped
   item carries the underlying row's id so `PreviewMigration::resolveConflict()`
   can act on one specific conflict.

   There are two groups here and not four. `category` and `payee` were the
   other two, and nothing could ever fill them: the preview renders *before*
   promotion, so a promote-time failure could not reach it, and at parse time
   there is no such thing as an unresolvable category or payee — every one in
   the export becomes a staged row, and anything the parser genuinely cannot
   place goes to `extra`. Both counts were therefore structurally zero, which
   made the two sections permanently invisible and made a "fully mapped" badge
   keyed on them print on every import whether or not anything mapped. The
   groups, the badge and their translations are gone; the summary of unmapped
   items the preview owes the reader (`A8-R5`) is what `extra` and `conflict`
   deliver.
4. **`ConfirmMigration`** promotes via `PromoteStagingToDomain::promote()`
   (run OUTSIDE any wrapping transaction — its own per-entity writes are
   already bounded/chunked), then wraps only the status-flip + persisted
   summary counts in one transaction, so a committed `'confirmed'` status
   always implies the counts were durably recorded. Re-confirming an
   already-confirmed run short-circuits to the persisted counts rather than
   re-promoting.

## Idempotency and the natural-key fallback

Every promotion step first asks `SourceMapWriter::resolve()` whether a source
entity was already promoted by an earlier confirm of the same export; a hit
reuses the existing Beatrax id and performs no further writes. This is what
makes a byte-identical re-run a true no-op, not merely "safe to call again."
Budget-grid assignments are the one deliberate exception:
`EnvelopeWriter::setAssigned()` is already idempotent by value, so no separate
resolve-gate is needed there.

`SourceMapWriter` persists `(user_id, source_product, source_entity_type,
source_external_id) → (beatrax_entity_type, beatrax_id)` in
`migration_source_map`, and survives run confirm/discard (unlike the scratch
staging tables). Its sibling `migration_import_baseline` holds one row per
`(migration_source_map_id, field_name)` — the persistent third leg of the
3-way merge (the baseline value compared against on a later reconciliation).
Both tables are registered in `Modules\Sync\Internal\Config\MergeRulesRegistry`
as persistent multi-device sync surfaces.

`SourceMapWriter::resolve()` tries an exact `source_external_id` match first;
when the entity carries no stable source id, the natural-key fallback is
tried instead, but ONLY among rows that themselves have no
`source_external_id` — a natural key is never consulted as a fallback for an
entity that DOES carry a stable id, so a rename correctly surfaces as "field
changed" rather than wrongly resolving to the old natural key.
`SourceMapWriter::record()`'s upsert uses a manual existence check (rather
than a plain unique-constraint upsert) for the natural-key path, because
SQLite treats `NULL` as distinct-from-itself in a UNIQUE index — a second
insert with the same `NULL source_external_id` would otherwise slip past the
constraint entirely and create a duplicate map row. `record()` also
snapshots `$baselineFields`, one `migration_import_baseline` row per field,
upserted on `(migration_source_map_id, field_name)` so a re-import advances
the same row rather than accumulating history. When a source format carries no stable per-entity id (YNAB4/
nYNAB accounts and payees), a `natural_key` (normalized name, or
`"<group>/<name>"` for categories) is consulted instead — but only among rows
that themselves carry no `source_external_id`, so an entity that DOES have a
stable id never falls back to a natural-key match (a rename then correctly
surfaces as "field changed" rather than silently resolving to the old key).
Category natural keys are tagged with a fixed `'cat:'` prefix and group
synthetic-parent keys with a fixed `'grp:'` prefix — both unconditionally
prepended regardless of source content, so the two keyspaces are provably
disjoint for any possible group/category name, including a group literally
named "Group".

Migrated accounts have no real bank IBAN, so `PromoteStagingToDomain`
synthesizes a deterministic pseudo-IBAN (`'MIG' . crc32b(sourceExternalId)`)
— short enough for the `accounts.iban` column and stable across a re-run, so
`Transfers\PairsTransferLegs` can still match a migrated account's transfer
legs by IBAN like any other account. Migrated transactions likewise need a
satisfied `transactions.import_run_id` FK; `PromoteStagingToDomain` finds-or-
creates one synthetic `import_runs` row per `(user, sourceProduct)` (mirrors
`CashBook`'s manual-entry synthetic-fixture precedent).

None of the three source formats carry a transaction time-of-day, so
`postedAt`/`valueDate` are always midnight. Since `FingerprintComposer`'s
dedup tuple relies on `bookedAt`'s second-resolution to disambiguate two
otherwise-identical same-day rows, feeding it the same midnight value for
every row would collapse two genuinely distinct transactions into one.
`PromoteStagingToDomain` seeds `bookedAt` with a deterministic sub-day offset
derived from the staged row's own stable primary key, so two distinct staged
rows always get distinct fingerprints — `postedAt`/`valueDate` stay the exact
user-facing date; only the internal, never-displayed `bookedAt` carries the
synthetic offset.

## Format parsers

`AbstractYnabParser` holds the entire shared parsing body for YNAB4 and nYNAB
— the two formats diverge only in the category-column shape (`YnabCsvColumnMap`
handles both); every other convention (two-CSV export, split-memo
reconstruction via the `"Split (n/m)"` convention, transfer-payee pairing via
the `"Transfer : <Account>"` convention, cleared codes, amount format) is
identical. `Ynab4Parser`/`NynabParser` supply only the format identifier.
Neither format's CSV export carries goal or scheduled-transaction data, or a
per-row currency — the parser always stamps the batch with a fixed `'EUR'`
budget currency (Beatrax's own base currency, the documented fallback when a
source carries no currency signal at all).

`ActualParser` is the one format whose extracted directory is a SQLite
database rather than a CSV pair. It drives `ActualSqliteReader` — a wholly
separate, read-only `Pdo\Sqlite` connection (`OPEN_READONLY`; a write attempt
throws) opened directly against the untrusted extracted file, never joined
into the app's own `DatabaseManager` connection via SQLite's cross-database-
join keyword — doing so would share the app's single-writer WAL connection
state with an untrusted file and blur the never-write-back boundary this
class exists to enforce. It reads Actual's resolved `v_transactions`/
`v_categories`/`v_payees` views (never the raw tables, which carry stale
pre-merge ids and tombstoned rows the views already filter out), falling back
to a hand-replicated join+filter only when a very old export predates those
views. Unlike YNAB4/nYNAB, Actual carries real UUIDs for every entity, so no
natural-key synthesis is needed. `transactions()` streams rows via repeated
`PDOStatement::fetch()` calls (never `fetchAll()`), so a multi-year export
never materializes wholly in PHP memory; every row is narrowed through an
`is_array()` runtime check and every scalar column through `toStr()`/`toInt()`/
`toBool()` coercers, since `PDOStatement::fetch()`'s return type is untyped
in PHPStan's stubs regardless of fetch mode. `categories()`/`payees()`/
`goalDefs()` fall back to the raw, tombstone-filtered table via a simple SQL
ternary when the resolved view is absent; `transactions()` falls back to a
hand-replicated join+filter since its view resolution is more involved.
`budgetType()` normalizes Actual's legacy pre-migration preference values
(`'rollover'`→envelope, `'report'`→tracking) onto the current
`'envelope'|'tracking'` pair; `budgetAssignments()` branches on it to read
`zero_budgets` or `reflect_budgets` — an empty/absent result from the file's
OTHER, inactive-mode table is never reported as "no budget history", since
this method only ever queries the table matching the file's own active mode. `ActualGoalDefInterpreter` decodes the
`categories.goal_def` JSON blob's supported flat subset (a single target
amount with an optional target date) into a `MigrationGoalDto`; any other
shape (multi-step templates, percentage-of-income schedules) becomes an
`UnmappedItemDto('extra')` rather than a lossy flat approximation. Goals are
reachable only from Actual — neither YNAB CSV export carries goal/target
columns at all. The promote step calls `GoalWriter::save()` with `accountId:
null`, since Actual's goal template is category-scoped, not account-scoped. A
goal with no target date (Beatrax requires one) is likewise surfaced as
unmapped rather than inventing one. `Modules\Recurring` has no public write path to create a series from
external data, so a scheduled/recurring transaction (Actual's `schedules` +
`rules` tables only — neither YNAB export carries one) is deliberately
descoped to a note-only import: every schedule becomes exactly one
`UnmappedItemDto` (surfaced, never silently dropped) whose `note` is a plain
human-readable summary the parser assembles from the rule's conditions —
never a machine-parseable re-import format. Every
`json_decode()` of untrusted export content (goal defs, rule conditions) is
bounded by byte size and nesting depth before decoding, and every SQL string
is parameterized or a fixed code-controlled literal — source-file content is
never interpolated into SQL.

`YnabSplitReconstructor` reconstructs split-leg groups from the
`"Split (n/m)"` memo convention (the CSV export carries no explicit
parent/child link column) — deliberately conservative: only rows that are
BOTH memo-tagged AND adjacent under the same (Account, Date, Payee) key
collapse into a group. A lone memo-tagged row is left ungrouped, since a
genuine split always has 2+ legs, so a singleton is more likely a
coincidental "Split" substring in an otherwise plain memo.

`AmountStringParser` (the Dutch/plain-decimal amount-string parser for YNAB4/
nYNAB CSV cells) is copied verbatim from
`Modules\Budgets\Public\Services\EnvelopeWriter::parseAmount()` rather than
re-derived — any future bugfix to that shared parsing rule should be ported
here too. It matches that method's contract exactly: a blank or `0.00` cell
resolves to "no amount" (`null`), never zero.

`ZipExtractor` extracts an uploaded export ZIP into a scoped temp directory
under `storage/app/`, never a web-served path, guarding against a zip-bomb
(every entry's uncompressed size is read via `statIndex()` before any bytes
are written, with both an entry-count cap and a total-uncompressed-bytes cap)
and zip-slip (every entry name is checked for an absolute path or `..`
traversal segment; entries carrying a Unix symlink mode bit are rejected too
— a symlink's name itself is not inspected by the traversal check at all,
but `ZipArchive::extractTo()` can still materialize it as an actual
filesystem symlink whose attacker-controlled target a caller's later
`is_file()`/`fopen()` would silently follow outside the scoped directory;
only `OPSYS_UNIX`-tagged entries carry a meaningful Unix mode, so the check
is a no-op, never a false positive, for an archive built on another OS).
`$maxEntries`/`$maxTotalUncompressedBytes` are constructor-configurable so
tests can exercise both caps deterministically without constructing
multi-hundred-entry or multi-hundred-megabyte fixtures. The extracted
directory is tracked for `cleanup()` BEFORE `extractTo()` runs, so a
mid-extraction failure (disk full, permission error) still leaves
`cleanup()` able to find and remove whatever was partially written.

A budget-assignment row's `budgeted` amount is the assigned amount ONLY —
never a carried-forward balance (YNAB4's `Category Balance` / Actual's
derived running total); Beatrax's own `CarryoverQuery` derives balances, so
the promote step feeds `budgeted` straight into `EnvelopeWriter::setAssigned()`
and nothing else.

A `MigrationTransactionDto`'s `amount` is always the WHOLE-transaction
settled amount — for a split parent this is the sum of every leg (Actual's
own `is_parent` row already carries the parent total; the YNAB parsers
synthesize one by summing the legs reconstructed from the `"Split (n/m)"`
memo convention). `categorySourceExternalId` is null exactly when `splits`
is non-empty, since category lives on each leg, never the parent — matching
Actual's own `transactions.category IS NULL` for its `is_parent` rows.
`transferCounterpartSourceExternalId` is set on both legs of a
parser-detected transfer pair so the promote step can link them without
re-deriving the pairing logic; `Transfers\PairsTransferLegs::pairOrphansForUser()`
still runs once after promotion as the authoritative sweep, since the
parser's own textual pairing is advisory, not canonical.

`migration_source_map` records ONE row per promoted transaction, not one per
split leg — `SaveTransactionSplit::save()` has a `void` return (no leg ids to
map), and the parent-level resolve-gate already fully protects split-leg
re-run idempotency. A `transfer_pair` source_map entity type is likewise not
separately recorded: each transfer leg already gets its own `'transaction'`
source_map row, which is enough to relocate either leg individually;
`pair_transaction_id` on the transaction rows themselves is what the
transfer linkage actually rests on.

## Promotion order

`PromoteStagingToDomain::promote()` runs a fixed sequence: categories → budget
grid (`EnvelopeWriter::setAssigned()`) → accounts → transactions (via
`ResolvesCounterparties::run()` THEN `RecordTransactions`, in that order,
since the stamped counterparty id is what `RecordTransactions` persists) →
splits (`SaveTransactionSplit`) → transfer sweep
(`PairsTransferLegs::pairOrphansForUser()`) → goals (Actual only). Categories
are promoted in staging-insert order so a group's synthetic parent row —
always staged before its children — is already resolved by the time a child
row looks up its parent. When `RecordTransactions` silently drops a row as a
fingerprint duplicate of an unrelated row (a vanishingly rare cross-source
coincidence), it is surfaced as a visible `unmapped` item rather than silently
counted as a success, since nothing else (source-map, split legs, payee
resolution) is linked for that row.

Slugs for the rows this promoter creates come from two places.
`accounts.slug` is `AccountSlugResolver`'s — the Ledger service that owns
that column's `unique(user_id, slug)` — so an account reached by import and
one reached by migration cannot disagree on how a name becomes a slug or on
what an unsluggable name falls back to. `categories.slug` has no such owner
and is derived here, through the shared
`Modules\Core\Public\Support\UniqueSlug`: `Str::slug()` with the literal
`item` standing in for a name that slugs to nothing, then the same numeric
suffix walk. Both paths only ever allocate a slug for a row being created;
promotion never re-derives one for a row the source map already resolves.

## Reconciliation: the 3-way merge

`CheckForUpdates` is the entry point for re-importing a newer export of an
already-confirmed source. For every already-mapped entity in the new export,
`ThreeWayMergeResolver` reads three values — the newly-parsed source value
(`S_new`), the baseline stored at the entity's last import
(`migration_import_baseline`, `B`), and the current live Beatrax value (`C`)
— and applies:

```
S_new == B                 -> skip (source unchanged, neither side touched)
S_new != B AND C == B      -> apply (source changed, Beatrax untouched since)
S_new != B AND C != B      -> conflict (BOTH sides diverged from baseline)
```

Budget-assignment reconciliation is the concretely-worked, fully-tested case;
category-name, account-name, transaction-description, and transaction-amount
reconciliation follow the identical algorithm against their own entity kind.
Money comparisons use `Brick\Money` value equality (currency-aware); a
`MoneyMismatchException` is treated as "not equal" rather than bubbling up,
since a currency change is itself a value change. Transaction-amount
reconciliation is restricted to non-split rows — a split parent's
`amount_minor` isn't the invariant `SaveTransactionSplit::save()` actually
enforces (leg sums against the parent's `settled_amount_minor`), so
reconciling it independently of its legs risks a silently inconsistent row.
Transaction date, category assignment, payee, and goal reconciliation remain
unimplemented — they carry materially higher blast radius (fingerprint
invariants, `GoalWriter` validation) than has been justified so far, so a
source-side change to those fields on an already-promoted entity currently
falls through unreconciled.

Every conflict is recorded via `CheckForUpdates::recordConflict()` as a
`migration_staging_unmapped_items` row (`item_type = 'conflict'`) carrying the
full local/source/baseline value triple (`ConflictValueCodec` handles the
stringify/parse boundary) with `resolution` left `NULL` — the decision is
deferred to whichever choice the user makes on the preview page
(`PreviewMigration::resolveConflict()` persists it onto the same row). The
row's plain-text `display_label`/`reason` columns are populated too (some
tests query them directly), but `PreviewSummaryBuilder` recomputes the actual
human label + resolution-aware copy from the structured columns for the UI —
the two plain-text columns are a legacy/debug mirror, not the source of truth.
`PreviewSummaryBuilder` resolves a human entity name for the label (e.g.
"Groceries · January 2026 budget" rather than the raw internal
"budget_assignment budgeted_minor") and formats money-shaped values via the
same `Money` formatter the rest of the app uses (e.g. "€ 300,00"), never a
raw minor-unit integer; a transaction's counterparty name/description is
decrypted for display via `SensitiveColumnCodec` (a documented no-op for a
plaintext user).
`ConfirmMigration` is the only place a resolution is actually applied: a
`'take_source'` resolution writes the source value via the same
`EntityChangeApplier::apply()` non-conflicting changes use (one writer per
entity kind, never two), and every conflict's baseline is then advanced to
whichever value ended up live — for `'keep_local'`/`NULL` this needs an
explicit `EntityChangeApplier::advanceBaseline()` call; for a take-source
budget assignment it already happened as a side effect of
`promoteBudgetAssignments()`'s own unconditional-apply-by-value write.
Budget assignments resolved anything other than `'take_source'` are threaded
through `PromoteStagingToDomain::promote()`'s explicit skip-list, since that
is the one entity kind `promote()` otherwise applies unconditionally on every
run; every other entity kind's own per-row resolve-gate already never
revisits an already-mapped row, so no separate skip-list is needed for them.

`EntityChangeApplier::apply()` handles every non-`budget_assignment` field
change by resolving the Beatrax entity id via `SourceMapWriter`, writing the
field, then advancing that entity's baseline snapshot to the newly-applied
value; `budget_assignment` is deliberately never routed through it, since
`PromoteStagingToDomain::promoteBudgetAssignments()`'s own unconditional path
already applies it — routing it through `apply()` too would double-apply.
`advanceBaseline()` pins a conflict's baseline to a specific value without
writing to the domain at all, uniformly across every entity type, including
`budget_assignment` (its persistent `migration_source_map` row is keyed by
the same composite `sourceExternalId` `ThreeWayMergeResolver` already uses).

`EntityChangeApplier::applyTransactionAmount()` recomputes a transaction's
stored `fingerprint` in the same update as its `amount_minor` change, since
that column is part of the SHA-256 fingerprint tuple and the
`transactions_fingerprint_uq` composite unique index. A genuine fingerprint
collision on that update returns `false` (no write performed) rather than
letting the raw `QueryException` bubble out; any other `QueryException` is
re-thrown rather than silently reclassified as a benign collision — this
project is SQLite-only, and SQLite's own error message lists the conflicting
column names rather than the index name, so the classifier matches on
`transactions.fingerprint`/`transactions.amount_minor` (or either index name,
for forward-compatibility with a driver that names the constraint instead).
`FingerprintComposer::compose()`'s hash tuple does not consume
`counterparty_name`/`counterparty_iban`/`description` bytes at all — only
`counterparty_normalized`, which `SensitiveFieldRegistry` lists as a
blind-index column rather than an AEAD-sealed one: for an enrolled user it
holds a keyed HMAC-SHA256 digest of the normalised name rather than the
name. The tuple hashes whatever that column holds, opaquely — a hash of a
digest — so recomputing the fingerprint here needs no decrypt step even for
an encrypted user, and lands on the same value a re-import of the same
logical row would derive for that same user.

`EntityChangeApplier::apply()` routes a non-amount field write through
`SensitiveColumnCodec::encryptAttrs()` before the update (mirroring
`TagTransaction`'s encrypt-before-write), so a reconciled
`transactions.description` never lands as plaintext in an at-rest-encrypted
column; the call is a no-op for non-sensitive fields and for a plaintext
user. The UNENCRYPTED field value is still what gets snapshotted into
`migration_import_baseline` — a knowingly-accepted plaintext exposure in a
table that is not itself in `SensitiveFieldRegistry`, since
`ThreeWayMergeResolver::reconcileTransactionDescriptions()`'s three-way
compare needs a plaintext baseline. A future hardening could encrypt
`baseline_value` and decrypt it at compare time (it is a stored compare
value, not a lookup key, so random-nonce ciphertext would work there); this
is deferred rather than done blind because it touches reconciliation
correctness paths directly.

`migration_runs`/`MigrationConfirmResult` carry no persisted "budget months"
count — the results page's 5th stat-grid column needs it, but confirm
deliberately does NOT truncate staging post-confirm (to avoid narrowing a
future design space), so `migration_staging_budget_assignments` is still
readable at results time; `MigrationResults` computes the final count with
the same DISTINCT-`period_start` formula `PreviewSummaryBuilder` uses.

## Wizard pages

`MigrationsIndex` (`/migrations`) lists the user's past runs; discarded runs
are excluded entirely, since neither row action ("Resume preview" would hit
`MigrationRunNotParsedException`; "Check for updates" requires a `'confirmed'`
status) is meaningful for one. A `'parsed'`/`'needs_attention'` row offers
"Resume preview"; a `'confirmed'` row offers "Check for updates"
(`/migrations/new?reconcile_of={run}`, the `CheckForUpdates` entry point).
`NewMigration` (`/migrations/new`) is the upload step; an optional
`?reconcile_of={run}` query parameter (read via injected `Request`, not a
route parameter) resolves against the user's own confirmed runs only,
silently falling back to a first-time-import mount for a foreign/wrong-status
run rather than erroring, since this route carries no per-entity id and must
stay reachable by any authenticated user. Its upload validation runs both
`extensions:zip` (checks the client-supplied filename extension) and
`mimes:zip` (sniffs the uploaded file's real content via `finfo` against
zip's registered MIME types) as defence-in-depth — a file renamed to
`whatever.zip` that isn't actually a ZIP is rejected here with a precise
message rather than only later via `ZipExtractor`'s `ZipArchive::open()`
failure. `submit()`'s `extract()`/`cleanup()` pair is wrapped so `cleanup()`
still runs and removes whatever `ZipExtractor` already tracked even when
`extract()` itself is what threw partway (disk full, permission error) —
safe to call when nothing was extracted, and safe to call twice.
`PreviewMigration` (`/migrations/{run}/preview`) is
the confirm/discard/resolve-conflict step; every conflict row exposes a
keep-local/take-source toggle that persists onto the same
`migration_staging_unmapped_items` row `CheckForUpdates` created — `render()`
re-reads the CURRENT resolution from `PreviewSummaryBuilder` on every render
so the toggle reflects real persisted state, never a client-side-only copy.
`confirm()` is safe to call uniformly for a first-time run ('parsed'
→ promotes + flips to 'confirmed'), a reconciliation run ('needs_attention'
→ `ConfirmMigration`'s `promote()` call re-visits an already-resolve-gated
batch, a safe no-op, and flips to 'confirmed' as a review acknowledgement),
or an already-confirmed run (short-circuits to the persisted counts).
Migration has no "name this account/category" naming flow analogous to
`Import`'s ICS/PayPal/unfamiliar-IBAN prompts — every migrated account gets a
deterministic synthetic pseudo-IBAN with no user input required, so there is
no equivalent precondition gating Confirm. Both `render()` and every action
(`confirm()`/`discard()`) run an explicit user-scoped `firstOrFail()` lookup,
independent of `PreviewSummaryBuilder`'s own second IDOR guard — either alone
is sufficient; both are present as defence-in-depth.
`MigrationResults` (`/migrations/{run}/results`) is the read-only summary.

The four wizard routes (`migrations.index`/`.new`/`.preview`/`.results`)
mirror `Modules\Import\Routes\web.php`'s shape: `Route::view` for the two
id-less pages, a closure rendering the page shell for the two run-scoped
pages with `->where('id', '[0-9]+')` on both. `/migrations/new` accepts an
optional `?reconcile_of={run}` query parameter (the entry point from the
index's "Check for updates" row action); `NewMigration::mount()` reads it via
injected `Request`, not a route parameter, so the route itself stays a plain
`Route::view`.

## Cross-user safety

Every wizard action and every reconciliation/promotion query is scoped by
`user_id`; a foreign or non-existent run resolves to a 404 via
`firstOrFail()`/`ModelNotFoundException`, never a 403 — consistent with this
codebase's ASVS V4 convention. `DiscardMigrationRun` refuses to discard an
already-confirmed run (would orphan the domain rows already promoted) and
`ConfirmMigration` refuses to confirm an already-discarded run (staging is
already truncated, so a confirm would silently flip status back with all-zero
counts) — the two exceptions guard symmetric ends of the same state machine.
`DiscardMigrationRun::sweepAbandonedForUser()` deletes never-confirmed runs
older than a fixed threshold for one user, cascade-wiping their staging via
the FK; every delete is scoped by both `id` AND `user_id` explicitly — never
a bulk unscoped truncate — so it can never touch another user's rows even
from a future scheduled hook that iterates every user.

## Encryption interaction

When a reconciled field lands on an at-rest-encrypted column
(`transactions.description`), `EntityChangeApplier::apply()` routes the write
through `SensitiveColumnCodec::encryptAttrs()` before the update, and
`ThreeWayMergeResolver::reconcileTransactionDescriptions()` decrypts the live
stored value before comparing it against the plaintext baseline snapshot —
comparing raw ciphertext against a plaintext baseline would otherwise register
a spurious conflict on every re-run. `reconcileCategories()`/`reconcileAccounts()`
compare a plain `name` column instead; `categories.name`/`accounts.name` are
NOT `SensitiveFieldRegistry`-listed (only `counterparties.display_name`/
`merchant_name`/`iban` are), so those two methods are correctly unaffected by
encryption and need no decrypt step. The baseline snapshot itself
(`migration_import_baseline.baseline_value`) is stored plaintext regardless —
a reviewed, accepted exposure, since the three-way compare needs a plaintext
baseline and this table is not authorization-sensitive on its own.
