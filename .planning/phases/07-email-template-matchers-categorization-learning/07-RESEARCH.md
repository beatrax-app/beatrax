# Phase 7: Email Template Matchers + Categorization Learning - Research

**Researched:** 2026-05-17
**Domain:** Email-receipt → canonical-transaction matching + categorization rules/memory + correction-divergence UX
**Confidence:** HIGH for stack/architecture (existing libraries + locked patterns); MEDIUM for per-sender receipt template anchors (templates drift); LOW for the exact PayPal/ICS/Google Play template shape without fresh empirical fixtures

## Summary

Phase 7 plugs a new `Modules/Receipts/` bounded module into the locked Phase 1–5 SourceAdapter pipeline. Three load-bearing facts shape the work: (1) every infrastructure prerequisite already exists — `zbateson/mail-mime-parser 4.0.1` is already in `composer.lock`, the `NormalizeStage → FingerprintComposer v3 → FingerprintStage::classify → ApplyEnrichments → RecordTransactions` pipeline is locked and unit-tested, `Modules/EmailScan/Public/Services/InboxMessageQuery` already streams `status='fetched'` rows via a generator, the `MimeHeaderParser` already wraps zbateson for the four header fields, and the `TransactionImported` event + Phase 5 `ResolveChainLinksJob` already pick up `reference_id` automatically; (2) the project's invariants forbid the obvious shortcuts — no facades, no helpers, no auto-detection of receipt format (declared up-front like every other source), no transaction writes from `Modules/EmailScan/`; (3) two domains carry real research risk — exact PayPal/ICS Cards/Google Play HTML template anchors must be mined from real inbox fixtures during Wave 0 (templates drift; web-search returns marketing material, not anchors), and `.mbox` streaming has only one viable PHP library (`armin/mbox-parser`) which is INCOMPATIBLE with the locked `zbateson 4.0` (it requires `^2.2`) so the mbox iterator must be hand-rolled.

