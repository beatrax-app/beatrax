# `Import` — architecture

The `Import` module is the orchestrator that takes a user-supplied
file (or an OS-opened drop) and walks it through the
preview-then-confirm wizard into the canonical ledger. It owns the
`ImportPipeline` stage chain, the per-source `PaymentTypeHinter` and
`StartingBalanceDetector` registries (tag-discovered), the
merchant-alias surface, the institution-IBAN alias bridge, and the
post-commit dispatch boundary that wakes up `Chains`.

## What this module is for

The detailed cross-cutting design lives in the
[ingestion-pipeline architecture topic](../../architecture/ingestion-pipeline.md);
this page describes the module's surface. One source format's parent/child
rollup has its own page, because a row that funds a purchase is a movement and
folding it away counted the same euros twice —
[PayPal funding legs](paypal-funding-legs.md). The user uploads / drops a
file; the module previews it (no DB writes); the user confirms; the
pipeline persists through `Ledger`'s sole sanctioned writer; the chain
job dispatches.

What the module explicitly does NOT do:

- It never persists transactions itself. The pipeline ends at
  `RecordsTransactions` (Ledger's contract); this module never
  writes to `transactions`.
- It never reaches into another module's internals to consume a
  payment-type hint or starting-balance detector. The container
  tags (`import.payment_type_hinter`,
  `starting-balance.detector`) are how new hinters / detectors
  ship — append the FQN to a constant in the provider, add the
  class, the registry picks it up.
- It never re-resolves a previously-resolved counterparty IBAN
  without a documented reason. The `KnownCounterpartyIbanResolver`
  is the single sanctioned reader of the
  `known_counterparty_ibans` table.

## Module boundary

`Public/` exposes the cross-module surface:

- **Contracts/**
  - `RunsImports::runFromUpload($localPath, $sourceFormat, $user,
    $originalFilename, $formatHint)` — the preview phase entry point
    (no DB writes; caches the canonical rows for the confirm step),
    returning an `ImportPreviewResult`.
    `RunsImports::runFromRemoteFetch($sourceRows, $sourceFormat,
    $user, $idempotencyKey)` is the same phase fed by a connector's
    generator instead of a staged file.
    `RunsImports::runAndConfirm(...)` walks both phases in one call
    and is the single entrypoint the idempotency contract test
    drives, so a new adapter joins it without re-implementing the
    wizard's two-step dance.
  - `ConfirmsImports` is invokable — `ConfirmsImports::__invoke(
    $importRunId, $user, $dispatchChain = true)` — the confirm phase
    entry point (persists through Ledger; dispatches Chains). It is
    keyed on the **import run id**, not on a preview id.
  - `NamesAccounts` is invokable — `NamesAccounts::__invoke($iban,
    $userSuppliedName, $user, $statementCurrency)` returns the id of the Account row it
    created; the "name this unknown IBAN" hook used inline in the
    wizard.
  - `AppliesEnrichments` is invokable —
    `AppliesEnrichments::__invoke($enrichments, $user)` returns how
    many rows were actually enriched; re-imports that produce
    stronger `source_ref` values.
  - `CapturesImportForSync::capture($importRun, $user)` — the
    post-commit hook `Sync` implements. An implementation must not
    throw into the import: a device that could not capture has still
    imported.
  - `PaymentTypeHinter::hint($tx, $sourceFormat)` — per-source hinter
    contract; discovered through the `import.payment_type_hinter`
    tag.
  - `DetectsStartingBalance::supports($sourceFormat)` and
    `DetectsStartingBalance::detect($importRunIds, $user)` —
    per-source detector contract; discovered through the
    `starting-balance.detector` tag. A detector is handed ImportRun
    ids and filters internally to its own source format; it never
    re-reads the file.
  - `ResolvesKnownCounterpartyIban::resolveAccount($iban, $userId)` —
    the bridge between an institution IBAN (PayPal Luxembourg, ICS
    at ABN AMRO) and the user's synthetic-IBAN account.
- **Actions/**
  - `RunImport` (impl. `RunsImports`).
  - `ConfirmImport` (impl. `ConfirmsImports`).
  - `ApplyEnrichments` (impl. `AppliesEnrichments`).
  - `DiscardImport` — drops a run's cached preview.
  - `EnsurePaypalAccountAction` — the synthetic PayPal account the
    Onboarding connector step needs before it can stage a run.
  - `CreateMerchantAlias`, `MergeMerchantAliases`.
- **Pipeline/**
  - `NormalizeStage` — the one stage this module publishes, because
    `Receipts` normalizes a fetched-inbox row without going through
    `ImportPipeline`. `ResolvesCounterparties` lives in
    [`Counterparties`](../counterparties/architecture.md); the
    pipeline injects the contract. It derives no money of its own: the
    settled pair and the rate between them come from
    `Ledger::TransactionAmount`, so an adapter's own idea of either
    reaches no column. Both call sites of this stage are therefore the
    seam where a settled leg that disagrees in sign with its native one,
    or a negative `fx_rate_used`, stops being reachable.
- **Dto/** — `ImportPreviewResult`, `ImportConfirmResult`,
  `PreviewRowDto`, `PaymentTypeHint`, `StartingBalanceCandidate`,
  `PendingEnrichment`, `UnknownIban`, the three dispositions
  (`NewRowDisposition`, `DuplicateDisposition`,
  `EnrichedDisposition`) plus `FingerprintDisposition`, and the
  consolidated-preview batch/section pair.
- **Enums/** — `PaymentType`, `BankCsvFormatHint`,
  `ImportFailureReason`, `PreviewRowStatus`, `PreviewSectionStatus`.
  `SourceFormat` is **not** one of them; it belongs to `Ingestion`,
  the module that owns the adapters the value selects. Nor is
  `ImportRunStatus`, which belongs to `Ledger` along with the
  `import_runs` table it describes.
- **Events/**
  - `TransactionImported` — carries `(transaction, user)`. Dispatched
    by `Ledger`'s `RecordTransactions` once per row actually
    inserted, after that chunk commits, never for a duplicate or an
    enrichment. Consumed by `Anomaly`, `Receipts`, `Transfers` and
    `Search`.
- **Services/**
  - `AccountNamer` (impl. `NamesAccounts`).
  - `MerchantNameResolver` — six-step matcher (per-user exact →
    per-user generalised → community exact → community generalised →
    community regex → null) consumed by `Counterparties`. The three
    community tiers are gated on `Community`'s `useSharedList` opt-out.
  - `PatternGeneralizer` — produces the generalised pattern for
    a raw description string.
  - `AliasMatchPreviewQuery`,
    `BuildConsolidatedPreviewQuery`,
    `DetectStartingBalancesQuery`.

`Internal/` houses the implementation:

- **Internal/Pipeline/ImportPipeline** — orchestrates the stage
  chain.
- **Internal/Pipeline/PreviewCache** — JSON-only cache of preview
  rows, plus the per-run section summary the consolidated screen
  renders from.
- **Internal/Pipeline/Stages/** — `ParseStage`,
  `ClassifyTransactionType`, `PaymentTypeClassifierStage`,
  `FingerprintStage`. `NormalizeStage` sits in this module's
  `Public/Pipeline/` instead, because `Receipts` needs it without
  the rest of the chain. The remaining links are other modules'
  contracts injected here: `AppliesAutoCategory`
  (`Categorization`), `ResolvesCounterparties` (`Counterparties`)
  and `RecordsStatementSummary` (`Ledger`).
- **Internal/Parsers/** — per-source `PaymentTypeHinter`
  implementations only (`Csv/PositionalCsvPaymentTypeHinter`,
  `Banking/Camt053PaymentTypeHinter`,
  `Banking/Mt940PaymentTypeHinter`,
  `Ics/IcsPdfPaymentTypeHinter`,
  `Paypal/PaypalCsvPaymentTypeHinter`, plus the shared
  description-keyword base, the `DutchNarrativeHinter` subclass the
  three bank formats share, its one keyword table, and the fallback
  hinter). The file readers themselves are
  **not** here: they are `Ingestion`'s `SourceAdapter`
  implementations, reached through `SourceAdapterRegistry`. The
  directory kept its name from when this module owned both.
- **Internal/Detectors/** — per-source starting-balance
  detectors.
- **Internal/Services/KnownCounterpartyIbanResolver** — concrete
  `ResolvesKnownCounterpartyIban`. Single sanctioned reader of
  `known_counterparty_ibans`.
- **Internal/Services/AliasYamlExporter / AliasYamlImporter** —
  bulk-edit surface for `/settings/aliases`.
- **Internal/Listeners/HandleFileOpenedFromOs** — filters
  `Desktop::FileOpenedFromOs` by `.csv` extension; routes the
  path into the wizard.
- **Internal/Listeners/SeedDefaultKnownCounterpartyIbans** —
  `UserInstalled` listener that seeds the two default
  institution-IBAN aliases.

## Key services + events

- `ImportPipeline::preview($localPath, $sourceFormat, $accounts,
  $user, $importRunId, $formatHint = null)` — six parameters, not
  three: the stage chain resolves each row's own account through the
  injected `AccountResolver` and stamps the run id onto every
  canonical row, so both have to arrive with the file. Stages:
  parse → account-resolve → normalize → classify-transaction-type →
  payment-type → auto-category → counterparty-resolve → fingerprint.
  It returns an array, not a DTO — `RunImport` is what wraps it into
  an `ImportPreviewResult`. No DB writes beyond the statement
  metadata the run's own row carries; the rows are cached.
  `ImportPipeline::previewFromGenerator(...)` is the same chain fed
  by a connector's generator, and takes no format hint because a
  fetched feed has no CSV layout to disambiguate.
- `ConfirmImport::__invoke($importRunId, $user, $dispatchChain =
  true)` — an invokable action, keyed on the import run id
  rather than a preview id; replays cached rows through
  `RecordsTransactions` (which runs its own chunked transactions),
  then applies pending enrichments and flips the run to `confirmed`
  inside one further transaction. AFTER that commit:
  `CapturesImportForSync::capture($importRun, $user)` always runs,
  then — only under `$dispatchChain` —
  `UpsertsCardStatements::upsertForImportRun($importRunId,
  $user)` runs unconditionally, and only when the run inserted
  or enriched something does it write the
  `ChainResolutionRun` row and call
  `DispatchesChainResolution::dispatchForUser($userId)` followed
  by `DispatchesRecurringDetection::dispatchForUser($userId)`.
  The card-statement upsert sits outside that inner gate on purpose:
  an all-duplicate re-import still has to recover a deleted
  `card_statements` row. `$dispatchChain = false` is the
  fixture escape hatch that skips the upsert and both dispatches —
  the Sync capture runs regardless, because a fixture's rows still
  belong on the paired device.
  A re-confirm of an already-`confirmed` run short-circuits: it
  returns 0 inserted and reports the original inserts as
  duplicates, so `ImportConfirmResult` values must never be summed
  across attempts for one run.
- `KnownCounterpartyIbanResolver::resolveAccount($iban, $userId)` —
  reads `known_counterparty_ibans`; returns the user's matching
  `paypal` / `ics_card` Account, or null when no alias exists or the
  user owns no account of the alias's target kind. Lowest-id account
  wins on several matches.
- `PaymentTypeClassifierStage` — collects tagged
  `PaymentTypeHinter` instances; first match wins; the
  description-keyword fallback is intentionally LAST so the
  registry test's "fallback is last" invariant holds.
- `DetectStartingBalancesQuery` — collects tagged
  `DetectsStartingBalance` detectors; returns the first
  non-empty list. CAMT.053 first (canonical), MT940 second
  (legacy), ICS PDF third, PayPal CSV last (always declines).
- `MerchantNameResolver::resolve($rawDescription, $userId)` —
  six-step matcher consumed by
  `Counterparties`' `CounterpartyResolverService` and its
  `CounterpartyTriageQueue`. It takes a user **id**, not a User.
- `HandleFileOpenedFromOs` — extension filter; persists into the
  `Desktop` pending-intent store.

## Data flow

The end-to-end import:

```
User uploads file (or drops a .csv onto the app)
  ├─ drop path: Desktop FileOpenedFromOs → HandleFileOpenedFromOs
  │              → Desktop PendingFileIntent store
  │              → user logs in (if needed)
  │              → /desktop/file-staging → /imports/new
  └─ upload path: UploadWizard SFC

PreviewWizard
  → RunImport::runFromUpload($localPath, $sourceFormat, $user,
                             $originalFilename, $formatHint)
       → ImportPipeline::preview($localPath, $sourceFormat,
                                 $accounts, $user, $importRunId,
                                 $formatHint)
            → ParseStage (Ingestion SourceAdapter for the format)
            → AccountResolver (own-IBAN → account, or UnknownIban)
            → NormalizeStage (Import Public/Pipeline)
            → ClassifyTransactionType
            → PaymentTypeClassifierStage (per-source hinters)
            → AppliesAutoCategory (from Categorization)
            → ResolvesCounterparties (from Counterparties)
            → FingerprintStage
       → PreviewCache::put keyed by IMPORT RUN id (30-minute TTL)
  → user reviews preview
  → ConfirmImport::__invoke($importRunId, $user)
       → RecordsTransactions::__invoke($cachedRows, $user,
                                        captureForSync: false)
            → BEGIN TX (one per CHUNK_SIZE rows, not one per run)
                 → INSERT ON CONFLICT(fingerprint) per row
            → COMMIT
            → per inserted row: dispatch TransactionImported
       → BEGIN TX
            → AppliesEnrichments::__invoke (pending source_ref
                                             strengthens)
            → import_runs row updated to `confirmed`
       → COMMIT
       → CapturesImportForSync::capture (run + accounts, parents first)
       → UpsertsCardStatements::upsertForImportRun (Chains contract
                                                    — Pre-Chain step)
       → if inserted or enriched:
            → DispatchesChainResolution::dispatchForUser (Chains)
            → DispatchesRecurringDetection::dispatchForUser (Recurring)
```

`DefaultKnownCounterpartyIbansSeeder` seeds two real-world institution
IBANs per user: `LU89751000135104200E` (PayPal SARL et Cie SCA,
Luxembourg → the user's `paypal` account) and `NL08ABNA0526650664`
(International Card Services BV at ABN AMRO → the user's `ics_card`
account). It uses `firstOrCreate` (not `updateOrCreate`) keyed on
`(user_id, real_iban)` so a re-run produces zero new rows AND preserves
any custom `notes` the user edited. It also calls `withoutGlobalScopes()`
deliberately: without it, `BelongsToUser`'s `UserScope` adds a second
`where('user_id', auth()->id())` on top of the explicit `user_id`
lookup whenever the seeder runs for a DIFFERENT user than the
authenticated one (e.g. an admin endpoint or a `tinker --as=foo`
session) — the AND of both filters returns zero rows, `firstOrCreate`
attempts an INSERT, and that INSERT violates the per-user UNIQUE
constraint on `(user_id, real_iban)`. Dropping the global scope at
query time keeps the seeder context-independent.

The institution-IBAN bridge seed:

```
UserInstalled
  → SeedDefaultKnownCounterpartyIbans
       → INSERT (PayPal Luxembourg → paypal kind)
       → INSERT (ICS at ABN AMRO → ics_card kind)
            both idempotent on UNIQUE(user_id, real_iban)
```

## Consolidated preview (multi-run commit)

`BuildConsolidatedPreviewQuery` drives the FirstImportStep's "commit
everything" surface, which lets a user review every ImportRun a
connector step stashed (across multiple source formats) before writing
any of it. `build()` enforces three boundary rules before any cache read:

1. **User-scope** — only ImportRuns owned by the calling user survive. A
   tampered `wizard_progress` payload pointing at a sibling user's
   ImportRun never reaches the cache lookup.
2. **Stale window** (`STALE_WINDOW_DAYS = 14`) — runs older than the
   cutoff are dropped, so a forgotten browser tab never replays weeks-old
   data. The underlying preview cache TTL (30 minutes) already empties
   the row set well before this window matters; the query-layer filter
   keeps the contract explicit regardless.
3. **Already-confirmed** — runs with `status = 'confirmed'` are dropped,
   so a refresh/back-button navigation cannot resurface an
   already-committed run on the review screen.

Surviving ids are grouped by `source_format` (first-appearance order,
deterministic for a given input) into a `ConsolidatedPreviewSection` per
format: `totalRows` counts NEW + ENRICHED dispositions (both write when
committed), a separate duplicate count feeds the batch-level
`alreadyImportedCount` reassurance line, and `sampleRows` caps at
`SAMPLE_ROW_LIMIT` (5, overridable per section). A section's `status` is
`error` when any contributing run's cache entry is missing (`null` —
preview window elapsed) or a contributing run reported a
`fileFailureReason`, `empty` when every contributing run cached zero
rows and none of them failed (legitimate: every row was already in the
ledger), otherwise `ready`.

`sampleRows` excludes ERROR rows. The sample stands for what committing
would write and a failed row writes nothing, so among the others in a
table with no status column it read as one more transaction. The
section's `error` string is the file-level detail where there is one,
otherwise the translated `ImportFailureReason` of the first failed row
— never a caught exception's own message, which names internal classes
and the reader's user id.

None of that is computed by walking the rows. `build()` runs inside a
Livewire `render()`, so it repeats on every round-trip of the step; a
section reads `PreviewCache::sectionSummary()`, which answers from a
small per-run entry holding the four counts, the file failure, the first
failed row's reason and the five sample rows. The run's row set is read
back only when a reader expands a section past the stored sample
(`loadMoreRows()` grows the override by 25 a click) or when a preview was
cached without a summary beside it. Three runs of 2,000 rows cost 794 ms
a render through the rows and 0.9 ms through the summaries.

## What the results screen can still say

`/imports/{id}/results` renders after `ConfirmImport` has dropped the
preview cache, so everything it can name about what was skipped has to
be copied onto the `import_runs` row before that happens.
`import_runs.row_issues` is that copy: a JSON list of
`{kind, row, reason, detail}` written inside the same transaction that
flips the run to `confirmed`.

- `kind` is an `ImportIssueKind` — `file_error`, `row_error` or
  `duplicate`.
- `row` is a source row index, rendered one-based. On a `row_error` or
  a `duplicate` it is the row itself. On a `file_error` it is the row
  the read stopped at — the first one the reader never got — and null
  when it stopped before producing any. It is counted from the rows
  the pipeline produced, not read out of the exception message, so it
  survives a message that cannot be shown.
- `reason` is an `ImportFailureReason` backing value, translated at
  render time so the list reads in the reader's language whatever
  language the import ran in.
- `detail` is what the failure said for itself past the reason,
  carried only from an exception implementing
  `Core\Public\Support\MessageNamesNoUserData`. Four exceptions
  declare it today, all of them adapter-level, so most row failures
  carry a reason and no detail — their messages quote a cell.

The detail is rendered **beside** the reason, never instead of it. The
first version substituted, and a `file_error` whose exception was not
declared safe explained itself with itself: "The file could not be read
in full: This file could not be read." The row index is what makes the
line specific when there is no detail to carry.

A failure that declares no safe message of its own falls back to a
sentence naming the exception's short class name — and there are two of
those sentences, `errors.row_unreadable_detail` and
`errors.file_unreadable_detail`. They were one for a while, and a PDF
refused before a single row was read told the reader "the app could not
read this row", under a heading saying the whole file could not be read.
`ImportPipeline::fileDetail()` and `::safeDetail()` are the two call
sites; nothing else picks between them.

### The three PDF refusals

`ImportFailureReason` keeps `PdfReaderUnavailable`, `PdfHasNoTextLayer`
and `PdfPasswordProtected` apart because they are three different pieces
of news, and the reader can act on each in a different way — save an
unprotected copy, fetch the real statement instead of a scan, or (for the
third, which is a packaging fault) nothing at all. Collapsed onto one,
the phone's answer to every PDF was "install pdftotext", which was not
installable there and, once `PdfTextLayoutReader` existed, not true
either. See
[../ingestion/ics-pdf-text-extraction.md](../ingestion/ics-pdf-text-extraction.md#what-each-refusal-means).

What is deliberately **not** in the column: counterparty names,
descriptions, and any caught exception message that has not declared
itself free of user data. The list is diagnostic, not a second copy of
the statement.

Both counts are capped at `MAX_STORED_ISSUES_PER_KIND` (50) so an
all-duplicate re-import of a year of statements cannot write thousands
of entries into one column; past the cap the screen falls back to the
run's own counters and says how many are not listed.

The column is deliberately absent from the Sync merge rules. The
counters replicate to a paired device; the diagnostic list stays on the
machine that read the file, so no parse text has to travel the op log.

Before this, expanding "Show errors (1)" produced one sentence defining
the word "error" — a count in the control promising a list, and a
glossary entry inside it. The help sentence is still there as a
preamble; the list is the content.

## Chain resolution progress on the results page

`ConfirmImport` reserves a `pending` `chain_resolution_runs` row inside
its post-commit block and then the wizard redirects to
`/imports/{id}/results`, so the results screen is the one the reader is
actually on while the resolver works. `ImportResults::render()` reads
the newest `chain_resolution_runs` row for the signed-in user and
passes its `JobRunStatus` to the view; the view draws a polled progress
section for `pending` and `running`, and nothing at all otherwise. The
poll target is the component itself — a refresh, not an action method —
so nothing about the surface is reachable from the wire.

The status is derived in `render()` rather than held on a public
property, and that is the whole point of where this now lives. The
previous version sat on `PreviewWizard`, behind an `@if` on a property
only its own polling action ever set: nothing set it before the first
render, so the section never drew, so the poll it carried never
started. Every state it could describe was unreachable, on a screen the
confirm had already navigated away from.

The row is looked up by exact `user_id`. Never a
`failed_jobs.payload LIKE '%userId:N%'` match — an id-prefix substring
matches every user whose id begins with this one's digits, so user 1
would read user 12's run. `TheProgressPollLivedOnAScreenTheReaderHadLeftTest`
greps the component to keep the pattern out and pins the cross-user
case behind it.

A **failed** resolution draws nothing here. The dashboard already
banners one across the whole app
(`Shell\Internal\Http\Livewire\Dashboard`), and the stored
`last_error` is written as `"<JobClass>: <first line of the message>"`
— a developer's sentence, which for the crypto layer names an internal
class and the reader's own user id. One banner, on the surface that
already had it, and no job error on this page at all.

## Merchant aliases

`MerchantNameResolver::resolve()` walks a six-step precedence stack
(first hit wins), consulted at preview/render time only — it never
mutates `transactions.description`:

1. The user's exact `merchant_aliases.pattern` match — the raw
   description as first seen.
2. The user's `generalized_pattern` **whole-token** match, scanned over
   the user's first 500 aliases by id. The exact tier above is not
   capped: an alias past the cap still renames its own raw description,
   it only stops widening to other rows.
3. The community corpus's exact `pattern` match (`user_id IS NULL` rows).
4. The community corpus's generalized-pattern whole-token match.
5. The community corpus's `regex:` rows.
6. `null` — the caller renders the raw, italic-muted description.

Exact-then-generalized ordering is deliberate: if both an exact alias and
a broader generalized alias match the same row, the exact one wins, so a
broad rename can never silently override a more specific one.

Tiers 3 to 5 are the whole of what "Use the shared merchant list" opts out
of, and this resolver is that switch's only consumer: with it off they are
skipped and `resolve()` answers from the reader's own aliases or not at
all. Tiers 1 and 2 are never gated — a reader's own aliases are their own
data, not community data. The gate is read per call rather than memoised
beside the country: an opt-out answered from a cache that no sync write
and no second process can drop is an opt-out that keeps sharing after the
reader switched it off.

Both alias tiers read **one memoised list per reader**, loaded and sorted
once and held for the life of the container — the same shape
`CommunityCorpusQuery` holds its corpus tiers in, and the same reason:
`resolve()` is called once per transaction, so a whole 40,000-row backfill
paid two `merchant_aliases` reads and a sort per row. `MerchantNameResolver`
is a **singleton**, so the memo is keyed by user id; a container-wide memo
would answer one household member out of another's aliases.
`CreateMerchantAlias` calls `MerchantNameResolver::forget($userId)` after
it writes, and any other writer into `merchant_aliases` that runs in a
process which then resolves has to do the same, or the screen renders the
list as it stood before the write.

Both generalized tiers run `CorpusPatternMatcher::containsToken()` — never
SQL `LIKE`, mirroring the Categorization RuleEvaluator's defence against
user-authored patterns entering a SQL string, and never a bare `mb_strpos`,
because merchant tokens are short enough that an unanchored search matches
inside a longer word routinely (`OBI` inside "mobiel", `RDW` inside
"Nordwind"). The user tier is consulted **first**, so leaving it unanchored
meant the corpus was never even asked.

`AliasMatchPreviewQuery` — the "N transactions match" line in the alias
editor — runs the **same** matcher, because its whole job is to predict what
tier 2 will do. Any change to one is a change to both, or the preview starts
promising matches the alias will never make.

`PatternGeneralizer::generalize()` produces the `generalized_pattern`
fuzzy-match target by tokenizing on whitespace and dropping tokens that
classify as PIN-tail (`*NNNN`, final token only), terminal id (bare
4-7-digit run, optional `T`/`#` prefix), SEPA/CAMT noise prefix
(`EREF:`/`KENMERK:`/`BKTXCD:`/`MARF:`/`REF:`), amount (`12.50`, `-12,99`)
or date fragment, then lower-cases and re-joins the survivors. The output
is always a literal substring target, never a regex, so it stays safe to
match from PHP without touching SQL.

`AliasYamlImporter`/`AliasYamlExporter` round-trip the per-user alias
table through a YAML document shaped like the bundled community-corpus
file. Parsing runs with `Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE` — the
single mitigation against the YAML-deserialisation-to-RCE class of
attack (ASVS V10) — and validates the top-level `entries` list shape
before touching the database. A file it refuses raises
`AliasFileRejectedException`, which carries an `AliasFileRejection` case rather
than a sentence: the message on the exception is English machine text bound for
the log, and the settings page renders the translated
`import::aliases.errors.*` line the case names — see
[an exception message rendered as if it were copy](../../conventions/invariants-from-shipped-failures.md#an-exception-message-rendered-as-if-it-were-copy). `diff()` classifies each parsed entry as
`new` / `unchanged` / `conflicts` (same pattern, different
`friendly_name` or `generalized_pattern`); `apply()` commits inside one
transaction, defaulting any unrecognised conflict-resolution action to
`'keep'` so a forged action key can never silently replace an existing
alias.

`LongestCommonPrefix::compute()` powers the Settings → Aliases
bulk-merge dialog's pattern prefill: it throws on fewer than two inputs,
returns `''` on any empty input, and refuses a prefix shorter than 4
characters (a 1-3 character prefix would over-match thousands of
unrelated rows) — the dialog then forces the user to type a pattern by
hand. `MergeMerchantAliases` keeps the lowest-id row as the survivor,
appends the absorbed rows' `(pattern, generalized_pattern,
friendly_name)` into the survivor's `merged_from` JSON provenance column,
and deletes the absorbed rows — both writes inside one transaction so a
failure mid-way leaves the table untouched.

`CreateMerchantAlias` is the sole permissible write path from the UI /
Livewire / queue worker into `merchant_aliases`: `updateOrCreate` keyed
on `(user_id, pattern)` means calling it twice for the same user + raw
description updates the existing row rather than duplicating it. When
the caller passes a null `generalizedPattern`, the action derives one
via `PatternGeneralizer` — the rename popover instead passes the
user-edited value so a manual override wins over the heuristic.

`AccountNamer` validates a user-supplied account name (1..80 characters,
non-empty `Str::slug()` body) and derives the Account's slug as
`slug($name) + '-' + last8(iban)`; the shape check on the IBAN itself is
structural only (`[A-Z0-9]{15,34}`, no Mod-97 checksum) since a future
MT940/CAMT ingestion path can carry already-truncated counterparty IBANs
that a user still wants to name.

`AliasMatchPreviewQuery` powers the "test against my transactions"
probe: a debounced Settings → Aliases live input walks the user's most
recent 500 transactions (bounded by design — a full-history scan on
every keystroke would saturate SQLite WAL contention) and returns the
total match count plus the first five rows. Matching runs in PHP via
`mb_strpos`/`mb_strtolower`, never SQL `LIKE`, mirroring the
Categorization `RuleEvaluator` defence so a user-authored pattern never
enters the SQL string. Patterns under three characters are rejected
with an explanatory empty result, which the query reads out of
`import::aliases.errors.too_short` — the same key the settings page substitutes
when it short-circuits on the same floor, so a second caller cannot surface a
different sentence.

`AccountNamer`'s slug derivation is `slug($name) + '-' + last8(iban)` —
the last 8 characters (not 4) dramatically lower the chance of two
distinct IBANs colliding on the same slug, and the per-user
`UNIQUE(user_id, slug)` + `UNIQUE(user_id, iban)` pair guarantees the
same IBAN never lands twice.

## Payment-type hinters

Each per-source `PaymentTypeHinter` (`Modules/Import/Internal/Parsers/`)
declines with `null` for rows outside its own `source_format`, then
inspects the row for a recognisable signal:

- **PositionalCsvPaymentTypeHinter** / **Mt940PaymentTypeHinter** /
  **Camt053PaymentTypeHinter** all extend `DutchNarrativeHinter`, which
  scans `description` and `counterpartyName` together for the Dutch
  lexemes ASN embeds in every row (`Betaalautomaat`/`Geldautomaat` → Pin,
  `Incasso`/`Automatische incasso` → DirectDebit, `iDEAL`/`Online
  betaling` → Online, `Overboeking`/`SEPA Credit Transfer` → Transfer).
  Matching is case-insensitive and bounded by `\p{L}` on both sides, so
  `Idealo` is not `iDEAL` while `Betaalautomaat12:34` still is. The one
  table those lexemes come from is `DutchNarrativeKeywords`; the MT940
  `:86:` narrative and the CAMT entry narrative carry the same lexemes as
  the CSV description, which is why one table serves all three.
- **Camt053PaymentTypeHinter** additionally keys off the authoritative
  ISO20022 `BkTxCd` (domain|family|subFamily) tuple first —
  `PMNT|CCRD|POSD`/`POSC` → Pin, `PMNT|IDDT|ESDD`/`PMDD`/`RCDT|ESDD` →
  DirectDebit, `PMNT|ICDT|ESCT`/`RCDT|ESCT` → Transfer — falling back to
  the inherited narrative scan when the tuple is missing or unrecognised.
- **IcsPdfPaymentTypeHinter** scans for Mijn ICS's small set of
  distinctive Dutch CAPS tokens (`KOSTEN KASOPNAME` → Fee, `GELDMAAT` →
  Pin, `IDEAL BETALING` → Online, `INCASSO` → DirectDebit) — the ICS PDF
  layout carries no per-row transaction-type column.
- **PaypalCsvPaymentTypeHinter** keys off the first event's `type` in
  `rawPayload['events']` (case-insensitive), mapping observed NL event
  types (`Express Checkout-betaling` → Online, `Algemene
  valutaomrekening` → Fee) plus a forward-compatibility set of EN
  literals PayPal sometimes leaves un-localised (`Payment Sent` →
  Online, `Refund` → Refund, `Fee` → Fee, `User Initiated Withdrawal` →
  Transfer). An unmapped event type is by-design forward-compatible
  noise, not a classification miss — the adapter already raises a typed
  exception for genuinely-unmappable events at parse time.
- **DescriptionKeywordFallbackHinter** is the universal last resort
  (confidence fixed at 40, below every source-specific hinter): it
  inspects every row regardless of `source_format` and MUST be
  registered LAST in `ImportServiceProvider::PAYMENT_TYPE_HINTER_FQNS`
  so the registry test's "fallback is last" invariant holds.

## Starting-balance detection

`DetectStartingBalancesQuery::collect()` fans out to every tagged
`DetectsStartingBalance` detector (CAMT.053, MT940, ICS PDF, PayPal CSV —
the last always declines with an empty list, since PayPal's `Saldo`
column resets after every funding sweep) and applies a per-account
conflict-resolution chain: earliest `openingBalanceDate` wins; on a date
tie, canonical CAMT.053 (`<OpngBal>` element) is preferred over MT940
(sometimes a re-computed running total); if two candidates still tie on
both date and source, both are returned so the wizard renders a
conflict-resolution card instead of guessing.

## Preview wizard: inline account naming

`PreviewWizard` (step 2) renders the preview table and, before the user
can confirm, resolves four naming branches sharing one Blade section —
all scoped to the authenticated `CurrentUser` so a forged `importRunId`
from another user 404s via `firstOrFail` rather than exposing that
user's import run:

- **IBAN naming** — an ASN source row references an IBAN the user
  hasn't linked to an Account yet. The pipeline surfaces those via
  `$preview->accountsToName`; `nameAccount()` re-validates the supplied
  IBAN against that same list (defence-in-depth against a crafted wire
  request naming an arbitrary IBAN) before delegating to `AccountNamer`.
  The matched entry is also what says which currency the new account is
  denominated in —
  [an account is denominated by its statement](an-account-is-denominated-by-its-statement.md).
- **ICS card naming** — raised when the run's `source_format` is
  `'ics-pdf'` OR the preview names `'ICS-CARD'`, and closed only once an
  Account carries that exact literal. `saveIcsAccountName()` inserts the
  synthetic-IBAN Account and re-runs the importer so the preview catches
  up.
- **PayPal naming** — same shape, `source_format = 'paypal-csv'` or the
  preview naming `'PAYPAL'`, minting `kind = 'paypal'`.
- **Google Play naming** — `saveGooglePlayAccountName()` delegates to
  `EnsureGooglePlayAccountAction`, which mints the `kind='google_play'`
  Account on the synthetic IBAN `'GOOGLE-PLAY'`. This one has no format
  arm at all: Google Play issues receipts and no statement export, so
  its rows only ever arrive over the `eml`/`mbox` transports — and those
  same transports carry PayPal and ICS receipts, so the format alone
  cannot say whose account the run needs. `needsGooglePlayAccountName()`
  therefore asks only whether `$preview->accountsToName` holds the
  literal.

Without that branch a Google Play receipt could not reach the ledger on
either path. Nothing in the app minted the account, the generic namer
answers "IBAN must be 15..34 uppercase alphanumeric characters" for the
literal, and `Receipts`' inbox job returns early when the synthetic IBAN
resolves to no Account — while still stamping the audit row `parsed`, so
the receipt read as processed with an empty ledger behind it.

All four branches take the account's denomination from the file rather
than from the reader's reporting currency, `OwnAccountPrompt::statementCurrency()`
answering for the three synthetic ones. Stamping `BaseCurrency::code()` opened a
229-row euro account labelled in yen —
[an account is denominated by its statement](an-account-is-denominated-by-its-statement.md#one-file-many-currencies).

The synthetic-account branches share `AccountNamer::validateName()`
for the 1..80-character bound + slug-body guard, but can't use
`NamesAccounts` end-to-end because a synthetic IBAN doesn't satisfy the
structural guard `AccountNamer` enforces for real IBANs. The literals
themselves are spelled once, in `Ingestion`'s `SyntheticIban` enum, and
`OwnAccountPrompt`, the two `Ensure…AccountAction`s and the three
`Receipts` matchers all read them from there.

All three synthetic prompts are suppressed when the preview already
carries a `fileFailureReason` and nothing importable came out of it.
Anchoring them on `source_format` alone meant they fired for a file the
parser had already given up on, and they sit ABOVE the file-failure card
in the wizard's one `@if`/`@elseif` chain — so an ICS PDF this install
could not read asked "Name your ICS card account" instead of saying why,
and the reader ended up with a permanent, empty card account for an
import that never had a chance. The account is a durable object once
made: nothing in the app deletes a ledger Account, the prompt closes for
good the moment the synthetic IBAN is claimed, and the copy tells the
reader the name is "so it shows up consistently across the app". So the
fix is not to roll it back afterwards — it is not to ask for it. The
same guard runs on all three `save…AccountName()` write paths, because a
submit already in flight when the failure arrived would otherwise still
land the account.

The row count is what makes the guard safe. A file that stopped partway
still hands back the rows it read, and those rows need the account, so
only "failed AND nothing importable" suppresses the prompt. The generic
unknown-IBAN branch needs no guard at all: its allow-list is
`$preview->accountsToName`, which is populated by rows, so a file that
read nothing offers no IBAN to name in the first place — and when it IS
populated, naming is exactly what unblocks those rows.

That generic branch is where an account carrying no IBAN of its own lands when
no bespoke prompt claims it — in practice a preset's `REVOLUT` or `N26`
placeholder, for which no bespoke prompt exists. So the branch asks
`Iban::isIban()` which of the two captions the entry gets, and renders
`StandInAccountName` in place of the identifier — the reader is being asked to
name an account and a stand-in is not something they can act on. Passing one to
`Iban::grouped()` drew `REVO LUT` on a real Revolut import:
[a stand-in for an IBAN, drawn as one](../../conventions/invariants-from-shipped-failures.md#a-stand-in-for-an-iban-drawn-as-one).

A synthetic sentinel reaching that branch is a different matter, because the
branch's Save cannot answer for one: `AccountNamer` refuses `PAYPAL` and
`ICS-CARD` on the structural guard, and widening it would mint a wallet as
`kind = bank`. So the sentinel must not arrive there at all — which is what the
list arm below is for. The one state that still routes one through is a preview
cached before something else minted the account on the same literal, where the
prompt is stale rather than unanswerable.

`OwnAccountPrompt` owns all three questions —
`needsIcsAccountName()` / `needsPaypalAccountName()` /
`needsGooglePlayAccountName()` — and asks all three through one private
predicate taking two witnesses. The `source_format` arm is what a
statement export declares, and keeps a future synthetic-IBAN drift
(e.g. `'ICS-CARD-PRIMARY'`) raising the prompt even though the preview
would then name a literal nobody recognises. The unknown-IBAN arm is
what a receipt drop declares, whose `source_format` names only the
transport it shares with the other two providers: without it a PayPal or
ICS receipt dropped as `.eml` fell through to the generic namer, and the
reader reached a prompt whose Save button could not mint the account.
Google Play passes no format, because it has none. Either witness raises
the prompt; only the literal being claimed closes it. It holds
`ICS_OWN_IBAN`, the literal `IcsPdfAdapter` emits, and
`hasNothingToName()`, the same guard the save actions re-ask on the
write side. `CurrentUser` arrives per
call rather than through its constructor, so the container may hand the
prompt out as a singleton without freezing a user into it.

Every one of those questions reads the run's owner first. The preview
cache key is not user-scoped, so before `ownedRun()` existed the class
answered from whatever run id it was handed: `statementCurrency()` would
report the denomination of somebody else's statement, and
`hasNothingToName()` — then `previewReadNothing()` — would clear the
write guard on a run its caller had never opened. `PreviewWizard` is the
only production caller and does assert ownership at mount, but a
guarantee that lives in the caller is a guarantee the next caller has to
be told about. `hasNothingToName()` is therefore fail-closed: a run that
is not the reader's has nothing for them to name, so the three
`save…AccountName()` paths return rather than mint an account off it.
Both checks
use the raw query builder (via injected `DatabaseManager`) rather than
the Eloquent Builder to keep phpstan-strict-rules'
`staticMethod.dynamicCall` rule quiet — the same convention the
dashboard queries under `Modules/Ledger/Public/Services/` follow.

The locked Blade copy for the two naming prompts (source of truth lives
in `preview-wizard.blade.php`):

- ICS: Heading "Name your ICS card account.", helper "This is the first
  time you've imported ICS data. Give this card a name so it shows up
  consistently across the app.", input "Account name" / placeholder
  "e.g. ICS card", button "Save name".
- PayPal: Heading "Name your PayPal account.", helper "This is the first
  time you've imported PayPal data. Give this wallet a name so it shows
  up consistently across the app.", input "Account name" / placeholder
  "e.g. PayPal", button "Save name".
- Google Play: Heading "Name your Google Play account.", helper "This is
  the first time you've imported a Google Play receipt. Give this
  account a name so it shows up consistently across the app.", input
  "Account name" / placeholder "e.g. Google Play", button "Save name".

## An email drop is not an empty statement

An `.eml` or `.mbox` upload does not go through an Ingestion adapter.
`ParseStage` hands the bytes to `RecordReceipt` (Receipts), which decodes
the headers, files the message under `file_imports`, stores the raw bytes
through `FileDropEmlBlobStore`, and asks the matchers what it is. Only a
message a matcher reads as a payment comes back out as a
`SourceTransactionDto`; a message that is saved but yields no payment
yields no source row at all.

That is a legitimate and common outcome — a statement-ready notice, a
sign-in alert, a receipt from a sender no matcher covers — and read as a
row count it is indistinguishable from a file that would not parse. The
preview screen therefore has a second witness beside the rows:

- `RecordReceipt` takes an optional `ReceiptCaptureLog` and records one
  `CapturedReceipt` per message — sender, subject, the message's own
  `Date`, the match outcome, and the matcher that answered.
- `ImportPipeline::preview` creates the log, threads it through
  `ParseStage::run`, and hands it to `PreviewWriter::finish`, which puts
  it on the `PreviewHead` beside the sample rows. It is capped at
  `ReceiptCaptureLog::MAX_KEPT` with the total counted whole, so a
  mailbox archive cannot grow the cached head without bound.
- `PreviewWizard`'s Blade reads `ImportPreviewResult::capturedAReceipt()`
  and draws each capture with `ReceiptCaptureState::of()`.

`ReceiptCaptureState` splits what the stored status collapses.
`file_imports.status` records `unmatched` both for a sender no matcher
claims and for a sender one of them claims but whose wording it could not
read a payment out of; only the answering `matcher_key` tells them apart,
and only the second is worth reporting as a bug. The four states are
`Read`, `NotAPayment` (a matcher skipped it deliberately), `Unreadable`
and `UnknownSender`.

The captures do **not** decide the failure copy on their own.
`RecordReceipt` writes its audit row for bytes that are not a message at
all, so `CapturedReceipt::identified()` — a sender address or a subject
came out of the file — is what suppresses "This file could not be read".
A `.eml` carrying random bytes still says so.

Those bytes no longer reach `RecordReceipt`. `ParseStage`'s receipt arm asks
`ReceiptFileShape::of()` what the file is before reading it, and refuses a file
that is not the declared transport. The panel's opening claim — "every message
has been saved" — is a completeness claim over whatever the arm iterated, and an
archive read as a single message iterates exactly one of its messages. Three
mismatches are now named rather than parsed: an archive declared as a message,
a message declared as an archive, and a file that is neither.

The panel's second sentence — "Nothing here became a transaction, so
nothing was added to your ledger" — has a condition of its own. A zero
importable-row count is also what the screen shows while it is asking for
a synthetic account's name, because every row the receipt produced is
held back as `UnknownAccount` until the account exists. That is the
first-run path of every receipt provider, so the sentence was printed for
each of them once, above the capture saying confirming would add the
payment and above the form asking for the name that was holding it. The
Blade therefore splits the two readings: `$nothingImportableYet` is what
the header subtitle and the failure copy key on, and `$nothingImported`
is that count with every open naming question — the three synthetic
prompts and `accountsToName` — subtracted from it.

Nothing ties a `file_imports` row to an `import_runs` row, which is why
the log is threaded through the call rather than queried back afterwards.
It is also why there is no durable surface listing captured receipts: the
preview is the only place a reader is told about one, and it expires with
the preview cache.

## Upload wizard

Step 1 of the wizard. The user picks an import TYPE — the shape of the
file they are holding (`ImportType`: csv / camt053 / mt940 / pdf /
email) — and then a format under that type, and uploads a statement
file; on submit the file is staged via Livewire's temporary upload
directory, the importer runs the preview phase (copying the upload to a
stable app-owned path on the way through), and the user is redirected to
`/imports/{id}/preview`. The first select names no institution: which
banks are covered is answered on the website, and a bank's own name
reaches the second select as `CsvPreset`/`PositionalCsvPreset` data via
`CsvPresetRegistry`, never as copy written into the component.

Two cascading selects drive the page: changing `$importType` rebuilds
the Format select via `availableFormats()` and resets `$sourceFormat` to
the new type's first valid leaf (`updatedImportType()`) — otherwise a
defensive `in:` validator would still accept a stale composite while
the picker visually disagreed with the submitted value. That reset opens the
email type on `eml`, and an archive read as a single message keeps only its
first message, so `matchFormatToFile()` runs on every file and import-type
change: within the email pair it takes the format from the file's own bytes
(`ReceiptFileShape`) and writes `$formatNotice`, which the Blade prints under
the select inside its `aria-live` region. The switch is never silent, and it is
confined to that pair — a leaf belonging to another import type is left alone so
`importTypeFormatRule()` still refuses it, and no CSV preset is inferable from a
file that names no bank. The leaf
`sourceFormat` value is the wire format `HeaderSniffer`,
`SourceAdapterRegistry`, and the per-source-format adapters dispatch on;
the import-type field exists only to drive the picker UX. `rules()`'s
`importTypeFormatRule()` closure additionally enforces that the
type/sourceFormat pair is a meaningful cross-product (e.g.
`importType='email'` + `sourceFormat='asn-csv'` fails here rather than
downstream at `ParseStage`). The file-size cap varies by format (10 MB
default — matches the typical maximum bank CSV statement export and is
well above a Mijn ICS monthly PDF's ~100 KB — 1 MB for `.mbox`, 20 KB
for `.eml`).

A parse-time failure from `runFromUpload()` (sniff mismatch, unsupported
PayPal language, or any other Throwable escaping before the pipeline's
own per-row try/catch) surfaces a stable, human-readable `uploadError`
message with the full stack trace logged via the injected
`LoggerInterface` — otherwise the Livewire generic-error toast gives no
actionable detail and no log line surfaces the cause.

## Rename counterparty popover

The italic raw-description span in a preview row dispatches
`rename-counterparty:open` (raw description + row index);
`RenameCounterpartyPopover` — mounted once at the bottom of the upload
wizard / preview wizard Blade — opens a Flux modal anchored to that row.
Because the modal is identity-less (only one rename is ever in progress
at a time), a single component instance serves every row. On save it
persists a `merchant_aliases` row and, optionally, a
`categorization_rules` row, then dispatches `rename-counterparty:saved`
so the parent wizard updates the affected row in place without
re-running the importer.

## RunImport: preview idempotency + race recovery

`RunImport::runFromUpload()` is the first half of the wizard's two-step
ceremony:

1. Hash the local file (SHA256). If the user already imported a file
   with the same hash AND that import landed (`status='confirmed'`),
   short-circuit with an empty preview — the file-layer idempotency
   guard backed by the UNIQUE `(user_id, sha256)` index on
   `import_runs`.
2. Copy the upload into the app-owned `storage/app/imports/` folder so
   a stable, app-owned path is persisted on the `ImportRun` row (the
   Livewire temporary upload directory is garbage-collected after 24h
   and cannot be referenced later, e.g. by inline account naming after
   a coffee break).
3. Otherwise create (or reuse) an `ImportRun` row with
   `status='previewed'` so the wizard has a stable id to reference — a
   reused row (prior status `'previewed'` or `'discarded'`) has its
   audit fields reset so stale counters/status never leak into the
   fresh attempt.
4. Run `ImportPipeline::preview()` and cache the canonical batch under
   that import run id for the Confirm step.

A concurrent preview for the same `(user_id, sha256)` racing between the
initial SELECT and the INSERT is caught as
`UniqueConstraintViolationException`: `reReadAfterRace()` re-reads the
winner's row (a null result here is a genuine, unexpected invariant
break — the violation itself proves the row committed) and falls
through to the same confirmed-short-circuit / reuse-reset semantics
rather than surfacing an unhandled 500. `runAndConfirm()` is the
test/CLI convenience that drives both preview and confirm in one call.

`runFromRemoteFetch()` mirrors this shape for a remote-fetch caller with
no local file to hash and copy — the *only* legal way a module other
than Import (e.g. `Modules\OpenBanking`) can drive the pipeline, since
`ImportPipeline` itself is `Modules\Import\Internal`:

- Dedups at the `ImportRun` grain on a caller-supplied
  `$idempotencyKey` instead of a file SHA256 (there is no file); the
  contract requires the caller derive it deterministically from the
  fetch window (never wall-clock time) so re-running "Sync now" within
  the same window reuses one row.
- `raw_file_path` has no real file to point at, so it carries a
  synthetic marker (`syntheticRawFilePath()`, `open-banking://{key}`)
  that is deterministic per key and unambiguously not a real on-disk
  path.
- Runs `ImportPipeline::previewFromGenerator()` (skips
  `persistStatementMetadata()` — no CAMT-shaped statement summary for a
  point-in-time balance) instead of `preview()`.
- Per-row duplicate detection is still enforced downstream by
  `FingerprintStage`, independent of this dedup layer.

## Applying enrichments

`ApplyEnrichments` wraps each `PendingEnrichment` in its own per-row DB
transaction with `lockForUpdate()`, so two concurrent imports targeting
the same row's `source_ref` either serialise or short-circuit on a rank
re-evaluation rather than racing on the UPDATE. The stored `source_ref`
is read again inside the lock and re-ranked against the incoming
enrichment via `SourceRefRanker`; if the stored ref now outranks or ties
the incoming one, the enrichment is dropped as a no-op (a cached weaker
reference can never overwrite a freshly-stored stronger one — this
closes the preview-then-confirm TOCTOU window). `enriched_from`
accumulates a full append-only provenance trail, one JSON entry per
successful application: `{format, ran_at, import_run_id, added}`.
Caller scoping by `user_id` is enforced inside the UPDATE itself, so a
forged `PendingEnrichment` referencing another user's transaction id
resolves zero rows and is silently dropped.

**What counts as a receipt format** is decided in one place,
`SourceRefRanker::isReceiptFormat()`, and both the `FingerprintStage`
gate that produces an ENRICHED disposition and the conflict branch below
read it. It answers by asking `SourceFormat::isReceiptFile()` rather
than holding a list of its own, and `rank()` returns one shared band for
whatever that answers — so a new receipt transport is recognised here
the moment the enum recognises it. The answer has to include the
**transports** `eml` and `mbox`, because that is the `source_format` a
receipt row is stored under: the wizard's receipt arm and `Receipts`'
inbox job both normalise under the transport id. Listing only the
per-matcher ids (`paypal-receipt`, `ics-receipt`, `google-play-receipt`)
made the gate unreachable for every receipt an install can actually
produce — receipt-vs-statement collisions all dropped as DUPLICATE while
the unit tests, which hand-built a `paypal-receipt` row nothing writes,
stayed green. That is the drift a second copy of the list invites, and
it is why there is now only one.

**Receipt-conflict branch** (only when `conflictingFields` is non-empty
AND the source format is a receipt format): the user's
`receipt_conflict_resolution` policy decides the outcome —

- `unset` — INSERTs one `pending_enrichment_conflicts` row and
  dispatches `ReceiptConflictDetected` per conflicting field (the toast
  surfaces the choice); the per-field UPDATE is skipped.
- `prefer_receipt` — the incoming (receipt-derived) values land
  silently in the same UPDATE as the `source_ref` change.
- `prefer_first_write` — the stored values are kept verbatim; only the
  `source_ref` enrichment proceeds.

Two encryption guarantees hold regardless of policy: `FingerprintStage`
decrypts the stored value before ever populating `conflictingFields`, so
`stored_value`/`csvValue` are always plaintext (never ciphertext)
wherever they are persisted or dispatched; and the fresh incoming values
travel as plaintext only as far as `writeEnrichment()`, which puts them
through `SensitiveColumnCodec::encryptAttrs()` before they reach the
`transactions` UPDATE — so a `prefer_receipt` resolution never
re-introduces plaintext into an at-rest-encrypted column (a documented
no-op pass-through for a non-encrypted user). The plaintext leg is not
an oversight: `rederivedFingerprint()` has to read the counterparty name
in the clear to re-key it, and AEAD ciphertext differs on every write of
the same value.
`EnrichmentConflictField` is the closed vocabulary of column names that
may flow through as literal SQL column names, so a poisoned preview
cache can never turn an arbitrary array key into an UPDATE column. It is
one enum in `Import\Public\Enums`, named by `FingerprintStage` as it
emits each conflict, by `ApplyEnrichments` as it accepts one, and by
`Receipts`' `ApplyReceiptConflictResolution` as it resolves one — where
three hand-copied `['counterparty_name', 'description', 'currency',
'amount_minor']` lists used to sit, each under a comment saying it
mirrored one of the others. `isFingerprintInput()` carries the subset
the fingerprint tuple is composed over, so the recompose and the
allow-list can no longer disagree.

The user's `receipt_conflict_resolution` policy is read into a
method-local variable per `__invoke()` call — never cached on the action
instance, since the action is a singleton and an instance-level cache
would leak across users on the same queue-worker process.

## Preview cache

`PreviewCache` holds the parsed canonical batch, pending enrichments, and
preview payload between the upload/preview click and the confirm click
(30-minute TTL, DTOs round-tripped via JSON — never PHP's native
object-deserialisation, so a corrupted or schema-skewed payload throws
loudly instead of silently dropping rows).

Four keys per run: `import.{id}.preview`, `import.{id}.preview-summary`,
`import.{id}.canonical` and `import.{id}.enrichments`. The summary is
derived from the preview payload and written, refreshed and dropped with
it — `put()`, `applyAliasInPlace()` and `forget()` each touch both — so a
writer that puts the preview key directly has to drop the summary key
beside it or the consolidated screen answers from the older row set. A
reader that needs more than the summary holds falls back to the preview
payload on its own.

Each getter reads its key **once**. `Repository::has()` is `get()` with
the result thrown away, so the file store deserialises the whole payload
twice for a `has()`-then-`get()` pair — on the preview key that is the
entire row set, read twice per render. A single `get()` distinguishes the
two cases just as well: nothing is ever stored as `null`, so `null` is
absence and any other non-conforming payload is the corruption that
throws. The cached content is a
knowingly-accepted transient plaintext exposure: `counterparty_name`,
`description`, `counterparty_iban`, and any decrypted conflict values sit
in cleartext at rest for the TTL window (a plaintext file under
`storage/framework/cache/`, or an on-disk SQLite `cache` table) — reviewed
and accepted given the bounded window and that the preview/confirm flow
only runs behind the unlocked app-lock.
