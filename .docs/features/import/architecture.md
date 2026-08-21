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
this page describes the module's surface. The user uploads / drops a
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
  - `RunsImports::preview($file, $sourceFormat, $user)` — the
    preview phase entry point (no DB writes; caches the canonical
    rows for the confirm step).
  - `ConfirmsImports::confirm($previewId, $user)` — the confirm
    phase entry point (persists through Ledger; dispatches Chains).
  - `NamesAccounts::nameAccount($iban, $name, $user)` — the
    "name this unknown IBAN" hook used inline in the wizard.
  - `AppliesEnrichments::apply($enrichments, $user)` — re-imports
    that produce stronger `source_ref` values.
  - `PaymentTypeHinter::hint($tx)` — per-source hinter contract;
    discovered through the `import.payment_type_hinter` tag.
  - `DetectsStartingBalance::detect($file)` — per-source detector
    contract; discovered through the `starting-balance.detector`
    tag.
  - `ResolvesKnownCounterpartyIban::resolveFor($iban, $user)` —
    the bridge between an institution IBAN (PayPal Luxembourg, ICS
    at ABN AMRO) and the user's synthetic-IBAN account.
- **Actions/**
  - `RunImport` (impl. `RunsImports`).
  - `ConfirmImport` (impl. `ConfirmsImports`).
  - `ApplyEnrichments` (impl. `AppliesEnrichments`).
  - `CreateMerchantAlias`, `MergeMerchantAliases`.
- **Pipeline/**
  - `ResolvesCounterparties` lives in
    [`Counterparties`](../counterparties/architecture.md); the
    pipeline injects the contract.
- **DTOs/** — `ImportPreviewDto`, `ImportRunResultDto`,
  `PaymentTypeHintDto`, `StartingBalanceDto`, etc.
- **Enums/** — `SourceFormat`, `PaymentType`,
  `ImportRunStatus`.
- **Events/**
  - `TransactionImported` — raised by `ConfirmImport` per row
    after persist. Consumed by `Desktop` for OS notifications.
- **Services/**
  - `AccountNamer` (impl. `NamesAccounts`).
  - `MerchantNameResolver` — five-step matcher (per-user exact →
    per-user generalised → community exact → community generalised
    → null) consumed by `Counterparties`.
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
  `FingerprintStage`. The other stages
  (`NormalizeStage`, `ApplyAutoCategoryStage`,
  `ResolveCounterpartyStage`, `RecordsStatementSummary`) are
  owned by their respective modules and injected here.
- **Internal/Parsers/** — per-source parsers (`Asn/Camt053`,
  `Asn/Mt940`, `Asn/Csv`, `Ics/Pdf`, `Paypal/Csv`). Each ships
  its own `PaymentTypeHinter`.
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

- `ImportPipeline::preview($file, $sourceFormat, $user)` —
  stages: parse → normalize → classify-transaction-type →
  payment-type → auto-category → counterparty-resolve →
  fingerprint. No DB writes; result cached.
- `ConfirmImport::confirm($previewId, $user)` — replays cached
  rows through `RecordsTransactions`; applies pending
  enrichments inside the same DB transaction; AFTER commit:
  `UpsertsCardStatements::upsert` then
  `DispatchesChainResolution::dispatch`.
- `KnownCounterpartyIbanResolver::resolveFor($iban, $user)` —
  reads `known_counterparty_ibans`; returns the user's matching
  `paypal` / `ics_card` Account (or null).
- `PaymentTypeClassifierStage` — collects tagged
  `PaymentTypeHinter` instances; first match wins; the
  description-keyword fallback is intentionally LAST so the
  registry test's "fallback is last" invariant holds.
- `DetectStartingBalancesQuery` — collects tagged
  `DetectsStartingBalance` detectors; returns the first
  non-empty list. CAMT.053 first (canonical), MT940 second
  (legacy), ICS PDF third, PayPal CSV last (always declines).
- `MerchantNameResolver::resolve($description, $user)` —
  five-step matcher consumed by `Counterparties::CounterpartyResolverService`.
- `HandleFileOpenedFromOs` — extension filter; persists into the
  `Desktop` pending-intent store.

## Data flow

The end-to-end import:

```
User uploads file (or drops a .csv onto the app)
  ├─ drop path: Desktop::FileOpenedFromOs → HandleFileOpenedFromOs
  │              → Desktop::PendingFileIntent
  │              → user logs in (if needed)
  │              → /desktop/file-staging → /imports/new
  └─ upload path: UploadWizard SFC

PreviewWizard
  → RunImport::preview($file, $sourceFormat, $user)
       → ImportPipeline::preview
            → ParseStage (per-source parser)
            → NormalizeStage (from Ingestion; injected)
            → ClassifyTransactionType
            → PaymentTypeClassifierStage (per-source hinters)
            → ApplyAutoCategoryStage (from Categorization)
            → ResolveCounterpartyStage (from Counterparties)
            → FingerprintStage
       → cache canonical rows under preview id
  → user reviews preview
  → ConfirmImport::confirm($previewId, $user)
       → BEGIN TX
            → for each cached row: RecordsTransactions::record
            → ApplyEnrichments::apply (pending source_ref strengthens)
       → COMMIT
       → UpsertsCardStatements::upsert  (Chains contract — Pre-Chain step)
       → DispatchesChainResolution::dispatch (Chains contract)
       → per row: dispatch TransactionImported
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
            both idempotent on UNIQUE(user_id, institution_iban)
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

## Merchant aliases

`MerchantNameResolver::resolve()` walks a five-step precedence stack
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
5. `null` — the caller renders the raw, italic-muted description.

Exact-then-generalized ordering is deliberate: if both an exact alias and
a broader generalized alias match the same row, the exact one wins, so a
broad rename can never silently override a more specific one.

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
before touching the database. `diff()` classifies each parsed entry as
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
with an explanatory empty result.

`AccountNamer`'s slug derivation is `slug($name) + '-' + last8(iban)` —
the last 8 characters (not 4) dramatically lower the chance of two
distinct IBANs colliding on the same slug, and the per-user
`UNIQUE(user_id, slug)` + `UNIQUE(user_id, iban)` pair guarantees the
same IBAN never lands twice.

## Payment-type hinters

Each per-source `PaymentTypeHinter` (`Modules/Import/Internal/Parsers/`)
declines with `null` for rows outside its own `source_format`, then
inspects the row for a recognisable signal:

- **AsnCsvPaymentTypeHinter** / **Mt940PaymentTypeHinter** scan the
  description for Dutch lexemes ASN embeds in every row (`Betaalautomaat`/
  `Geldautomaat` → Pin, `Incasso`/`Automatische incasso` → DirectDebit,
  `iDEAL`/`Online betaling` → Online, `Overboeking`/`SEPA Credit
  Transfer` → Transfer), matched case-insensitively via `mb_strpos` (no
  SQL). MT940 mirrors the ASN CSV keyword set verbatim since its `:86:`
  narrative carries the same lexemes.
- **Camt053PaymentTypeHinter** keys off the authoritative ISO20022
  `BkTxCd` (domain|family|subFamily) tuple first —
  `PMNT|CCRD|POSD`/`POSC` → Pin, `PMNT|IDDT|ESDD`/`PMDD`/`RCDT|ESDD` →
  DirectDebit, `PMNT|ICDT|ESCT`/`RCDT|ESCT` → Transfer — falling back to
  the same Dutch-lexeme scan when the tuple is missing or unrecognised
  (CAMT entries carry the same narrative text merged into `description`).
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
can confirm, resolves three naming branches sharing one Blade section —
all scoped to the authenticated `CurrentUser` so a forged `importRunId`
from another user 404s via `firstOrFail` rather than exposing that
user's import run:

- **IBAN naming** — an ASN source row references an IBAN the user
  hasn't linked to an Account yet. The pipeline surfaces those via
  `$preview->accountsToName`; `nameAccount()` re-validates the supplied
  IBAN against that same list (defence-in-depth against a crafted wire
  request naming an arbitrary IBAN) before delegating to `AccountNamer`.
- **ICS card naming** — triggered when the run's `source_format` is
  `'ics-pdf'` AND no `kind='ics_card'` Account exists yet.
  `saveIcsAccountName()` inserts the synthetic `'ICS-CARD'`-IBAN
  Account and re-runs the importer so the preview catches up.
- **PayPal naming** — same shape, keyed on `source_format =
  'paypal-csv'` and `kind = 'paypal'`, synthetic IBAN `'PAYPAL'`.

Both synthetic-account branches share `AccountNamer::validateName()`
for the 1..80-character bound + slug-body guard, but can't use
`NamesAccounts` end-to-end because a synthetic IBAN doesn't satisfy the
structural guard `AccountNamer` enforces for real IBANs.

`needsIcsAccountName()` / `needsPaypalAccountName()` anchor the naming
prompt on the run's `source_format` rather than the unknown-IBAN list,
so a future synthetic-IBAN drift (e.g. `'ICS-CARD-PRIMARY'`) still
triggers the prompt. Both use the raw query builder (via injected
`DatabaseManager`) rather than the Eloquent Builder to keep
phpstan-strict-rules' `staticMethod.dynamicCall` rule quiet — the same
convention the dashboard queries under `Modules/Ledger/Public/Services/`
follow.

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

## Upload wizard

Step 1 of the wizard. The user picks an issuer (ASN / ICS / PayPal /
other-bank / email-file) and a format for that issuer, then uploads a
statement file; on submit the file is staged via Livewire's temporary
upload directory, the importer runs the preview phase (copying the
upload to a stable app-owned path on the way through), and the user is
redirected to `/imports/{id}/preview`.

Two cascading selects drive the page: changing `$issuer` rebuilds the
Format select via `availableFormats()` and resets `$sourceFormat` to
the new issuer's first valid leaf (`updatedIssuer()`) — otherwise a
defensive `in:` validator would still accept a stale composite while
the picker visually disagreed with the submitted value. The leaf
`sourceFormat` value is the wire format `HeaderSniffer`,
`SourceAdapterRegistry`, and the per-source-format adapters dispatch on;
the issuer field exists only to drive the picker UX. `rules()`'s
`issuerFormatRule()` closure additionally enforces that the
issuer/sourceFormat pair is a meaningful cross-product (e.g.
`issuer='email-file'` + `sourceFormat='asn-csv'` fails here rather than
downstream at `ParseStage`). The file-size cap varies by format (10 MB
default — matches the typical maximum ASN statement export and is well
above a Mijn ICS monthly PDF's ~100 KB — 1 MB for `.mbox`, 20 KB for
`.eml`).

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
wherever they are persisted or dispatched; and `extractIncomingValues()`
runs the fresh incoming values through `SensitiveColumnCodec::encryptAttrs()`
before they reach the `transactions` UPDATE, so a `prefer_receipt`
resolution never re-introduces plaintext into an at-rest-encrypted
column (a documented no-op pass-through for a non-encrypted user).
`ALLOWED_CONFLICT_FIELDS` whitelists the four column names that may flow
through as literal SQL column names, so a poisoned preview cache can
never turn an arbitrary array key into an UPDATE column.

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