**Primary recommendation:** Hand-roll three matchers + a small hand-rolled mbox iterator on top of the already-installed `zbateson/mail-mime-parser`; reuse the existing `MimeHeaderParser` shape and the locked ingestion pipeline verbatim; introduce a *new* `pending_enrichment_conflicts` table (not a column on the in-memory `PendingEnrichment` DTO — there is no `pending_enrichments` *table*); ship matchers as a Laravel-13 container-tagged collection with a `priority()` arm sort + `canHandle()` filter; gate every install behind the slopcheck check below.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `.eml` MIME parsing (raw bytes → headers + bodies + attachments) | Backend (Receipts/Internal) | — | Pure PHP via `zbateson`; no browser involvement |
| Per-sender pattern matching (`canHandle` + `match`) | Backend (Receipts/Internal/Matchers) | — | One class per sender; container-tagged registry |
| `.mbox` streaming iterator (line-by-line state machine) | Backend (Receipts/Internal/Pipeline) | — | Multi-GB safety mandates streaming, never load-to-memory |
| Wizard preview/confirm for file drop | Frontend (Livewire 4 SFC) | Backend (Receipts/Public actions) | Mirrors existing `/imports` wizard pattern |
| Watched-folder polling (background) | Backend (Receipts/Internal/Jobs + console schedule) | OS (launchd via Phase 6's plists) | Local-only, polled, no push |
| Rule evaluation + memory write-side | Backend (Categorization/Internal) | — | Already-owned categorization domain |
| Rule CRUD UI on `/rules` | Frontend (Livewire 4 + Flux table/modal) | Backend (Categorization/Public actions) | Standard CRUD page, calm aesthetic |
| Correction-divergence toast + drawer panel | Frontend (Livewire dispatch + Alpine listener) | Backend (Categorization/Public events) | Reuses Phase 4/5 toast pattern |
| Chain hint emission | Backend (Receipts/Public/Events) | Backend (Chains listener) | `reference_id` always (silent), typed event for richer hints |
| Cross-format dedup of receipt vs CSV | Backend (existing FingerprintStage::classify) | — | Fingerprint-equivalence is the load-bearing invariant |

## User Constraints (from CONTEXT.md)

### Locked Decisions

**Matcher Architecture & Module Home**

- **D-701:** New `Modules/Receipts/` bounded module with Public/Internal split from day one. Owns matchers, registry, `.eml`/`.mbox` ingestion path, `ParsedReceiptDto → SourceTransactionDto` adapter, consumer that walks `inbox_messages.status='fetched'`. New `BoundaryArchTest::noEmailFetchFromReceipts` invariant: Receipts must NEVER call `GmailApiClient`/`GraphApiClient`/OAuth code. Public surface: `RecordReceipt` action, `FileImportQuery`, `SenderMatcher` contract, `MatcherRegistry`, `ParsedReceiptDto`, `MatchOutcomeDto`, `ChainHintDetected` event.
- **D-702:** Per-sender PHP class implementing `SenderMatcher`. `canHandle(InboxMessageDto $m): bool` + `match(string $emlRaw): MatchOutcomeDto`. Registry collects them via container tagging. YAML/regex template configs rejected.
- **D-703:** Receipt → Transaction reuses the existing SourceAdapter pipeline. `ParsedReceiptDto` → `SourceTransactionDto` → `NormalizeStage` → `FingerprintComposer v3` → `FingerprintStage::classify` → `ApplyEnrichments` → `RecordTransactions`. `matcher_key` flows through as the `source_kind`/`source_format` equivalent.

**.eml / .mbox Drop-In (EML-07)**

- **D-704:** Wizard primary + watched folder optional (off by default). New "Email file (.eml/.mbox)" issuer on `/imports`; `HeaderSniffer` learns RFC 822 + mbox arms. Watched folder under `storage/app/inbox-drop/` behind `/settings` toggle, scheduled scan, moves processed files to `/processed/{YYYY-MM}/`.
- **D-705:** Separate `file_imports` table — `inbox_messages` stays API-only. Shape mirrors `inbox_messages`: `id`, `user_id`, `source_kind` enum (`'eml'`/`'mbox'`), `source_filename`, `provider_message_id` (RFC 822 `Message-ID`, nullable per D-705a), `internal_date`, `sender_email`, `sender_name`, `subject`, `eml_path` (`storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/{message_id_hash}.eml`), `status` enum (`fetched`/`parsed`/`skipped`/`unmatched`), `fetched_at`, timestamps. `UNIQUE (user_id, provider_message_id)`.
- **D-705a:** Synthetic `Message-ID` for files missing the header = `sha256(eml_raw_bytes)`.

**Dedup & Fingerprint Conflicts**

- **D-706:** Cross-source fingerprint matches reuse Phase 2 Wave 3 `ENRICHED` disposition + `ApplyEnrichments`. Default for non-conflict (second source only adds new data).
- **D-707:** First-conflict toast → per-user `users.receipt_conflict_resolution` enum (`'unset'` default, `'prefer_receipt'`, `'prefer_first_write'`) → silent application thereafter. Conflict held in a side channel (planner picks: new `pending_enrichment_conflicts` table OR transient field on existing `pending_enrichments` — see D-715).
- **D-708:** Chain hints — matchers populate `transaction.reference_id` (silent, Phase 5's `ResolveChainLinksJob` picks them up). Additionally emit typed `ChainHintDetected` event for richer cross-source clues. Payload: `{ source_transaction_id, hint_type, hint_payload (typed sub-DTO), evidence }`.

**Categorization Learning (CAT-02 + CAT-04)**

- **D-709:** Rules = field-selector + match-type + value (no regex in v1). `{ field: 'merchant'|'description'|'counterparty', match: 'contains'|'equals'|'starts_with', value: string, category_id }`. Case-insensitive. Multi-condition + regex deferred to v2.
- **D-710:** Rule engine + memory-writer in `Modules/Categorization/`. New `CategorizationRule` model + CRUD action, `RuleEvaluator` service, `MerchantMemoryWriter` listener (grows `merchant_memories` from `TransactionCategorized`), new `ApplyAutoCategoryStage` after `NormalizeStage`.
- **D-711:** Specificity-scored precedence; rule beats memory on tie. Scoring: equals=100, memory=90, starts_with=50+len, contains=10+len. Memory grows even when rule wins.
- **D-712:** Toast + inline drawer panel for correction divergence; both call same `UpdateCategorizationRule` action. Memory-driven suggestions get a lighter inline panel only ("Auto-categorized from merchant history [Override]") — no toast.
- **D-713:** New `/rules` top-nav page; sibling to `/transactions`, `/imports`, `/chains/review`, `/inboxes`, `/uncategorized`.

### Claude's Discretion

- **D-714:** Wave structure suggestion (Wave 0 = fixtures + module skeleton + arch tests; Wave 1 = PaypalReceiptMatcher vertical slice + file-drop entrypoint; Wave 2 = IcsReceiptMatcher + GooglePlayReceiptMatcher; Wave 3 = ApplyAutoCategoryStage + merchant_memories writer; Wave 4 = rules CRUD + `/rules` page + correction-divergence UX).
- **D-715:** Conflict-pending storage shape — new `pending_enrichment_conflicts` table OR transient field on existing `pending_enrichments`. **See "Pending-Conflict Storage Decision" below — recommendation: new table.**
- **D-716:** Tag name for matcher container binding (`'receipts.matcher'` suggested) + priority vs `canHandle()` semantics. **See "Container Tagging" below.**
- **D-717:** Hash function for D-705a synthetic Message-ID. **See "Synthetic Message-ID Hash" below — recommendation: sha256.**
- **D-718:** Watched-folder cadence — 5 minutes suggested. **See "Watched Folder" below.**
- **D-719:** `ApplyAutoCategoryStage` sync (inside `RecordTransactions`) vs async (queued job on `TransactionImported`). **See "ApplyAutoCategoryStage Placement" below — recommendation: synchronous.**
- **D-720:** UI-SPEC pass locks Flux components for `/rules` (table, modal, dropdowns) and inline drawer panel.
- **D-721:** Top-nav grouping if `/rules`+`/uncategorized`+`/chains/review` crowd the bar. UI-SPEC owns this.
- **D-722:** Whether `categorization_rules` supports `active` boolean + `notes` text field — planner decides Wave 4 budget.

### Deferred Ideas (OUT OF SCOPE)

- **Regex rules** (D-709 v2) — `match` enum reserves room for `'regex'`.
- **Multi-condition rules** (D-709 v2) — e.g., "merchant contains X AND amount > Y".
- **Free-form DSL for rules** — rejected complexity tier.
- **Per-field conflict precedence map** (D-707 v2 power-user refinement).
- **PDF receipt parsing** — Phase 7 v1 covers HTML email; OCR is v2.
- **Image-receipt / photo ingestion** — never on the roadmap.
- **Watched-folder enabled by default** — v2 nicety with onboarding hint.
- **Top-nav "Categorize" submenu** — UI-SPEC defers (D-721).
- **`active` toggle + `notes` field on rules** (D-722 nicety; planner decides).
- **Re-parse trigger from UI** for `status='unmatched'` rows after matcher updates — likely v2 unless Wave 4 budget allows.
- **Per-merchant `normalized_merchant` write-side** (Phase 6 D-139 deferred) — only if needed for CAT-02 to fire reliably.
- **Re-parse on rule change** — v2 power-user feature.
- **Inline "create rule from this transaction" affordance** on `/transactions/{id}` — nice-to-have; v2 if Wave 4 doesn't fit.
- **Confidence indicator on auto-categorization** (green dot for rule, dim dot for memory) — UI-SPEC may include if budget allows.
- **Bulk rule import/export (JSON)** — v2 power-user.

## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| EML-05 | Per-sender template matchers exist for PayPal, ICS Cards, and Google Play receipts; each extracts merchant, amount, currency, and reference IDs into canonical transactions | Per-sender matcher pattern (Standard Stack + Architecture Patterns + Per-Sender Matcher Patterns sections); matchers fed by `InboxMessageQuery::forStatus('fetched')` already shipped in Phase 6 |
| EML-07 | User can drop an `.eml` or `.mbox` file in an import folder and have it ingested via the same matcher pipeline | `.eml`/`.mbox` ingestion path (`Modules/Receipts/Internal/Pipeline/EmlMimeReader.php` + hand-rolled `MboxIterator.php`); `file_imports` table; `/imports` wizard arm + optional watched folder |
| CAT-02 | After categorizing a merchant once, future transactions from the same normalized merchant are auto-suggested the same category | `MerchantMemoryWriter` listener growing existing `merchant_memories` table; `ApplyAutoCategoryStage` evaluating memory at import time |
| CAT-04 | User can define rules ("contains 'SPOTIFY' → Subscriptions/Streaming") that pre-categorize on import | `categorization_rules` table + Public actions + `RuleEvaluator` service + correction-divergence UX (toast + drawer panel) |

## Standard Stack

### Core (Already Installed — Verified in `composer.lock`)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `zbateson/mail-mime-parser` | `4.0.1` (locked) | `.eml` MIME parsing — headers, bodies, attachments, multipart, charset decode, RFC 2047 encoded-words | `[VERIFIED: composer.lock + Packagist 51M total downloads, 1.2M/month]` Pure PHP, no `ext-imap` dependency — exactly what PLT-05 needs. Already wired up via `Modules/EmailScan/Internal/MimeHeaderParser` (Phase 6). v4.0 tested on PHP 8.1–8.5 `[CITED: mail-mime-parser.org/upgrade-4.0]` |
| `brick/money` | `^0.11` (locked) | Multi-currency arithmetic for receipt amounts (USD on Google Play, EUR for PayPal/ICS) | `[VERIFIED: composer.json]` Already locked across project. Receipt amounts arrive as strings — must parse via `BigDecimal` and emit minor-units integers per FND-04 |
| `spatie/laravel-data` | `^4.0` (locked) | Typed DTOs (`ParsedReceiptDto`, `MatchOutcomeDto`, `MatcherInputDto`, `CategorizationRuleDto`, `AutoCategorizationOutcomeDto`, `ChainHintDetected` payload sub-DTOs) | `[VERIFIED: composer.json]` Already the canonical DTO base across every module |
| `livewire/livewire` | `^4.0` + `livewire/flux ^2.0` (locked) | `/rules` page, rule form modal, correction-divergence toast | `[VERIFIED: composer.json]` Identical to `/chains/review`, `/inboxes`, `/uncategorized` patterns |

### Supporting (Already Installed)

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `pestphp/pest` + `pest-plugin-arch` + `pest-plugin-laravel` + `spatie/pest-plugin-snapshots` | `^4.0` / `^2.0` (locked) | Matcher unit tests, snapshot tests for `.eml` → `ParsedReceiptDto` round-trip, arch invariants | `[VERIFIED: composer.json]` Standard for the project |
| `larastan/larastan` (level max) + `phpstan/phpstan-strict-rules` + `canvural/larastan-strict-rules` | `^3.0` / `^2.0` / `^3.0` (locked) | Static analysis on matcher code | `[VERIFIED: composer.json]` strict-rules forbids facade/helper calls; matcher code must use injected `DatabaseManager` for whereBetween/whereIn |

### Alternatives Considered (Rejected)

| Instead of | Could Use | Tradeoff / Reason Rejected |
|------------|-----------|----------------------------|
| Hand-rolled `MboxIterator` | `armin/mbox-parser` `^2.0` | `[VERIFIED: composer require --dry-run output]` armin/mbox-parser requires `zbateson/mail-mime-parser ^2.2` — project is on `4.0.1`. Incompatible. Trying to install it triggers a SAT-solver failure. Hand-rolling a 60-line line-by-line state machine is the cheaper path. **[VERIFIED: GitHub: github.com/a-r-m-i-n/mbox-parser](https://github.com/a-r-m-i-n/mbox-parser) — confirmed `composer.json` of armin/mbox-parser 2.0.1 declares `"zbateson/mail-mime-parser": "^2.2"`.** |
| Config-driven YAML/regex templates | YAML matcher manifests parsed at boot | Rejected per D-702. Hostile to PHPStan level max type safety; can't express per-sender quirks (PayPal fees/holds rollup, ICS PDF attachment hints) without escape hatches; steps outside project's typed-DTO style |
| Auto-detect file format | Sniff `.eml` vs `.mbox` from first bytes inside the wizard | Project invariant: declared format up-front (ING-07 / D-704). HeaderSniffer arm exists to *validate* the user's declared format matches the file shape, never to guess |
| Single mega-matcher class | One `ReceiptMatcher` with internal sender branching | Rejected per D-702. Per-sender classes give isolated unit-test surface, isolated fixture sets, isolated bug-fix surface. Mirrors `PaypalCsvAdapter` precedent |
| New parallel matcher → transaction pipeline | Dedicated Receipts-only writer skipping `SourceAdapter` pipeline | Rejected per D-703. Would reimplement idempotency + cross-format dedup. The whole "receipts dedupe against CSVs for free" payoff comes from fingerprint-equivalence — only attainable by reusing `NormalizeStage → FingerprintComposer v3` |

**Installation:**

Zero new composer dependencies for Phase 7. Every library is already in `composer.lock` (Phase 6 introduced `zbateson/mail-mime-parser`; Phase 4–5 introduced everything else).

**Version verification commands (already run):**

```bash
# zbateson/mail-mime-parser — confirmed installed at 4.0.1
grep -A2 '"zbateson/mail-mime-parser"' composer.lock
# armin/mbox-parser — confirmed INCOMPATIBLE
composer require --dry-run armin/mbox-parser  # SAT failure
```

## Package Legitimacy Audit

> Phase 7 introduces ZERO new direct composer dependencies. The two packages that *could* be relevant were audited via slopcheck (`-e packagist`):

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `zbateson/mail-mime-parser` | packagist | ~10 yrs | 51.2M total / 1.2M monthly | [github.com/zbateson/mail-mime-parser](https://github.com/zbateson/mail-mime-parser) | [OK] | Approved — already installed (`4.0.1`) |
| `armin/mbox-parser` | packagist | ~5 yrs | 27K total / 1.5K monthly | [github.com/a-r-m-i-n/mbox-parser](https://github.com/a-r-m-i-n/mbox-parser) | [OK] | **REMOVED** — version-conflict with locked `zbateson 4.0` (requires `^2.2`). Hand-roll the mbox iterator instead |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
**Packages removed for version-incompatibility:** `armin/mbox-parser` (hand-roll `MboxIterator` instead)

`[VERIFIED: slopcheck install -e packagist zbateson/mail-mime-parser armin/mbox-parser]`

## Architecture Patterns

### System Architecture Diagram

```
                                    ┌──────────────────────────────────┐
                                    │  Phase 6 (already shipped):       │
                                    │  Gmail / Graph fetchers           │
                                    │  → on-disk .eml blobs             │
                                    │  → inbox_messages.status='fetched'│
                                    └─────────────┬────────────────────┘
                                                  │ InboxMessageQuery::forStatus('fetched')
                                                  ▼
   ┌──────────────────┐         ┌────────────────────────────────────────────┐
   │ /imports wizard  │         │ ProcessFetchedInboxMessagesJob (queued)     │
   │  "Email file"    │         │  • walks inbox_messages + file_imports      │
   │  arm (EML-07)    │         │  • dispatches via MatcherRegistry           │
   └────────┬─────────┘         └──────────────┬─────────────────────────────┘
            │                                  │
            │ .eml or .mbox upload             │ raw .eml bytes from disk
            ▼                                  │
   ┌──────────────────┐                        │
   │  HeaderSniffer   │  detects RFC 822 / mbox│
   │  (Receipts arm)  │                        │
   └────────┬─────────┘                        │
            │                                  │
            ▼                                  ▼
   ┌──────────────────────┐    ┌──────────────────────────────────────┐
   │  EmlMimeReader       │    │  MatcherRegistry (container-tagged)  │
   │  (zbateson wrap)     │───▶│  • sort by priority()                │
   │                      │    │  • first canHandle()==true wins      │
   └──────────────────────┘    └──────────────┬───────────────────────┘
                                              │
                       ┌──────────────────────┼──────────────────────┐
                       ▼                      ▼                      ▼
              PaypalReceiptMatcher  IcsReceiptMatcher       GooglePlayReceiptMatcher
                       │                      │                      │
                       └──────────┬───────────┴──────────────────────┘
                                  │
                                  ▼
                       ┌──────────────────────┐
                       │  ParsedReceiptDto    │  matcher output
                       │  {merchant, amount,  │
                       │   currency, refId,   │
                       │   chainHints[]?, ...}│
                       └──────────┬───────────┘
                                  │
                                  ▼
                       ┌──────────────────────────────────────────────┐
                       │  ReceiptSourceAdapter (Internal/Pipeline)    │
                       │  ParsedReceiptDto → SourceTransactionDto     │
                       └──────────┬───────────────────────────────────┘
                                  │
                                  ▼
   ┌────────────────────────────────────────────────────────────────────────┐
   │           EXISTING LOCKED PIPELINE — no changes to its shape           │
   │                                                                        │
   │   NormalizeStage → ClassifyTransactionType → ApplyAutoCategoryStage*  │
   │       → FingerprintStage::classify → ApplyEnrichments                  │
   │       → RecordTransactions                                             │
   │                                                                        │
   │   * NEW stage added by Phase 7; runs uniformly for every source        │
   └─────┬──────────────────────┬───────────────────────┬───────────────────┘
         │                      │                       │
         │ NewRowDisposition    │ Enriched(no conflict) │ Enriched(conflict) ─┐
         ▼                      ▼                       │                     │
   INSERT row            UPDATE existing                │                     ▼
                         (silent ENRICHED)              │            users.receipt_conflict_resolution
                                                        │                     │
                                                        │       ┌─────────────┴────────────┐
                                                        │  'unset'             'prefer_*' (set)
                                                        │       │                          │
                                                        │       ▼                          ▼
                                                        │  pending_enrichment_conflicts    apply policy
                                                        │  + ReceiptConflictDetected       silently
                                                        │  event → first-conflict toast
                                                        │
                                                        ▼
                                              status: 'parsed'/'skipped'/'unmatched'

   ─── side surfaces ───
                                              ┌─────────────────────────────────┐
   TransactionImported  ────────────────────▶│ Phase 5 ResolveChainLinksJob     │
   (existing event)                           │ (already shipped — reads ref_id) │
                                              └─────────────────────────────────┘

                                              ┌──────────────────────────────────┐
   ChainHintDetected  (new, opt-in)  ───────▶│ Modules/Chains/Internal listener  │
                                              │ creates candidate chain_links     │
                                              └──────────────────────────────────┘

   TransactionCategorized  ─────────────────▶ MerchantMemoryWriter listener
   (existing event)                           grows merchant_memories
```

### Recommended Project Structure

```
Modules/Receipts/
├── composer.json                                   # diederik/receipts manifest
├── Providers/
│   └── ReceiptsServiceProvider.php                 # tag matchers, register MatcherRegistry, load migrations
├── Database/
│   └── Migrations/
│       ├── XXXX_create_file_imports_table.php
│       ├── XXXX_add_matcher_key_to_inbox_messages.php   # widens Phase 6's table
│       ├── XXXX_add_receipt_conflict_resolution_to_users.php
│       └── XXXX_create_pending_enrichment_conflicts_table.php
├── Public/
│   ├── Contracts/
│   │   └── SenderMatcher.php                       # canHandle + match + priority
│   ├── Dto/
│   │   ├── ParsedReceiptDto.php
│   │   ├── MatchOutcomeDto.php                     # success | unmatched | skipped + parsed?
│   │   ├── MatcherInputDto.php                     # union of InboxMessageDto + FileImportDto
│   │   └── ChainHintPayload/
│   │       ├── FundedByCardPayload.php             # typed sub-DTOs per hint_type
│   │       └── RefundOfPayload.php
│   ├── Events/
│   │   ├── ChainHintDetected.php
│   │   └── ReceiptConflictDetected.php
│   ├── Actions/
│   │   └── RecordReceipt.php                       # entrypoint: matcher consumer + wizard
│   └── Services/
│       └── FileImportQuery.php                     # wizard preview + drawer queries
├── Internal/
│   ├── Matchers/
│   │   ├── PaypalReceiptMatcher.php
│   │   ├── IcsReceiptMatcher.php
│   │   └── GooglePlayReceiptMatcher.php
│   ├── MatcherRegistry.php                         # injects 'receipts.matcher' tagged collection
│   ├── Pipeline/
│   │   ├── EmlMimeReader.php                       # zbateson wrapper for bodies + attachments
│   │   ├── MboxIterator.php                        # hand-rolled streaming iterator
│   │   ├── ReceiptSourceAdapter.php                # ParsedReceiptDto → SourceTransactionDto
│   │   └── ReceiptHeaderProfile.php                # HeaderSniffer arm for .eml/.mbox
│   ├── Jobs/
│   │   ├── ProcessFetchedInboxMessagesJob.php      # walks status='fetched'
│   │   └── ScanInboxDropFolderJob.php              # watched-folder scanner (D-704 secondary)
│   ├── Http/
│   │   ├── Livewire/
│   │   │   └── WizardEmailFileStep.php             # /imports wizard arm
│   │   └── Controllers/
│   │       └── (none — wizard handles upload)
│   └── Console/
│       └── (schedule registered in routes/console.php)
├── Resources/
│   └── views/
│       └── livewire/
│           └── wizard-email-file-step.blade.php
├── Routes/
│   ├── web.php                                     # (likely empty — wizard step is Livewire)
│   └── console.php                                 # ScanInboxDropFolderJob schedule
└── tests/
    ├── Pest.php                                    # inert per project convention
    ├── TestCase.php
    ├── Unit/
    │   ├── Matchers/
    │   │   ├── PaypalReceiptMatcherTest.php
    │   │   ├── IcsReceiptMatcherTest.php
    │   │   └── GooglePlayReceiptMatcherTest.php
    │   ├── EmlMimeReaderTest.php
    │   └── MboxIteratorTest.php
    ├── Feature/
    │   ├── EmlFileDropTest.php
    │   ├── MboxFileDropTest.php
    │   ├── ProcessFetchedInboxMessagesJobTest.php
    │   └── ReceiptCsvFingerprintParityTest.php     # the load-bearing invariant
    ├── Contracts/
    │   └── (added to tests/Contracts/IdempotencyContractTest.php dataset row)
    └── fixtures/
        ├── paypal/                                  # multiple template generations
        ├── ics/
        ├── google-play/
        └── mbox/
            ├── small-multi-message.mbox
            └── synthetic-large.mbox                 # exercises streaming bound

Modules/Categorization/   (extended)
├── Database/Migrations/
│   └── XXXX_create_categorization_rules_table.php
├── Public/
│   ├── Actions/
│   │   ├── CreateCategorizationRule.php
│   │   ├── UpdateCategorizationRule.php
│   │   └── DeleteCategorizationRule.php
│   ├── Dto/
│   │   ├── CategorizationRuleDto.php
│   │   └── AutoCategorizationOutcomeDto.php
│   └── Services/
│       └── CategorizationRuleQuery.php
├── Internal/
│   ├── Services/
│   │   └── RuleEvaluator.php
│   ├── Listeners/
│   │   └── MerchantMemoryWriter.php                # TransactionCategorized
│   ├── Pipeline/
│   │   └── ApplyAutoCategoryStage.php              # runs after NormalizeStage
│   └── Http/Livewire/
│       ├── RulesPage.php
│       └── RuleFormModal.php
├── Resources/views/livewire/
│   ├── rules-page.blade.php
│   └── rule-form-modal.blade.php
└── Routes/web.php  (extended with /rules)
```

### Pattern 1: Container-tagged matcher registry (D-702 / D-716)

**What:** Service provider tags every matcher class with `'receipts.matcher'`; the registry resolves the tagged collection on construct and sorts by `priority()`. `canHandle()` filters; first match wins.

**When to use:** Phase 7 matcher dispatch — adding a fourth merchant later (Amazon, Bol.com) means one new class + one `tag()` call.

**Example:**
```php
// Source: Laravel 13 Service Container docs (laravel.com/docs/13.x/container)
// Modules/Receipts/Providers/ReceiptsServiceProvider.php
public function register(): void
{
    $this->app->tag([
        PaypalReceiptMatcher::class,
        IcsReceiptMatcher::class,
        GooglePlayReceiptMatcher::class,
    ], 'receipts.matcher');

    $this->app->singleton(MatcherRegistry::class, static function (Container $app): MatcherRegistry {
        /** @var iterable<SenderMatcher> $tagged */
        $tagged = $app->tagged('receipts.matcher');
        $matchers = iterator_to_array($tagged);
        usort($matchers, static fn (SenderMatcher $a, SenderMatcher $b) => $b->priority() <=> $a->priority());
        return new MatcherRegistry($matchers);
    });
}
```

**Priority + canHandle semantics (D-716 recommendation):**

- `canHandle()` is the authoritative filter — if it returns `false`, that matcher is skipped regardless of priority.
- `priority()` orders matchers when multiple `canHandle()` claims could overlap (e.g., a future `genericReceiptMatcher` fallback would have `priority() = 0`; sender-specific matchers have `priority() = 100`).
- Higher priority wins. The first matcher in the sorted list whose `canHandle()` returns `true` is invoked; the rest are skipped.
- If zero matchers claim a message: status transitions to `'unmatched'` (eligible for re-parse later).
- If a matcher claims but `match()` returns `MatchOutcomeDto::skipped()`: status transitions to `'skipped'` (e.g., "PayPal login from new device" notification).
- Recommended initial priorities: PayPal = 100, ICS = 100, Google Play = 100 (no overlap risk in v1; container-order tiebreaker is acceptable since `canHandle()` keys on sender domain).

`[VERIFIED: laravel.com/docs/13.x/container — tag() / tagged() API]`

### Pattern 2: ParsedReceiptDto → SourceTransactionDto bridge

**What:** `ReceiptSourceAdapter` is one method that maps the matcher's structured output into the `SourceTransactionDto` shape the locked pipeline already consumes. The bridge is the load-bearing contract: receipt-derived `SourceTransactionDto`s MUST hash to the same fingerprint as their CSV-derived twins for cross-format dedup (D-706) to work.

**Field mapping:**

| ParsedReceiptDto field | SourceTransactionDto field | Notes |
|------------------------|----------------------------|-------|
| `merchantName` (string, raw from receipt) | `counterpartyName` | NormalizeStage runs FingerprintComposer::normalize() on this — receipt's cleaner merchant name often outranks CSV's `NETFLIX.COM` |
| `amountMinor` (int, from `brick/money` parse) | `amountMinor` | MUST be EUR-equivalent if PayPal's settled-EUR is present; otherwise native currency from receipt |
| `currency` (3-letter ISO) | `currency` | USD for Google Play, EUR for PayPal/ICS in EUR-native case |
| `settledAmountMinor` (?int) | `settledAmountMinor` | When matcher extracts both legs (PayPal foreign-currency receipt, Google Play USD-charged-against-EUR-card) |
| `settledCurrency` (?string) | `settledCurrency` | EUR for the FX cases |
| `referenceId` (?string) | `sourceRef` AND eventually `transaction.reference_id` | PayPal Transaction ID / Google Play Order ID (GPA.X-X-X-X) / ICS reference — when present |
| `bookedAt` (CarbonImmutable, from receipt date or header Date) | `bookedAt` + `postedAt` + `valueDate` | All three same for receipts (no booked-vs-posted lag like banks) |
| `ownIban` | `ownIban` | `'PAYPAL'` synthetic for PayPal receipts; `'ICS-CARD'` for ICS receipts; per-receipt account synthetic for Google Play (`'GOOGLE-PLAY'`?) |
| `counterpartyIban` | `counterpartyIban` | Usually `null` for receipts (the merchant doesn't appear by IBAN) |
| `description` (?string) | `description` | Item description from receipt body |
| `rawPayload` (array) | `rawPayload` | Keep the source matcher_key + receipt's raw extracted fields for audit |
| `sourceRowIndex` | `sourceRowIndex` | `0` for single-receipt; incrementing for multi-item Google Play receipts |
| matcher_key (e.g., `'paypal-receipt'`) | `sourceFormat` (passed to NormalizeStage) | Flows through as `transactions.source_format` for audit-link UX |

**Currency rounding via brick/money:**
```php
// Source: brick/money README + Phase 3 NormalizeStage precedent
use Brick\Money\Money;
use Brick\Math\RoundingMode;

$money = Money::of('12.99', 'EUR', roundingMode: RoundingMode::HALF_UP);
$amountMinor = $money->getMinorAmount()->toInt();   // 1299
```

**Fingerprint-parity test pattern (load-bearing per "Specific Ideas" section of CONTEXT.md):**

```php
// Wave 0 / Wave 1 — Modules/Receipts/tests/Feature/ReceiptCsvFingerprintParityTest.php
it('produces the same fingerprint for a PayPal CSV row and its matching .eml receipt', function (): void {
    // Arrange: anonymised PayPal CSV row whose Transaction ID matches the anonymised .eml
    $csv = parseFixtureCsvRow('Modules/Receipts/tests/fixtures/paypal/paired-csv.csv');
    $eml = file_get_contents('Modules/Receipts/tests/fixtures/paypal/paired-receipt.eml');

    // Act
    $csvCanonical = $this->normalize->run($csv, $accountId, $user, $importRunId, 'paypal-csv');
    $matcher = $this->app->make(PaypalReceiptMatcher::class);
    $outcome = $matcher->match($eml);
    $receiptSource = (new ReceiptSourceAdapter)->toSourceDto($outcome->parsed);
    $receiptCanonical = $this->normalize->run($receiptSource, $accountId, $user, $importRunId, 'paypal-receipt');

    // Assert: same fingerprint = cross-format dedup will collapse them automatically
    $composer = $this->app->make(FingerprintComposer::class);
    expect($composer->compose($receiptCanonical))->toBe($composer->compose($csvCanonical));
});
```

### Pattern 3: `.mbox` streaming iterator (hand-rolled)

**What:** Line-by-line state machine that yields one `.eml` blob per message, never loading the whole file into memory. Implements the `From ` line escape per **mboxrd** (Rahul Dhesi variant, the de facto modern standard).

**When to use:** Every `.mbox` ingestion. Multi-GB iCloud / Fastmail exports MUST never blow memory.

**Algorithm:**
```
state = 'between_messages'
buffer = ''
foreach line in file:                    # PHP fgets() — buffered line read
    if line starts with 'From ' AND state == 'between_messages':
        # New message starts
        yield buffer (if non-empty)
        buffer = ''
        state = 'in_message'
        # Skip the 'From ' separator line — it's not part of the message
        continue
    elif line starts with 'From ' AND state == 'in_message':
        # 'From ' line WITHIN a message body — should be escaped as '>From '
        # In mboxrd: any line starting with '>*From ' is escaped; strip ONE leading '>'
        # But this case (raw 'From ' inside body) means we're at the start of a NEW message
        yield buffer
        buffer = ''
        continue                          # skip the separator
    elif line starts with '>' AND mboxrd_unescape:
        # mboxrd: strip ONE '>' if the rest matches '>*From '
        if matches('^>+From '):
            line = substr(line, 1)        # strip ONE '>'
    buffer .= line

# Tail
if buffer non-empty:
    yield buffer
```

**Position tracking for resumability:** Each yield returns `['eml' => $buffer, 'byte_offset' => ftell($fh), 'message_index' => $n]`. The `file_imports` row stores `byte_offset` so a long mbox re-import can resume after a crash (planner can lock this if scope allows; otherwise just re-iterate from byte 0 — same `Message-ID` UNIQUE drops duplicates).

**Edge cases the iterator MUST handle:**

1. **File starts mid-line (no leading `From `)** — treat as continuation of a single-message file; yield once at EOF.
2. **CRLF vs LF line endings** — `fgets()` returns the line including its terminator; pass through verbatim so zbateson sees the same bytes the producer wrote.
3. **8-bit body content** — binary attachments encoded base64; the iterator is byte-oriented, not text-oriented (use `fread`/`fgets` on a binary-opened stream `fopen($p, 'rb')`).
4. **Empty messages** — possible from buggy producers; skip silently rather than yield an empty `.eml`.
5. **Very long lines** — RFC 5322 caps headers at 998 octets but bodies can have arbitrary line lengths; `fgets()` with a `length=8192` buffer handles this with multiple reads concatenated.

**Why not `armin/mbox-parser`?** `[VERIFIED: composer require --dry-run]` It requires `zbateson/mail-mime-parser ^2.2`; project is locked on `4.0.1`. SAT-solver failure. Hand-rolling is ~60 lines and gives us total control over the streaming contract.

**References:**
- mboxrd format spec: [fileformats.archiveteam.org/wiki/Mbox](http://fileformats.archiveteam.org/wiki/Mbox)
- RFC 4155 (mbox MIME registration): mbox treats the `From ` separator as a delimiter; `>` is the escape char

### Pattern 4: `ApplyAutoCategoryStage` placement (D-719 — synchronous, in-pipeline)

**What:** A new pipeline stage that runs after `NormalizeStage` (and after `ClassifyTransactionType`), before `FingerprintStage::classify`. Looks up matching `categorization_rules` and `merchant_memories` for the canonical row's user + merchant; if a match wins per D-711 specificity scoring, the canonical row's `category_id` is set BEFORE persistence.

**Where exactly in `ImportPipeline::preview`:**

```php
// Modules/Import/Internal/Pipeline/ImportPipeline.php (after Phase 7)
$normalized = $this->normalize->run($source, $accountId, $user, $importRunId, $sourceFormat);
$normalized = $this->classifier->run($normalized, $user);

// NEW: auto-categorize before fingerprint classification so the canonical
// row written by RecordTransactions already carries category_id.
$autoOutcome = $this->autoCategory->apply($normalized, $user);
$normalized = $autoOutcome->canonical;   // mutated canonical with category_id set

// Existing: fingerprint stage continues unchanged
$disposition = $this->fingerprint->classify($normalized, $user);
```

`ApplyAutoCategoryStage::apply()` returns `AutoCategorizationOutcomeDto { canonical: CanonicalTransaction, provenance: 'rule'|'memory'|null, ruleId?: int, memoryId?: int }`. The provenance is buffered in the preview cache alongside the canonical batch so `RecordTransactions` can stamp it onto a new column `transactions.auto_category_provenance` (JSON: `{source, rule_id?, memory_id?}`) — which D-712's correction-divergence toast then reads to render the "Rule that fired" panel.

**Sync vs async (D-719 recommendation):** **Synchronous, in-pipeline.** Rationale:

| Aspect | Synchronous (recommended) | Asynchronous (rejected) |
|--------|---------------------------|--------------------------|
| Test determinism | High — preview shows category at preview time | Low — wizard preview lacks category, surprises user |
| Pipeline simplicity | High — one more stage in the existing line | Adds `CategorizeAutomatically` queued job + listener wiring |
| Throughput on large backfills | Acceptable — rule eval is cheap (indexed) | Marginally better but irrelevant at single-user scale |
| Failure mode | Stage failure bubbles up like NormalizeStage failures (single ERROR preview row) | Job failure = silently uncategorized rows + retry plumbing |
| Provenance audit trail | Set at write time | Race: row inserted before category written |

`ApplyAutoCategoryStage` precedent in the codebase: `ClassifyTransactionType` (Phase 4 04-03) sits in the exact same pipeline slot — synchronous, between `NormalizeStage` and `FingerprintStage`. Phase 7 mirrors that shape.

### Pattern 5: Correction-divergence UX (D-712)

**Toast (moment-of-action) — reuses Phase 4/5 pattern:**

```php
// In Modules/Categorization/Internal/Http/Livewire/TransactionDetail or InlineCategoryPicker
public function reclassify(int $newCategoryId, AssignsCategory $assign, CurrentUser $user): void
{
    $oldProvenance = $this->transaction->auto_category_provenance ?? null;   // read JSON
    ($assign)($this->transaction->id, $newCategoryId, $user->user());

    if ($oldProvenance !== null && $oldProvenance['source'] === 'rule') {
        $this->dispatch('rule-divergence-toast', payload: [
            'ruleId' => $oldProvenance['rule_id'],
            'newCategoryId' => $newCategoryId,
            'message' => "Rule 'merchant contains SPOTIFY → Streaming' suggested Streaming. Update rule to 'Other Subscriptions' for future matches?",
        ]);
    }
}
```

```html
{{-- Alpine listener already established in Phase 4 04-04 + Phase 5 05-05b --}}
<div x-data="{ toast: null }" x-on:rule-divergence-toast.window="toast = $event.detail">
    <template x-if="toast">
        <div class="toast">
            <span x-text="toast.message"></span>
            <button wire:click="updateRule(toast.ruleId, toast.newCategoryId)">Update rule</button>
            <button @click="toast = null">Keep current rule</button>
        </div>
    </template>
</div>
```

**One-shot per rule:** A `dismissed_rule_divergence_toasts` session-only set (Livewire ephemeral property) prevents the same `ruleId` from re-firing the toast within the same Livewire component lifecycle. After a fresh page load it's available again — intentional: if the user reclassifies the same merchant a second time, the prompt should re-fire.

**Drawer inline panel (always-available):** Rendered inside `TransactionDetail` Blade whenever `$tx->auto_category_provenance['source'] === 'rule'`:

```html
<flux:card subtle class="mt-4">
    <div class="flex items-center justify-between">
        <span class="text-sm text-slate-600">
            Rule that fired: contains <span class="font-medium">SPOTIFY</span> → Streaming
        </span>
        <div class="flex gap-2">
            <flux:button size="xs" wire:click="updateRuleForCurrentCategory">Update</flux:button>
            <flux:button size="xs" variant="ghost" wire:click="removeRule">Remove</flux:button>
        </div>
    </div>
</flux:card>
```

**Memory-driven suggestion panel (lighter, no toast):** Same component family but reads `provenance.source === 'memory'`:

```html
<flux:card subtle class="mt-4">
    <span class="text-sm text-slate-600">Auto-categorized from merchant history</span>
    <flux:button size="xs" variant="ghost" wire:click="forgetMemory">Override</flux:button>
</flux:card>
```

### Pattern 6: `/rules` page Flux components (D-713 / D-720)

**Route:**
```php
// Modules/Categorization/Routes/web.php (extended)
Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/uncategorized', 'categorization::triage')->name('uncategorized');
    Route::view('/rules', 'categorization::rules')->name('rules');     // NEW
});
```

**Page wrapper Blade (mirrors triage.blade.php):**
```html
@extends('layouts.app')
@section('content')
    @livewire(\Modules\Categorization\Internal\Http\Livewire\RulesPage::class)
@endsection
```

**RulesPage Livewire SFC (skeleton):**
- Table columns: `field`, `match`, `value`, `category` (path), `hits_count`, `created_at`, actions.
- "New rule" button → opens `RuleFormModal` via `$this->dispatch('modal-show', {modal: 'rule-form'})`.
- Per-row edit → opens same modal pre-populated.
- Per-row delete → `wire:click` calls `DeleteCategorizationRule` action.

**Flux components (UI-SPEC plan-phase pass will lock the exact set):**

- `<flux:table>` + `<flux:column>` + `<flux:cell>` for the rule list (mirrors `/chains/review` queue layout).
- `<flux:modal name="rule-form">` for the New/Edit form.
- `<flux:select wire:model="field">` (merchant/description/counterparty), `<flux:select wire:model="match">` (contains/equals/starts_with), `<flux:input wire:model="value">`, `<flux:select wire:model="categoryId">` (sourced from `CategoryOptionsQuery`, same shape as `InlineCategoryPicker`).
- `<flux:dropdown>` per-row actions (Edit, Delete).
- Empty state when no rules exist: hero card with explainer + "New rule" CTA.

**Top-nav placement (D-721):** Add `<a href="{{ route('rules') }}">Rules</a>` to `Modules/Core/Resources/views/livewire/top-nav.blade.php`, sibling to `Uncategorized` (the two are categorization-adjacent). UI-SPEC pass during plan-phase may collapse `Rules` + `Uncategorized` + `Review chains` under a "Triage" dropdown if the bar overcrowds.

### Pattern 7: Watched-folder secondary path (D-704 / D-718)

**Cadence:** 5 minutes (D-718 default) is the right starting choice. The watched folder is a developer/power-user convenience; 5-minute latency is acceptable for "drop and forget" workflow. Sub-minute polling would risk file-system race conditions during big mbox writes (file moved to `inbox-drop/` while still being written by the source app).

**Registration in `routes/console.php`** (existing project pattern):

```php
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Illuminate\Console\Scheduling\Schedule;

Schedule::job(new ScanInboxDropFolderJob)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)       // 10-minute lock TTL — survives a stuck scan
    ->onOneServer();
```

**Processed/ semantics:** After a file is successfully ingested, it's moved (via PHP `rename()`, which is atomic on same filesystem) to `storage/app/inbox-drop/processed/{YYYY-MM}/{original-filename}`. Failed parses move to `inbox-drop/failed/{YYYY-MM}/` with a sibling `.error.txt` containing the exception message. Re-import is just "move file out of `processed/` back into `inbox-drop/`".

**`/settings` toggle pattern:** Mirror Phase 3 `default_currency_view`:

1. Add column: `users.watched_folder_enabled` boolean default `false`.
2. SettingsPage Livewire SFC gains a `<flux:switch wire:model.live="watchedFolderEnabled">` row.
3. `ScanInboxDropFolderJob::handle()` first checks `User::where('watched_folder_enabled', true)->cursor()` and processes per-user-scoped subfolders (`inbox-drop/{user_id}/...`) — multi-user safe per FND-03.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| MIME parsing (multipart, boundaries, encoded-words, quoted-printable, base64, charset conversion) | A regex-based header/body splitter | `zbateson/mail-mime-parser 4.0` (already installed via Phase 6) | 51M downloads, 10 years maintained. Charset edge cases alone (iso-8859-1, windows-1252, mojibake from forwarded receipts, ko-kr ascii-bombs) eat weeks. |
| Money parsing (`€12,99` Dutch locale, `$12.99` US, `1.234,56` thousand separators) | `(int)((float) $str * 100)` | `Brick\Money\Money::of($str, $currency, RoundingMode::HALF_UP)` then `->getMinorAmount()->toInt()` | Float coercion silently loses cents on values like `1.10` → `109`. brick/money is already locked. |
| Fingerprint composition | Re-derive the v3 tuple in the matcher | `Modules\Ledger\Public\Services\FingerprintComposer::compose()` (already locked, Phase 2 D-32) | The whole "receipts dedupe against CSVs" payoff comes from sharing this exact composition. Re-deriving = drift = silent duplicates. |
| Cross-format dedup logic | Custom "if matcher_key + ref equals" check | `FingerprintStage::classify()` returning `EnrichedDisposition` (Phase 2 D-32 / Wave 3) | Already handles rank function (`asn-camt053 > asn-mt940 > asn-csv`; planner adds `paypal-receipt`/`ics-receipt`/`google-play-receipt` rank values). |
| Currency rounding | `round($cents)` then cast to int | `RoundingMode::HALF_UP` on `Brick\Math\BigDecimal` | Banker's rounding vs half-up matters on hundreds of micro-charges per year. Pick once, lock once. |
| RFC 2047 encoded-word decoding (`=?UTF-8?Q?...?=` in Subject) | `iconv_mime_decode()` | zbateson's `getHeaderValue('Subject')` already decodes | Edge cases around malformed Q-encoded runs are exactly what zbateson exists to handle. |
| `.eml` extraction of date → `internalDate` | Custom `strtotime()` on Date header | Existing `Modules/EmailScan/Internal/MimeHeaderParser::parseHeadersWithFallbackDate()` — already shipped | Phase 6 already solved this; reuse don't rebuild. |
| Hash function selection for synthetic message-id | xxhash via ext-xxhash | `hash('sha256', ...)` (built-in, FIPS-compliant) | sha256 is built-in; 32-byte hex output fits the existing 128-char `provider_message_id` column with margin. Cryptographic collision resistance is overkill but free. |
| Rule storage schema | Custom JSON-blob rule definitions | Explicit columns `field`, `match`, `value` (D-709 shape) | Indexable; PHPStan can type-narrow each enum; UI form is trivial. |
| Mbox parsing | (rejected: `armin/mbox-parser`) | Hand-roll a 60-line streaming iterator | The library is INCOMPATIBLE with locked `zbateson 4.0`. Hand-rolling is cheaper than version-locking ourselves out of zbateson's v4 improvements. |

**Key insight:** Phase 7 is structurally "another arm of the SourceAdapter pipeline." The matcher is the ONLY new domain logic; everything else (idempotency, dedup, normalisation, fingerprinting, chain-resolution, multi-currency, multi-user isolation) is already locked and battle-tested. Hand-rolling any of those would re-introduce solved bugs.

## Runtime State Inventory

Phase 7 is greenfield-on-top-of-existing schema — no rename/refactor/migration aspect. **Section omitted (greenfield phase).**

## Common Pitfalls

### Pitfall 1: Receipt fingerprint drifts from its CSV twin

**What goes wrong:** PayPal receipt parser writes `counterpartyName = 'Netflix BV'` but PayPal CSV parser writes `counterpartyName = 'Netflix.com'`. Both go through `FingerprintComposer::normalize()` but the normalization output differs (Dutch BV suffix vs `.com` TLD) — fingerprints diverge — same transaction appears twice in `/transactions`.

**Why it happens:** Email receipts have *cleaner* merchant names than CSVs (that's the whole point of D-707). But cleaner ≠ identical post-normalization.

**How to avoid:**
- Ship the `ReceiptCsvFingerprintParityTest` (see Pattern 2) in Wave 0 — it asserts that for at least one synthesised paired CSV row + `.eml`, `FingerprintComposer::compose()` returns the same hash. The test forces matcher authors to think about how `normalize()` collapses `Netflix BV` vs `Netflix.com`.
- The CONTEXT.md "Specific Ideas" section flags this as the load-bearing invariant.
- When the test fails, the fix is in the *matcher* (extract the same merchant string the CSV produces) OR in `FingerprintComposer::normalize()` (collapse `BV`/`Inc`/`Ltd`/`.com` trailing tokens) — the latter requires a `NORMALIZATION_VERSION` bump + `diederik:rederive-fingerprints` re-run.

**Warning signs:**
- `/transactions` shows two rows for the same Netflix charge after both the bank CSV and the receipt are imported.
- `import_runs.duplicate_count` stays at zero when re-importing a receipt that should clearly be a duplicate of an already-imported CSV row.

### Pitfall 2: Multipart/alternative ordering

**What goes wrong:** PayPal receipts ship as `multipart/alternative` with both `text/plain` and `text/html` parts. A naive matcher reads `multipart->getPart(0)` and assumes that's the body — but the order is not guaranteed; some clients put HTML first, some put plain first. The matcher reads HTML when plain was available and trips on inline CSS/styling noise.

**Why it happens:** RFC 2046 says the LAST part of `multipart/alternative` is the richest; zbateson's `getTextContent()` / `getHtmlContent()` are the proper accessors.

**How to avoid:**
- **Always prefer `text/plain` when present**, fall back to `text/html` only when missing (per CONTEXT.md "Risks" section).
- Use zbateson's `getTextContent()` first; if `null`, fall back to `getHtmlContent()` and strip tags + decode entities.
- Wave 0 fixtures must include AT LEAST one `multipart/alternative` receipt with plain-first ordering AND one with html-first ordering.

**Warning signs:** Matcher silently extracts wrong merchant from an inline CSS class name.

### Pitfall 3: Charset / encoding pollution from forwarded receipts

**What goes wrong:** Receipt arrives in `iso-8859-15` (Western European); user forwards it through their mail client which converts to `utf-8` but appends a quoted preamble in their own charset; the result is a multipart message with mixed charsets. Matcher reads body bytes as utf-8 and gets mojibake on the `€` symbol.

**Why it happens:** Email clients are notoriously sloppy about charset preservation through forwarding chains.

**How to avoid:**
- zbateson normalises every part to UTF-8 internally when you call `getTextContent()` — trust that interface.
- Never call `mb_convert_encoding()` in matcher code; zbateson already did it.
- Matchers should match on amount as `12,99` (Dutch locale) OR `12.99` OR `€12,99` — accept the lexical variations, not just one charset.

**Warning signs:** PHP exceptions from `BigDecimal::of()` because the amount string contains `\xE2\x82\xAC` (broken `€`) glued to the digits.

### Pitfall 4: Sender-domain spoofing

**What goes wrong:** A phishing email forged with `From: service@paypal.com.attacker.example` slips past `canHandle()` if the matcher does `str_contains($from, 'paypal.com')`.

**Why it happens:** Sender-email checking with substring contains is famously insecure. `[CITED: malwarebytes.com — recent PayPal email spoofing campaigns 2025](https://www.malwarebytes.com/blog/news/2025/12/paypal-closes-loophole-that-let-scammers-send-real-emails-with-fake-purchase-notices)`

**How to avoid:**
- `canHandle()` should use an **exact suffix match on the email domain part**: `$emailDomain = substr(strrchr($from, '@'), 1); return $emailDomain === 'paypal.com';` (or in the `@*.paypal.com` allowed-subdomain case, validate via `preg_match('/^([a-z0-9-]+\.)?paypal\.com$/i', $emailDomain)`).
- DKIM/SPF verification is OUT OF SCOPE — diederik trusts that Phase 6's Gmail/Graph fetchers only return real provider messages (Gmail's `q=from:paypal.com` filter already DKIM-validates server-side). For the `.eml`/`.mbox` file-drop path, the trust model is "user dropped these files; we trust them" — but matchers should still domain-match strictly to avoid mis-parsing arbitrary newsletters.
- Wave 0 fixtures should include ONE spoofed-from-look-alike receipt to assert it's correctly rejected by `canHandle()`.

**Warning signs:** Matcher claims unexpected senders; `inbox_messages.matcher_key` is unexpectedly populated for non-receipt traffic.

### Pitfall 5: Rule + memory drift on rule deletion (CONTEXT.md flagged)

**What goes wrong:** User creates a rule "contains SPOTIFY → Streaming"; 6 months later they delete the rule expecting Spotify charges to become uncategorized again. But `merchant_memories` quietly accumulated `(Spotify_merchant_id, Streaming)` rows during those 6 months — Spotify charges keep auto-categorizing as Streaming.

**Why it happens:** D-711 explicitly says memory grows even when a rule wins. This is intentional (memory survives rule deletion) but it surprises users.

**How to avoid:**
- Render a doc note on `/rules` header: "Deleting a rule doesn't clear what was learned from past categorizations."
- Optionally: when deleting a rule, surface a one-shot prompt "Also clear merchant memory for matched merchants?" — but this is a Wave 4 UX nicety, not a v1 requirement.

**Warning signs:** Users complain about "deleted rule still working".

### Pitfall 6: Large `.mbox` blows memory

**What goes wrong:** 5-year iCloud export → 8 GB `.mbox`. Naive `file_get_contents()` in the iterator → PHP OOM.

**Why it happens:** Bulk exports concatenate years of attachments inline.

**How to avoid:**
- `MboxIterator` MUST use `fopen()` + `fgets()` (or `fread()` in 8 KB chunks) — never `file_get_contents()`.
- Wave 0 ships a deliberately-large synthetic `.mbox` (a few hundred MB of repeated synthetic receipts) and a Pest test that asserts peak memory stays under 64 MB during full iteration.
- The `ProcessFetchedInboxMessagesJob` consumer processes one message at a time and persists each to disk before moving to the next — never accumulates parsed receipts in memory.

**Warning signs:** `php artisan` OOM on `.mbox` re-import; PHP `memory_limit` notices in logs.

### Pitfall 7: `pending_enrichment_conflicts` orphan rows

**What goes wrong:** First-conflict toast fires; user closes the browser tab without choosing. The `pending_enrichment_conflicts` row accumulates. Next conflict for a different receipt also writes a row. Over time, the table grows unbounded.

**How to avoid:**
- TTL the rows: any conflict row older than 30 days is GC-able by a daily scheduled cleanup task.
- The toast text MUST make clear "this will keep asking until you choose" so the user knows dismissal isn't action.
- Conflict rows are scoped to `(user_id, transaction_id, field_name)` UNIQUE — re-conflict on the same (transaction, field) is idempotent (no duplicate rows).

**Warning signs:** `pending_enrichment_conflicts` row count grows monotonically with no bound.

### Pitfall 8: Synthetic `Message-ID` hash collision across mboxes

**What goes wrong:** Two different `.eml` files have identical bytes (e.g., user has the same receipt forwarded twice from two devices). Synthetic `Message-ID = sha256(bytes)` is identical. The second drop is a no-op. The user expects two `file_imports` rows; they get one.

**Why it happens:** D-705a is explicit: identical bytes = same `provider_message_id` = idempotent re-drop. This is correct behavior but counter-intuitive.

**How to avoid:** Document this in the watched-folder docs. The behavior IS the correctness model — "same bytes = same parse = no-op" is exactly what idempotency means. Add a per-file `source_filename` audit column so the user can at least see which filename was first ingested.

**Warning signs:** None — this is intentional behavior. Just be ready to explain it.

## Code Examples

Verified patterns from official sources:

### Container tagging (Laravel 13)

```php
// Source: https://laravel.com/docs/13.x/container#tagging
$this->app->tag([CpuReport::class, MemoryReport::class], 'reports');

$reports = $this->app->tagged('reports');
foreach ($reports as $report) { /* ... */ }
```

### zbateson body extraction (text/plain preferred)

```php
// Source: https://mail-mime-parser.org/
use ZBateson\MailMimeParser\MailMimeParser;

$message = (new MailMimeParser)->parse($emlRaw, true);
$body = $message->getTextContent();            // prefers text/plain
if ($body === null || $body === '') {
    $html = $message->getHtmlContent();
    $body = strip_tags(html_entity_decode($html ?? '', ENT_QUOTES | ENT_HTML5));
}
```

### Money parsing with Dutch locale (`12,99`)

```php
// Source: https://github.com/brick/money — README + project's PaypalAmountParser precedent
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

// Receipt body literal: "€12,99"
$raw = '12,99';                              // already stripped of currency symbol
$normalized = str_replace(',', '.', $raw);   // Dutch comma → US dot
$money = Money::of($normalized, 'EUR', roundingMode: RoundingMode::HALF_UP);
$minor = $money->getMinorAmount()->toInt();  // 1299
```

### Hand-rolled MboxIterator (skeleton)

```php
// Source: mboxrd spec — fileformats.archiveteam.org/wiki/Mbox + RFC 4155
/**
 * @return Generator<int, array{eml: string, byteOffset: int, index: int}>
 */
public function iterate(string $mboxPath): Generator
{
    $fh = fopen($mboxPath, 'rb');
    if ($fh === false) {
        throw new RuntimeException("Cannot open mbox: {$mboxPath}");
    }
    try {
        $buffer = '';
        $index = 0;
        $offset = 0;
        $inMessage = false;
        while (($line = fgets($fh)) !== false) {
            if (str_starts_with($line, 'From ')) {
                if ($inMessage && $buffer !== '') {
                    yield ['eml' => $buffer, 'byteOffset' => $offset, 'index' => $index++];
                }
                $buffer = '';
                $offset = ftell($fh);
                $inMessage = true;
                continue;        // separator line, not part of the message
            }
            // mboxrd unescape: strip one leading '>' from '>+From ' lines
            if ($inMessage && preg_match('/^>+From /', $line) === 1) {
                $line = substr($line, 1);
            }
            $buffer .= $line;
        }
        if ($inMessage && $buffer !== '') {
            yield ['eml' => $buffer, 'byteOffset' => $offset, 'index' => $index];
        }
    } finally {
        fclose($fh);
    }
}
```

### `categorization_rules` schema (suggested)

```php
// Source: D-709 + D-722 + existing Modules/Ledger/Database/Migrations/*_create_merchant_memories_table.php precedent
Schema::create('categorization_rules', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
    $table->string('field', 16);                 // 'merchant'|'description'|'counterparty'
    $table->string('match', 16);                 // 'contains'|'equals'|'starts_with'
    $table->string('value');                     // case-insensitive at evaluation time
    $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
    $table->unsignedInteger('hits_count')->default(0);
    $table->boolean('active')->default(true);    // D-722 nicety
    $table->text('notes')->nullable();           // D-722 nicety
    $table->timestamps();

    $table->unique(['user_id', 'field', 'match', 'value']);
    $table->index(['user_id', 'active']);        // RuleEvaluator hot-path
});

// Paired BEFORE INSERT / BEFORE UPDATE triggers on `field` and `match` enums
// (mirrors Phase 6 inbox_messages.status trigger pattern)
```

### `pending_enrichment_conflicts` schema (D-715 recommendation)

```php
// Source: D-707 + D-715 — recommended shape (NEW table, not column on PendingEnrichment DTO)
Schema::create('pending_enrichment_conflicts', static function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
    $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
    $table->string('field_name', 64);            // which column conflicted
    $table->text('stored_value')->nullable();    // current value in transactions
    $table->text('incoming_value')->nullable();  // receipt's competing value
    $table->string('incoming_source_format', 32); // e.g. 'paypal-receipt'
    $table->foreignId('import_run_id')->nullable()->constrained('import_runs')->nullOnDelete();
    $table->timestamps();

    $table->unique(['user_id', 'transaction_id', 'field_name']);  // re-conflict idempotent
    $table->index(['user_id', 'created_at']);                     // TTL sweep
});
```

## Per-Sender Matcher Patterns

> **Confidence: MEDIUM-LOW for exact template anchors.** Web search for "PayPal receipt HTML 2025 template" returns marketing/scam-warning material, not concrete template anchors. CONTEXT.md "Risks" section flags this exact concern: "PayPal redesigns its receipt HTML periodically." **Wave 0 MUST mine anchors from real anonymised inbox fixtures on the developer's machine** — this research can only sketch the shape.

### PayPal Receipt Matcher

**Sender domain (canHandle):** `@paypal.com` (exact match; service@paypal.com is canonical) `[CITED: paypal.com/us/cshelp]`. Some Dutch users may receive from `@paypal.nl` — confirm during Wave 0 fixture mining.

**Subject patterns (English + Dutch):**
- EN: "You sent a payment of ..." / "Receipt for your payment to ..." / "Your receipt from ..."
- NL: "Je hebt een betaling gedaan van ..." / "Je ontvangstbewijs van ..."

**Header anchors:**
- `From: service@paypal.com` (exact)
- `Subject:` matches one of the patterns above (regex tolerant)
- `List-Unsubscribe:` header presence is a useful "is this a receipt vs a marketing email" tiebreaker

**Body anchors (text/plain preferred, derived from PayPal receipt structure):**
- `Transaction ID:` followed by 17-char alphanumeric `[A-Z0-9]{17}` `[CITED: paypalobjects.com transaction_id_format]`
- Amount line: contains currency symbol + amount; layout differs EN vs NL but the pair `(currency_token, amount_decimal)` is the extraction target
- `Merchant:` / `To:` / `Paid to:` / `Aan:` (Dutch) — followed by merchant name on next line or same line

**Known template drift:**
- PayPal redesigns receipt HTML every 6-12 months (CONTEXT.md flagged).
- Fees/holds/refunds may roll into a single receipt or arrive as separate notifications — matcher should treat each as a single transaction (no PayPal CSV-style rollup; the CSV rollup is the ledger's source of truth for fee/hold detail).
- Currency conversion: foreign-currency payment receipts include both legs ("You paid $12.99 USD (€12.07 EUR)"). Matcher should extract BOTH; emit `amountMinor`+`currency` = native ($12.99 USD) and `settledAmountMinor`+`settledCurrency` = (€12.07 EUR) per Phase 3 D-42 pattern.

**Fallback strategy when matcher loses confidence:**
- If `Transaction ID` not found: return `MatchOutcomeDto::unmatched()` → `inbox_messages.status = 'unmatched'` → user re-parses after matcher fix.
- If amount/currency not extractable: same. Better to surface failure than guess.
- Notification-only emails (login from new device, password change): match the sender + a "login alert" subject signature → return `MatchOutcomeDto::skipped()` → status = `'skipped'`.

### ICS Cards Receipt Matcher

**Sender domain:** `@ics.nl` OR `@icscards.nl` `[CITED: D-120 seed list — Phase 6 known_senders migration]`

**Subject patterns (Dutch only):**
- "Aankoopnotificatie" (purchase notification)
- "Transactiemelding"
- "Je transactie van [merchant]"

**Header anchors:**
- `From:` containing `@ics.nl` or `@icscards.nl` (suffix-match, NOT contains, per Pitfall 4)

**Body anchors (mostly text/html — ICS receipts are HTML-heavy):**
- Merchant name in a `<td class="merchant">` or similar table cell — extract via stripped-text regex
- Amount in `€` + decimal — Dutch locale comma decimal (`€12,99`)
- Card last-4 digits: `eindigend op 1234` or `kaart **** 1234` — this is the CRITICAL chain hint
- Reference number / authorization code — often shorter than PayPal's (4-6 chars); use for `reference_id` even though it's less unique

**ICS-specific chain hint (D-708 ChainHintDetected payload):**
```
hint_type: 'funded_by_card'
hint_payload: { card_last4: '1234' }
evidence: 'eindigend op 1234'
```
The Chains listener uses `card_last4` to identify which ICS-CARD synthetic-IBAN account the charge funds.

**Known template drift:** Less aggressive than PayPal (ICS is a Dutch bank, low UX change cadence). Per CONTEXT.md user-memory `ICS Cards consumer portal is PDF-only` — the same applies to email; receipts are HTML-rich, no API surface.

**Fallback strategy:**
- Some ICS receipts arrive as PDF attachments rather than HTML body (CONTEXT.md "Deferred" flags PDF receipts as Phase 7 v1 OUT OF SCOPE) → `MatchOutcomeDto::unmatched()` for now.
- If amount missing: `unmatched`, status flag for re-parse.

### Google Play Receipt Matcher

**Sender domain:** `googleplay-noreply@google.com` (exact) `[CITED: support.google.com — confirmation emails from noreply@google.com / googleplay-noreply@google.com]`

**Subject patterns:**
- "Your Google Play Order Receipt from [Date]"
- "Your Google Play Order Receipt"
- "Your subscription with [App Name]"

**Header anchors:**
- `From: googleplay-noreply@google.com` (exact equality)

**Body anchors (text/plain preferred):**
- Order ID format: `GPA.\d{4}-\d{4}-\d{4}-\d{5}` `[CITED: support.google.com — order numbers begin with GPA]`
- Charge amount in USD (most Google Play receipts are USD even for EU users) — extracts native + (sometimes) settled EUR
- Item name + price — extracted as `description` + `amountMinor`
- "Total" line vs per-item lines — matcher should extract the `Total` row (one transaction per receipt), not per-line items

**Currency edge case:** Google Play charges in USD against an EU EUR card. Matcher emits `amountMinor=1299, currency='USD'`. The ICS Cards CSV/PDF for the same charge shows the settled EUR amount. Cross-format dedup via FingerprintComposer v3 will NOT collapse these (the `currency` field differs and is part of the fingerprint tuple) — this is the intentional model per Phase 3 D-42 (the receipt and the CSV/PDF settle row are distinct ledger entries; the chain resolver later links them via the ICS Cards charge sequence).

**Fallback strategy:**
- Missing `GPA.X-X-X-X` Order ID → `unmatched`.
- Refund receipts (`"Your Google Play refund"`) — distinct subject; matcher should handle as a second receipt-type with negative amount, or emit `unmatched` and defer to v2.

### Matcher robustness budget (per CONTEXT.md "Risks" section)

Each matcher MUST ship with anonymised fixtures covering AT LEAST:
- 1× current-generation template (whatever Wave 0 mines from real inbox)
- 1× prior-generation template (best-effort; ask the user to provide an older receipt if available)
- 1× edge-case (notification-only message, foreign-currency, refund)
- 1× spoofed-from-look-alike negative-case (must NOT match)

Matcher test layout:
```
Modules/Receipts/tests/Unit/Matchers/PaypalReceiptMatcherTest.php
  it('claims a current PayPal receipt')
  it('claims a prior-generation PayPal receipt')
  it('extracts merchant + amount + Transaction ID')
  it('emits settled-EUR alongside native USD for foreign-currency receipts')
  it('skips PayPal login alert emails')
  it('rejects spoofed paypal.com.attacker.example sender')
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `php-imap` + `webklex/laravel-imap` for everything | Provider APIs (Gmail/Graph) + zbateson for `.eml` files | PHP 8.4 unbundled `ext-imap` (2024); project pre-empted with `PLT-05` invariant | Receipt matchers consume `.eml` bytes directly via zbateson; no IMAP wire-level work needed in Phase 7 |
| YAML/regex template configs | Per-sender PHP classes | D-702 / project's typed-DTO style | PHPStan level max can type-narrow matcher outputs; per-sender bug fixes are isolated |
| Synchronous OAuth-protected fetcher per request | Queued Horizon-supervised consumer job | Phase 5 introduced Horizon + Redis | `ProcessFetchedInboxMessagesJob` runs as a worker, not in a wizard request — wizard preview-only path for file drop remains synchronous |

**Deprecated/outdated:**
- `php-imap-mailbox` (Sergey Linnik's IMAP wrapper): unmaintained; would still pull `ext-imap` regardless. Pre-empted by PLT-05.
- Per-receipt LLM-based categorization (e.g., GPT classifies "what is this purchase?"): OUT OF SCOPE per REQUIREMENTS.md "Hard Exclusions" — privacy + cold-start accuracy concerns.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | PayPal Dutch users receive from `service@paypal.com` (not `@paypal.nl`) | Per-Sender Matcher Patterns — PayPal | Matcher `canHandle()` rejects valid Dutch receipts; mitigated by Wave 0 fixture mining from real inbox |
| A2 | ICS Cards receipts in HTML body (not PDF attachment only) | Per-Sender Matcher Patterns — ICS | If all ICS receipts are PDF-only, Phase 7 v1 effectively has no ICS matcher; status falls to PDF-receipt OCR (deferred v2). Wave 0 fixture mining confirms |
| A3 | Google Play receipts are always for one Order ID per email (not bulk daily digests) | Per-Sender Matcher Patterns — Google Play | If bulk digests exist, matcher needs multi-transaction-per-receipt extraction; not currently scoped |
| A4 | RFC 822 `Message-ID` is reliably present for receipts from API-fetched sources (Gmail/Graph) | D-705a synthetic fallback only applies to file-drop with missing header | If Gmail/Graph receipts ever lack Message-ID, the synthetic-hash path activates; behavior is correct, just untested for API path |
| A5 | `armin/mbox-parser` still requires `zbateson ^2.2` as of latest release | Standard Stack — Alternatives | Verified via `composer require --dry-run` 2026-05-17; if armin/mbox-parser is upgraded to support zbateson 4.x, we could re-evaluate. Low risk — hand-rolled iterator is small |
| A6 | Specificity scores 100/90/50+len/10+len in D-711 produce intuitive UX outcomes | Pattern 5 + D-711 | If user-perceived ordering disagrees with the scoring, planner tunes the constants. The scoring is the abstraction worth getting wrong cheaply |
| A7 | Watched-folder 5-minute cadence is acceptable latency for "drop and forget" | Pattern 7 / D-718 | If users complain about lag, planner reduces to 1 minute — schedule change only |
| A8 | `users.receipt_conflict_resolution` enum sufficient (no per-field granularity in v1) | D-707 / Pattern 5 | If user needs "prefer receipt for merchant-name only but prefer CSV for description", that's a v2 feature; CONTEXT.md explicitly defers |
| A9 | New `pending_enrichment_conflicts` table is the right shape (NOT a column on the existing `pending_enrichments` DTO — which is in-memory only, no table exists) | D-715 — Pending-Conflict Storage | The existing `PendingEnrichment` is a DTO buffered in the preview cache, not a persisted row. Conflicts must persist beyond the preview window (the toast may fire post-confirm). A new table is correct |
| A10 | Top-nav can absorb a 6th item ("Rules") without UI-SPEC redesign | Pattern 6 / D-721 | If UI-SPEC pass decides 6 is too many, planner introduces a "Triage" or "Categorize" submenu — minor refactor |
| A11 | Per-sender template anchors documented in "Per-Sender Matcher Patterns" are approximately correct for current PayPal/ICS/Google Play templates | Per-Sender Matcher Patterns | Templates drift; Wave 0 MUST mine fresh fixtures from a real inbox. The patterns sketch is a starting point, not a contract |

## Open Questions

1. **Exact PayPal/ICS/Google Play receipt anchors for current templates**
   - What we know: sender domains are stable; reference-ID formats (PayPal `[A-Z0-9]{17}`, Google Play `GPA.X-X-X-X`) are documented.
   - What's unclear: exact body markup, table cell selectors, label text for "Merchant"/"Amount"/"Transaction ID" lines, Dutch vs English variants per sender.
   - Recommendation: Wave 0 anonymisation script (mirroring Phase 3 `scripts/anonymize_ics_text.php` and Phase 4 `scripts/anonymize_paypal_csv.php`) that the user runs on their own real inbox, committing the redacted `.eml` files to `Modules/Receipts/tests/fixtures/`. Same precedent as every prior phase's Wave 0.

2. **Conflict-resolution UX wording**
   - What we know: D-707 mandates a binary "prefer_receipt | prefer_first_write" toggle stored on `users`.
   - What's unclear: exact toast copy + whether the user can later revisit the choice from `/settings`.
   - Recommendation: UI-SPEC pass during plan-phase locks the copy + `/settings` row.

3. **Chain hint event payload sub-DTO design**
   - What we know: D-708 names two initial `hint_type` values: `'funded_by_card'` and `'refund_of'`.
   - What's unclear: whether sub-DTO payloads should be Spatie DTOs (typed) or arrays (flexible). Likely Spatie DTOs per project style, with one class per `hint_type`.
   - Recommendation: Spatie DTOs (e.g., `Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload`). Modules/Chains listener type-narrows via `match($hintType)` and the event payload's `match` returns the correctly-typed sub-DTO.

4. **Should `ApplyAutoCategoryStage` apply to ALL sources retroactively?**
   - What we know: Per D-710, the stage runs uniformly — so future CSV imports also benefit from rules.
   - What's unclear: Should a one-shot artisan command `diederik:auto-categorize-existing` re-run rules over ALREADY-imported transactions? This is the "re-parse on rule change" feature CONTEXT.md defers to v2.
   - Recommendation: NOT in Phase 7. Add to deferred items.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `zbateson/mail-mime-parser` | EmlMimeReader, ParsedMessageHeaders | ✓ | `4.0.1` | — (locked in composer.lock; no fallback needed) |
| PHP 8.5 | Project baseline | ✓ | `^8.5` (composer.json) | — |
| `brick/money` | Amount parsing | ✓ | `^0.11` | — |
| `pestphp/pest` | Test infrastructure | ✓ | `^4.0` | — |
| Redis | Queue infrastructure (`ProcessFetchedInboxMessagesJob`) | ✓ (Phase 5 introduced) | Docker container | — |
| macOS `launchd` | Watched-folder schedule (Phase 6 plists run scheduler) | ✓ (Phase 6) | — | If launchd not installed, watched folder simply doesn't run; wizard path remains functional |
| `armin/mbox-parser` | (rejected) | ✗ | — | Hand-roll `MboxIterator` (chosen) |

**Missing dependencies with no fallback:** None — every external dependency is already in the project.

**Missing dependencies with fallback:** `armin/mbox-parser` is rejected; hand-rolled iterator is the chosen path (not a fallback in the strict sense, but the same outcome).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.0 (on PHPUnit 11) + pest-plugin-arch 4.0 + pest-plugin-snapshots 2.0 |
| Config file | `phpunit.xml` (root) + per-module `tests/Pest.php` (inert) + root `tests/Pest.php` (load-bearing) |
| Quick run command | `vendor/bin/pest --filter='Receipts' --parallel` |
| Full suite command | `composer test` (= `pest --parallel`) — runs all module suites + tests/Contracts/ |
| Static analysis | `composer analyse` (= `phpstan analyse --memory-limit=1G`) at level max with strict + larastan-strict + larastan-livewire |
| Code style | `composer format:check` (Pint, default Laravel preset) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|--------------|
| EML-05 | PayPalReceiptMatcher extracts merchant/amount/currency/ref-ID from a current `.eml` | unit | `pest Modules/Receipts/tests/Unit/Matchers/PaypalReceiptMatcherTest.php` | ❌ Wave 0 (matcher + fixtures) |
| EML-05 | IcsReceiptMatcher same | unit | `pest Modules/Receipts/tests/Unit/Matchers/IcsReceiptMatcherTest.php` | ❌ Wave 0 |
| EML-05 | GooglePlayReceiptMatcher same | unit | `pest Modules/Receipts/tests/Unit/Matchers/GooglePlayReceiptMatcherTest.php` | ❌ Wave 0 |
| EML-05 | Receipt-derived transactions hash to same fingerprint as CSV-derived (cross-format dedup) | feature | `pest Modules/Receipts/tests/Feature/ReceiptCsvFingerprintParityTest.php` | ❌ Wave 0 |
| EML-05 | `inbox_messages.status` transitions `fetched → parsed/skipped/unmatched` after matcher run | feature | `pest Modules/Receipts/tests/Feature/ProcessFetchedInboxMessagesJobTest.php` | ❌ Wave 1 |
| EML-05 | `transaction.reference_id` populated triggers Phase 5 `ResolveChainLinksJob` automatically | feature | `pest Modules/Receipts/tests/Feature/ChainHintFromReceiptTest.php` | ❌ Wave 2 |
| EML-07 | `.eml` file drop via `/imports` wizard creates `file_imports` row + canonical transaction | feature | `pest Modules/Receipts/tests/Feature/EmlFileDropTest.php` | ❌ Wave 1 |
| EML-07 | `.mbox` file drop iterates messages and creates N `file_imports` rows | feature | `pest Modules/Receipts/tests/Feature/MboxFileDropTest.php` | ❌ Wave 1 |
| EML-07 | Same `.eml` re-dropped is no-op (synthetic Message-ID idempotency) | contracts | `pest tests/Contracts/IdempotencyContractTest.php --filter='eml'` | ❌ Wave 1 (add dataset row) |
| EML-07 | MboxIterator handles multi-GB file without OOM | unit | `pest Modules/Receipts/tests/Unit/MboxIteratorTest.php --filter='large'` | ❌ Wave 0 |
| CAT-02 | `MerchantMemoryWriter` grows `merchant_memories` after `TransactionCategorized` | feature | `pest Modules/Categorization/tests/Feature/MerchantMemoryWriterTest.php` | ❌ Wave 3 |
| CAT-02 | `ApplyAutoCategoryStage` sets `category_id` from memory match at import time | feature | `pest Modules/Categorization/tests/Feature/ApplyAutoCategoryStageTest.php` | ❌ Wave 3 |
| CAT-04 | User creates a rule via `/rules` page; rule appears in table | feature | `pest Modules/Categorization/tests/Feature/RulesPageTest.php` | ❌ Wave 4 |
| CAT-04 | Rule with `match='contains'` `value='SPOTIFY'` fires on next import | feature | `pest Modules/Categorization/tests/Feature/RuleEvaluatorTest.php` | ❌ Wave 4 |
| CAT-04 | Specificity scoring picks `equals` over `contains` over memory per D-711 | unit | `pest Modules/Categorization/tests/Unit/RuleEvaluatorTest.php` | ❌ Wave 4 |
| CAT-04 | Correction-divergence toast fires when reclassifying a rule-provenance transaction | feature | `pest Modules/Categorization/tests/Feature/CorrectionDivergenceTest.php` | ❌ Wave 4 |
| CAT-04 | Drawer inline panel renders when `auto_category_provenance.source = 'rule'` | feature | `pest Modules/Ledger/tests/Feature/TransactionDetailRulePanelTest.php` | ❌ Wave 4 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter='<changed-module>' --parallel` (Receipts or Categorization scope, ~5-10 seconds)
- **Per wave merge:** `composer test` (full suite, ~60 seconds at current scale)
- **Phase gate:** `composer test && composer analyse && composer format:check` all green before `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `Modules/Receipts/composer.json` — new module manifest (mirrors `Modules/Chains/composer.json`)
- [ ] `Modules/Receipts/Providers/ReceiptsServiceProvider.php` — registers `MatcherRegistry`, tags matchers, loads migrations + routes
- [ ] `Modules/Receipts/tests/Pest.php` + `tests/TestCase.php` — inert per project convention
- [ ] `tests/Pest.php` (root) — add `'Modules/Receipts' => Modules\Receipts\Tests\TestCase::class` to the foreach map
- [ ] `phpunit.xml` — add `Modules/Receipts/tests/Unit` and `Modules/Receipts/tests/Feature` directories to existing Unit + Feature testsuites
- [ ] `composer.json` autoload-dev — add `"Modules\\Receipts\\Tests\\": "Modules/Receipts/tests/"` (3-step pattern per Phase 4 D-80b)
- [ ] `Modules/Receipts/tests/fixtures/paypal/` + `ics/` + `google-play/` + `mbox/` — anonymised real receipts (the developer mines from their own inbox)
- [ ] `scripts/anonymize_eml.php` — anonymisation script (mirroring Phase 3 + 4)
- [ ] `tests/Contracts/BoundaryArchTest.php` — add `Modules\Receipts\Internal` containment rule + `noEmailFetchFromReceipts` invariant + facade carve-outs

## Security Domain

> `security_enforcement` defaults to enabled. Phase 7 owns sensitive surfaces: user-uploaded `.eml`/`.mbox` files, rule string-matching against external content, and cross-format dedup data flow.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|------------------|
| V1 Architecture | yes | BoundaryArchTest invariants (D-701 + D-132 carry-forward); Public/Internal split; DI-only |
| V2 Authentication | yes (inherited) | Phase 1 LoopbackOnly + Fortify auth gates `/rules` + wizard + watched folder |
| V3 Session Management | yes (inherited) | Laravel session driver; no Phase 7 additions |
| V4 Access Control | yes | Every Phase 7 query filters by `user_id` (cross-user 404 invariant + FND-03 BelongsToUser) |
| V5 Input Validation | yes | Rule `value` string accepts arbitrary user input — stored verbatim, matched via case-insensitive `LIKE` with `%` escape on user input; `.eml` upload bounded by Livewire MAX upload size (planner sets ~20 MB for `.eml`, 1 GB for `.mbox`) |
| V6 Cryptography | yes | `sha256()` for synthetic Message-ID (PHP built-in); APP_KEY for Laravel session/cookie crypto (inherited) — never hand-roll |
| V7 Error Handling | yes | Failed matcher → `unmatched` status + structured log entry; never crash the consumer job |
| V8 Data Protection | yes | `.eml` blobs live in `storage/app/inbox/...` outside web-accessible paths; chmod 700 on `inbox/` directory (mirrors Phase 6 chmod-600 for secrets) |
| V10 Malicious Code | yes | Matcher MUST NOT `eval()` or `include()` matched content; rule `value` is opaque string never executed |
| V12 File Upload | yes | Livewire `MAX_SIZE` enforced; declared MIME types `.eml` + `.mbox`; HeaderSniffer validates first-bytes signature; uploaded files stored outside webroot |
| V13 API & Web Services | yes (inherited) | All `/rules` actions CSRF-protected via Laravel default |

### Known Threat Patterns for PHP/Laravel/Email-Receipt Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Spoofed sender domain (`paypal.com.attacker.example`) | Spoofing | Exact suffix-match on email domain (NOT `str_contains`); Pitfall 4 |
| Mailbox bomb (zip-bomb `.mbox` inflating to TB at parse time) | DoS | Streaming `MboxIterator` (never load whole file); per-message size cap; PHP `memory_limit` as hard ceiling |
| Malicious `.eml` payload (XXE in embedded HTML, JS in body) | Tampering | zbateson normalises to text; matchers never render HTML; rule evaluation is string `LIKE`, no template parsing |
| Path traversal via `source_filename` (`../../etc/passwd`) | Tampering | `basename()` filter on upload-derived filenames; deterministic storage path uses `message_id_hash`, never the user-supplied filename |
| SQL injection via rule `value` field | Tampering | Eloquent + parameterised query bindings (Laravel default); rule `value` always bound, never interpolated |
| Cross-user data leak in `RuleEvaluator` | Information Disclosure | `where('user_id', $user->id)` on every rule query; cross-user Pest test (mirroring Phase 3-07 / 4-04 / 5-04 pattern) |
| Stored XSS in rule `value` rendered to `/rules` page | Tampering | Blade `{{ }}` auto-escapes; never use `{!! !!}` for rule values |
| Time-based fingerprint manipulation | Tampering | FingerprintComposer v3 uses booked_at at second resolution; receipt's bookedAt sourced from receipt body or `internalDate` — bounded by Phase 6 fetch flow |
| `eval()` / `include()` of receipt content | Code Execution | NEVER pass receipt content to dynamic execution; matchers MUST treat content as data |

## Sources

### Primary (HIGH confidence)
- Project `composer.lock` — verified `zbateson/mail-mime-parser 4.0.1` already installed
- Project `composer.json` — verified all dependencies (brick/money ^0.11, spatie/laravel-data ^4.0, livewire ^4.0, flux ^2.0, pestphp ^4.0, larastan ^3.0)
- `.planning/phases/06-email-receipt-ingestion-infrastructure/06-CONTEXT.md` — Phase 6 handoff contract (inbox_messages.status='fetched' rows + on-disk paths)
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — Phase 5 ResolveChainLinksJob + TransactionImported event + reference_id pickup
- `Modules/EmailScan/Public/Services/InboxMessageQuery.php` — verified generator-based streaming interface
- `Modules/EmailScan/Internal/MimeHeaderParser.php` — zbateson wrapper precedent (Phase 6)
- `Modules/Categorization/Public/Events/TransactionCategorized.php` — event the MerchantMemoryWriter listens to
- `Modules/Ledger/Public/Services/FingerprintComposer.php` — locked v3 normalization algorithm
- `Modules/Import/Internal/Pipeline/ImportPipeline.php` + `Modules/Import/Public/Actions/ConfirmImport.php` — the locked pipeline shape
- `Modules/Ingestion/Public/Dto/SourceTransactionDto.php` — the bridge target shape
- `Modules/Ingestion/Providers/IngestionServiceProvider.php` — registry pattern precedent (direct map, not tagged — Phase 7 elects tagging for matcher extensibility)
- `tests/Contracts/BoundaryArchTest.php` — the invariant suite Phase 7 extends
- [Laravel 13 Container docs (laravel.com/docs/13.x/container)](https://laravel.com/docs/13.x/container) — verified `tag()` and `tagged()` API
- [zbateson/mail-mime-parser 4.0 upgrade docs (mail-mime-parser.org/upgrade-4.0)](https://mail-mime-parser.org/upgrade-4.0) — confirmed PHP 8.1–8.5 compatibility
- [Packagist: zbateson/mail-mime-parser](https://packagist.org/packages/zbateson/mail-mime-parser) — 51.2M downloads, 1.2M/month, 10-year active
- Slopcheck verification: `slopcheck install -e packagist zbateson/mail-mime-parser armin/mbox-parser` returned `[OK]` for both

### Secondary (MEDIUM confidence)
- [Mbox format reference (fileformats.archiveteam.org/wiki/Mbox)](http://fileformats.archiveteam.org/wiki/Mbox) — mboxrd `From ` escape semantics
- [mbox-parser PHP library (github.com/a-r-m-i-n/mbox-parser)](https://github.com/a-r-m-i-n/mbox-parser) — confirmed incompatible with zbateson 4.x via composer SAT resolve
- [PayPal transaction ID format (paypalobjects.com)](https://www.paypalobjects.com/en_US/vhelp/paypalmanager_help/transaction_id_format.htm) — 17-char alphanumeric format
- [Google Play order number format (support.google.com)](https://support.google.com/googleplay/answer/2850369) — `GPA.X-X-X-X-X` pattern
- [ICS Cards customer info (icscards.nl)](https://www.icscards.nl/) — sender domain confirmation

### Tertiary (LOW confidence — Wave 0 must verify against real fixtures)
- Exact PayPal receipt HTML body anchors — search returns marketing material; CONTEXT.md flags template drift as load-bearing risk
- Exact ICS Cards Dutch email body markup — sparse public info; Wave 0 fixture mining required
- Exact Google Play receipt HTML structure — same caveat
- [Malwarebytes / PayPal email spoofing 2025 (malwarebytes.com)](https://www.malwarebytes.com/blog/news/2025/12/paypal-closes-loophole-that-let-scammers-send-real-emails-with-fake-purchase-notices) — context for Pitfall 4 (domain spoofing risk)

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — every library already locked in `composer.lock`; zbateson 4.0 explicitly tested on PHP 8.5
- Architecture: HIGH — pipeline is locked from Phase 2–5; Phase 7 plugs in one more SourceAdapter arm
- Pitfalls: HIGH for general pitfalls (fingerprint drift, multipart ordering, mbox memory); MEDIUM for per-sender anchor drift (templates change)
- Per-sender template anchors: MEDIUM-LOW — Wave 0 fixture mining required
- Validation architecture: HIGH — test infrastructure is mature (Pest 4 + module-local TestCase + Contracts suite)
- Security: HIGH — ASVS categories and threat patterns are well-understood for this stack

**Research date:** 2026-05-17
**Valid until:** 2026-06-14 (30 days for stable areas; 7 days for per-sender template anchors which drift — Wave 0 fixture mining resets that clock for matcher work)
