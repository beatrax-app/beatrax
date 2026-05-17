# Phase 7: Email Template Matchers + Categorization Learning - Context

**Gathered:** 2026-05-17
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 7 ships the receipt-to-transaction layer plus the categorization-learning brain. The deliverable is: per-sender PHP matchers that parse `.eml` blobs into canonical transactions for PayPal, ICS Cards, and Google Play; an `.eml` / `.mbox` drop-in path that runs through the same matcher pipeline (covering iCloud, Fastmail, and any provider without an API); a per-merchant memory write-side that auto-suggests categories from history; and a user-defined rule engine that pre-categorizes at import with a correction-divergence feedback loop.

**What Phase 7 delivers (vertical):**
- A new `Modules/Receipts/` bounded module owning: a `SenderMatcher` interface, per-sender PHP classes (`PaypalReceiptMatcher`, `IcsReceiptMatcher`, `GooglePlayReceiptMatcher`) registered via container tagging, a `MatcherRegistry`, and the `.eml`/`.mbox` ingestion pipeline.
- A `ParsedReceiptDto` → `SourceTransactionDto` adapter that feeds the **existing** SourceAdapter pipeline (Phase 1–5): `NormalizeStage` → `FingerprintComposer v3` → `ApplyEnrichments` (cross-format dedup) → `RecordTransactions`. The matcher path is one more `SourceAdapter` arm next to ASN CSV / CAMT.053 / MT940 / PayPal CSV / ICS PDF.
- A consumer that walks `inbox_messages.status='fetched'` rows from Phase 6 (via `Modules/EmailScan/Public/Services/InboxMessageQuery`), runs the matchers, and transitions status to `parsed` / `skipped` / `unmatched` while populating `inbox_messages.matcher_key`.
- A new `file_imports` table (mirroring the `inbox_messages` shape) for `.eml` / `.mbox` content that arrived via file drop rather than API. Same `fetched` → `parsed` / `skipped` / `unmatched` lifecycle, same on-disk `.eml` blob path (under `storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/`), same re-parse semantics.
- A wizard-primary drop-in path: a new "Email file (.eml/.mbox)" issuer option on the existing `/imports` wizard with a `HeaderSniffer` arm detecting RFC 822 / mbox-format, preview-then-confirm flow, and idempotency via RFC 822 `Message-ID`.
- An optional watched-folder secondary path under `storage/app/inbox-drop/` driven by a scheduled job that scans every 5 minutes and moves processed files to `/processed/{YYYY-MM}/`. Default off, surfaced as a `/settings` toggle.
- An expanded `Modules/Categorization/` owning: a new `categorization_rules` table + CRUD action, a rule-evaluation service, a `MerchantMemoryWriter` listener (grows `merchant_memories` from `TransactionCategorized` events), and a new `ApplyAutoCategoryStage` that runs inside the import pipeline.
- A `/rules` top-nav page hosting rule CRUD with the field-selector + match-type + value form.
- A correction-divergence UX: when the user reclassifies a transaction whose initial suggestion came from a rule, a toast surfaces an offer to update or keep the rule, AND the transaction detail drawer carries an always-visible "Rule that fired: [...] [Update] [Remove]" inline panel.
- A per-user `receipt_conflict_resolution` setting populated by the first-conflict toast (D-707) so subsequent fingerprint conflicts apply the chosen policy automatically.
- Chain-hint emission: matchers populate `transaction.reference_id` for the common case (Phase 5's existing `ResolveChainLinksJob` picks them up via the `TransactionImported` event with zero new code in Chains), and additionally emit a typed `ChainHintDetected` event when a matcher extracts richer cross-source clues (e.g. "funded by ICS card ending 1234").

**What Phase 7 does NOT deliver:**
- Email connection / OAuth / fetch / `.eml` persistence from APIs — owned by Phase 6 (the handoff is `inbox_messages.status='fetched'` rows + on-disk `.eml` paths).
- Recurring detection (Phase 8) — the merchant_memories table grown by Phase 7 is consumed by Phase 8's clustering, but Phase 7 stops at "suggest a category for a single transaction."
- Regex / DSL / multi-condition rules — v2.
- Push-style real-time receipt arrival — Phase 6 polling cadence is what surfaces new receipts to Phase 7's consumer.
- Modifying Phase 6's `inbox_messages` table shape beyond reading + transitioning the existing `status` enum and writing `matcher_key`.

**Architectural anchor:**
Phase 7's matcher pipeline reuses the locked Phase 1–5 SourceAdapter pipeline rather than reinventing idempotency, normalization, or fingerprinting. Every receipt-derived transaction is fingerprint-equivalent to its CSV-derived twin, which is what makes cross-format dedup (Phase 2 Wave 3) work without per-source policy.

</domain>

<decisions>
## Implementation Decisions

### Matcher Architecture & Module Home

- **D-701:** **New `Modules/Receipts/` bounded module.** Mirrors the `Modules/Transfers/` + `Modules/Chains/` precedent — cross-cutting capability gets its own module. Owns matchers, the matcher registry, the `.eml`/`.mbox` ingestion path, the `ParsedReceiptDto` → `SourceTransactionDto` adapter, and the consumer that walks Phase 6's `inbox_messages.status='fetched'` queue. Public/Internal split from day one. Cannot live in `Modules/EmailScan/` because Phase 6's `BoundaryArchTest::noTransactionWritesFromEmailScan` (D-132) forbids transaction writes from that module — Phase 7 is precisely the module that writes those transactions. A new `BoundaryArchTest::noEmailFetchFromReceipts` invariant flips the symmetry: `Modules/Receipts/` must never call `GmailApiClient` / `GraphApiClient` / OAuth code — it only reads `InboxMessageQuery` + the on-disk `.eml`. Public surface: `RecordReceipt` action (called by the consumer + the wizard drop-in path), `FileImportQuery` for the wizard preview, `SenderMatcher` contract + `MatcherRegistry` for future per-sender additions, `ParsedReceiptDto` + `MatchOutcomeDto` shapes.

- **D-702:** **Per-sender PHP class implementing `SenderMatcher`.** Each matcher (`PaypalReceiptMatcher`, `IcsReceiptMatcher`, `GooglePlayReceiptMatcher`) implements `canHandle(InboxMessageDto $m): bool` + `match(string $emlRaw): MatchOutcomeDto`. Registry collects them via container tagging (planner picks the exact tag — likely `'receipts.matcher'`). Each matcher fully unit-tested in isolation against anonymised `.eml` fixtures. Aligned with project DI-only invariant + typed-DTO style + Phase 4 `PaypalCsvAdapter` precedent. Config-driven YAML/regex templates were rejected: they make PHPStan level-10 type safety harder, can't express per-sender quirks (PayPal's fees/holds rollup, ICS's potential PDF-attachment receipts) without escape hatches, and step outside the project's typed-DTO style.

- **D-703:** **Receipt → Transaction reuses the existing SourceAdapter pipeline.** `ParsedReceiptDto` (matcher output) → `SourceTransactionDto` (adapter conversion) → `NormalizeStage` → `FingerprintComposer v3` → `FingerprintStage::classify` → `ApplyEnrichments` (cross-format dedup) → `RecordTransactions`. Receipt-derived transactions share the same fingerprint composition rules as CSV/CAMT/MT940, which is what makes the cross-format dedup (Phase 2 D-32 / Wave 3) just work. The matcher_key from `inbox_messages` flows through as the `source_kind` / `source_format` equivalent so `/transactions` audit links back to the originating `.eml`. A dedicated Receipts-only path that writes Transaction directly was rejected: it would reimplement idempotency + dedup and risk drift from the locked Phase 2 cross-format contract.

### .eml / .mbox Drop-In Entrypoint (EML-07)

- **D-704:** **Wizard primary + watched folder optional (off by default).** The `/imports` wizard gains a new "Email file (.eml/.mbox)" issuer option. `HeaderSniffer` learns a new arm that detects RFC 822 (`Return-Path:` / `Received:` header presence) and mbox format (`From ` line marker). Same preview-then-confirm flow as every other source. The watched folder under `storage/app/inbox-drop/` is the power-user secondary path: surfaced behind a `/settings` toggle (default off), driven by a new scheduled job (cadence: every 5 minutes), processed files move to `/processed/{YYYY-MM}/` so re-runs are idempotent. The wizard is the documented path because it's visible, idempotent, multi-user-safe, and reuses the existing import infrastructure.

- **D-705:** **Separate `file_imports` table — `inbox_messages` stays API-only.** Drop-in `.eml`/`.mbox` content creates rows in a new `file_imports` table whose shape mirrors `inbox_messages`: `id`, `user_id` (FND-03), `source_kind` enum (`'eml'` / `'mbox'`), `source_filename`, `provider_message_id` (RFC 822 `Message-ID` header; nullable when missing — see D-705a), `internal_date`, `sender_email`, `sender_name` (nullable), `subject` (nullable), `eml_path` (deterministic location under `storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/{message_id_hash}.eml`), `status` enum (`fetched` / `parsed` / `skipped` / `unmatched`), `fetched_at`, timestamps. `UNIQUE (user_id, provider_message_id)` is the wire-level idempotency contract for files-with-`Message-ID`; the user-isolation requirement is per FND-03. Keeps the Phase 6/Phase 7 boundary semantically clean: `inbox_messages` = API-fetched, `file_imports` = file-dropped. The matcher consumer reads from both tables (unified at the matcher level via `MatcherInputDto`). Idempotency on `.eml` re-drop = same `Message-ID` is a no-op; `.mbox` re-import iterates and lets the per-message `Message-ID` uniqueness handle dedup row-by-row.

- **D-705a:** **Synthetic Message-ID for files missing the header.** RFC 822 `Message-ID` is not mandatory; some forwarded receipts lose it. When the header is absent, the file-drop pipeline computes a synthetic `provider_message_id` = `sha256(eml_raw_bytes)`. Keeps the UNIQUE constraint workable and preserves byte-identical re-drop idempotency.

### Dedup & Fingerprint Conflicts

- **D-706:** **Cross-source fingerprint matches use the existing Phase 2 Wave 3 `ENRICHED` disposition.** When a receipt fingerprint equals a previously-imported CSV row (or vice versa), the FingerprintStage classifier returns `ENRICHED` and `ApplyEnrichments` fills nullable fields on the existing transaction rather than inserting a duplicate. This is the **default** behavior for the non-conflict case (when the second source only adds new data without overwriting). Receipts often arrive with richer data than CSVs (cleaner merchant names, full reference IDs, attachment hints); the conflict policy (D-707) governs the case where both sources have non-null values that disagree.

- **D-707:** **First-conflict toast → per-user setting → automatic application thereafter.** A new column `users.receipt_conflict_resolution` enum (`'unset'` default, `'prefer_receipt'`, `'prefer_first_write'`). When `ApplyEnrichments` detects a true field-value conflict and the user setting is `'unset'`, it: (a) holds the conflicting receipt-side value in a side channel (likely a `pending_enrichment_conflicts` table OR a transient field on the existing `pending_enrichments` table — planner picks the storage shape), (b) emits a `ReceiptConflictDetected` event, (c) the existing toast listener pattern (Phase 4/5 `$this->dispatch('toast', ...)` + Alpine `x-on:toast.window`) surfaces a one-time toast: *"Receipt has a cleaner merchant name ('Netflix BV') than the CSV ('NETFLIX.COM'). Use receipt data for future conflicts?"* with `[Use receipt]` `[Keep CSV]` actions. The user's answer is persisted as `receipt_conflict_resolution`. The current conflicting transaction is updated retroactively per the choice. From then on, the setting drives all conflicts without further prompting. Calm aesthetic preserved: single toast, never repeats. Per-field precedence map was considered + rejected for v1 (premature complexity).

- **D-708:** **Chain hints — reference_id always + structured event for rich hints.** Every matcher populates `transaction.reference_id` when extractable (PayPal Transaction ID, Google Play Order ID, ICS reference). Phase 5's existing `ResolveChainLinksJob` (already wired to `TransactionImported`) picks them up with zero new code in `Modules/Chains/`. **Additionally**, matchers MAY emit a typed `ChainHintDetected` event when they extract structured cross-source clues that don't fit in a single `reference_id` field (e.g., "funded by ICS card ending 1234" or "refunded order ABC") — `Modules/Chains/` listens and creates candidate `chain_links` rows eagerly. The event payload shape: `{ source_transaction_id, hint_type ('funded_by_card' | 'refund_of' | …), hint_payload (typed sub-DTO per hint_type), evidence (the raw string match) }`. Opt-in per matcher; not all matchers need it. New `Modules/Receipts/Public/Events/ChainHintDetected.php` is the cross-module surface.

### Categorization Learning (CAT-02 + CAT-04)

- **D-709:** **Rules = field-selector + match-type + value (no regex in v1).** Shape: `{ field: 'merchant' | 'description' | 'counterparty', match: 'contains' | 'equals' | 'starts_with', value: string, category_id }`. All matches case-insensitive. UI form is three dropdowns + a text input + a category picker. Non-technical-user-friendly. Covers "contains 'SPOTIFY' → Subscriptions / Streaming" cleanly. Multi-condition rules ("merchant contains X AND amount > Y") and regex are deferred to v2 — the schema reserves room (likely an explicit `match` enum that v2 can extend with `'regex'`).

- **D-710:** **Rule engine + memory-writer lives in `Modules/Categorization/`.** Categorization already owns `AssignCategory` + `CategoryOptionsQuery` + `TransactionCategorized` event. Phase 7 grows it with: a new `CategorizationRule` model + CRUD action, a `RuleEvaluator` service, a `MerchantMemoryWriter` listener that grows `merchant_memories` from `TransactionCategorized` events, and a new pipeline stage `ApplyAutoCategoryStage` that runs after `NormalizeStage` (so categorization happens uniformly to **every** source — CSV, CAMT, MT940, PayPal CSV, ICS PDF, AND email receipts). Rule logic stays in one module so a future v2 doesn't have to choose between "duplicate in Receipts" or "extract a third home."

- **D-711:** **Specificity-scored precedence with rule-then-memory tiebreaker.** Evaluation algorithm:
  1. Compute all candidate auto-categorizations: every matching `CategorizationRule` + the `merchant_memories` row for the transaction's `merchant_id` (if any).
  2. Score each candidate by specificity. Suggested scoring (planner can tune):
     - `match='equals'` on full merchant/description: 100
     - `merchant_memories` entry: 90 (semantically equivalent to "equals on merchant_id", slightly below an explicit equals-rule on description by intent)
     - `match='starts_with'`: 50 + len(value)
     - `match='contains'`: 10 + len(value)
  3. Highest score wins.
  4. **Tiebreaker:** rule beats memory (explicit intent beats learned behaviour at the same specificity).
  5. The auto-categorization is recorded with provenance (`source: 'rule' | 'memory'` + the matched rule_id or memory_id) so the correction-divergence flow (D-712) knows where the suggestion came from. **Merchant memory still grows even when a rule wins** so the memory remains valid if the user later deletes that rule.

- **D-712:** **Toast + inline drawer panel for correction divergence.** When the user reclassifies a transaction whose initial suggestion came from a rule (provenance recorded in D-711), two surfaces fire:
  - **Toast** immediately after save: *"Rule 'merchant contains SPOTIFY → Streaming' suggested Streaming. Update rule to use 'Other Subscriptions' for future matches? [Update rule] [Keep current rule]"*. Single dismissable toast, never repeats for the same rule unless reclassified again.
  - **Inline panel** in the `/transactions/{id}` detail drawer: an always-visible "Rule that fired: contains SPOTIFY → Streaming [Update] [Remove]" panel rendered whenever the current categorization provenance is `rule`. User who dismissed the toast can still take action from the drawer. Both surfaces call the same `UpdateCategorizationRule` action; the only difference is the dispatch trigger.
  - **Memory-driven suggestions** also surface a lighter inline panel ("Auto-categorized from merchant history [Override]") in the drawer; corrections-from-memory just decrement / replace the merchant_memories row via the existing `AssignCategory` flow with no toast (less interruption for the routine case).

- **D-713:** **New `/rules` top-nav page.** Dedicated route + Livewire SFC + page-level Blade wrapper, following the Phase 3 / Phase 5 `/settings` + `/chains/review` pattern. Sibling to `/transactions`, `/imports`, `/chains/review`, `/inboxes`, `/uncategorized`. Page contents: table of rules with field / match / value / category / hits-count / created columns, "New rule" button → modal with the D-709 form, per-row edit / delete actions. Hits-count is a denormalized counter on the `categorization_rules` row, incremented by `ApplyAutoCategoryStage` when a rule fires. Top-nav is getting crowded; UI-SPEC plan-phase pass owns positioning (likely under a "Categorize" submenu if the planner decides to group `/rules` + `/uncategorized`).

### Claude's Discretion

- **D-714:** Wave structure (suggested: Wave 0 = fixtures + module skeleton + arch tests + Pest registration; Wave 1 = PaypalReceiptMatcher vertical slice with file-drop entrypoint; Wave 2 = IcsReceiptMatcher + GooglePlayReceiptMatcher; Wave 3 = ApplyAutoCategoryStage + merchant_memories writer; Wave 4 = rules CRUD + /rules page + correction-divergence UX). Planner verifies against goal-backward analysis.
- **D-715:** Exact storage shape for the conflict-pending state in D-707 — either a new `pending_enrichment_conflicts` table or a transient field on the existing `pending_enrichments` table (Phase 2 Wave 3 introduced PendingEnrichment DTOs). Planner picks against existing precedent.
- **D-716:** Specific tag name for the matcher container binding (D-702: `'receipts.matcher'` suggested) + whether the registry sorts matchers by priority or treats `canHandle()` as authoritative when multiple match.
- **D-717:** Exact `provider_message_id` hash function for D-705a (sha256 suggested; xxhash or sha1 also acceptable trade-offs).
- **D-718:** Watched-folder cadence — 5 minutes suggested; planner can tune. Implementation = new scheduled job in `routes/console.php` per existing precedent.
- **D-719:** Whether `ApplyAutoCategoryStage` runs synchronously inside `RecordTransactions` (favors test determinism) or asynchronously via a `CategorizeAutomatically` queued job listening to `TransactionImported` (favors throughput on large backfills). Synchronous is the simpler default — defer to planner.
- **D-720:** UI-SPEC plan-phase pass locks the exact Flux components for `/rules` (table, modal, dropdowns) and the inline drawer panel.
- **D-721:** Top-nav grouping decision — if `/rules` + `/uncategorized` + `/chains/review` start crowding the bar, planner may introduce a "Categorize" or "Triage" submenu. UI-SPEC owns this.
- **D-722:** Whether the `categorization_rules` table supports an `active` boolean (toggle without delete) and a `notes` text field — UX nicety; planner decides if Wave 4 budget allows.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints. Critical here: DI-only invariant (constructor injection, no facades/helpers), `nwidart/laravel-modules` bounded modules with Public/Internal split, Larastan level 10 strict + Pint + Pest CI gates, calm aesthetic (Linear/Notion), GSD-agnostic code comments invariant.
- `.planning/REQUIREMENTS.md` — Phase 7 covers EML-05, EML-07, CAT-02, CAT-04. Adjacent (already-met) requirements that Phase 7 must respect: LED-01/02/03/04/05 (transactions + transfers + multi-currency + transfer-pair contract), CAT-01/03/05 (manual categorization + override + uncategorized triage), MC-01/02 (multi-currency display), FND-03 (per-user data isolation).
- `.planning/ROADMAP.md` §"Phase 7" — Goal + four success criteria (matcher → canonical transaction with chain hints, `.eml`/`.mbox` drop-in via same pipeline, per-merchant memory auto-suggest, user-defined rules with correction-update offer).

### Prior Phase Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, wizard preview-then-confirm pattern, BelongsToUser invariant, FingerprintComposer v1 origin, merchant_memories + merchants table introduction.
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-CONTEXT.md` — FingerprintComposer v3 (drop source_ref, add booked_at), cross-format dedup mechanism (FingerprintStage::classify + ENRICHED disposition + ApplyEnrichments + pending_enrichments + PendingEnrichment DTO), HeaderSniffer pattern, SourceAdapterRegistry. **The locked dedup contract Phase 7 reuses.**
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — `/settings` page extension pattern + Route::view + Livewire SFC convention (relevant to D-704 watched-folder toggle + D-713 `/rules` page).
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — `Modules/Transfers/` Public/Internal module shape that `Modules/Receipts/` mirrors. PaypalCsvAdapter precedent for sender-specific parsers. BoundaryArchTest invariant pattern.
- `.planning/phases/05-chain-resolution-paypal-funding-ics-bulk-ideal-decomposition/05-CONTEXT.md` — Horizon + Redis queue infrastructure inherited. ChainLinks public surface that D-708 ChainHintDetected event feeds. Failed-job toast pattern (Phase 4/5) that D-707 first-conflict + D-712 correction-divergence both mirror. View Factory composer pattern.
- `.planning/phases/06-email-receipt-ingestion-infrastructure/06-CONTEXT.md` — **Required read.** The Phase 6/Phase 7 handoff contract is `inbox_messages.status='fetched'` rows + on-disk `.eml` paths + `matcher_key` nullable column. `Modules/EmailScan/Public/Services/InboxMessageQuery` is Phase 7's read API into Phase 6's data. D-117 (status enum), D-118 (sender + subject extracted at fetch time), D-132 (`BoundaryArchTest::noTransactionWritesFromEmailScan`), and D-139 (sender_email normalization deferred) all condition Phase 7's design.

### Research
- `.planning/research/SUMMARY.md` — §"Phase 7" identifies per-sender template-matcher fragility (PayPal redesigns its receipt HTML periodically) as the load-bearing risk. Plan-phase must build matchers defensively (header-shape fallbacks, subject-line fallbacks, anonymised fixture coverage of multiple template generations).
- `.planning/research/ARCHITECTURE.md` — Search for the receipt-parsing section if present; the matcher inversion of control (registry + per-sender class) maps to the strategy-pattern shape research already validated for `SourceAdapter`.
- `.planning/research/PITFALLS.md` — Any pitfalls flagged for HTML email parsing (encoding, multipart/alternative preference order, attached PDFs in ICS emails) inform matcher robustness budgets.
- `.planning/research/STACK.md` — If a receipt-HTML or MIME library is recommended (e.g., `zbateson/mail-mime-parser` for raw `.eml` parsing without `ext-imap`) the planner should reuse that recommendation. Phase 7 must NOT introduce any dependency that transitively pulls `ext-imap` (PLT-05 invariant inherited).

### External Documentation (Phase 7's research targets)
- `zbateson/mail-mime-parser` — https://github.com/zbateson/mail-mime-parser — Likely the .eml parser pick. Pure PHP, no `ext-imap` dependency.
- RFC 822 / RFC 5322 — Internet Message Format — for the `Message-ID` + `From` + `Subject` + `Date` header semantics used by D-705a + D-705.
- mbox format reference — https://www.loc.gov/preservation/digital/formats/fdd/fdd000383.shtml — for `.mbox` iteration semantics (the `From ` line marker).
- Flux UI table + modal + form components — https://fluxui.dev/ — D-713 `/rules` UI.
- Livewire 4 docs — https://livewire.laravel.com/docs — wire:poll for watched-folder progress + dispatch pattern for D-712 toast.

### Existing Source (read before extending)
- `composer.json` — Phase 7 likely adds `zbateson/mail-mime-parser` (planner confirms). Composer audit must confirm no `ext-imap` regression (PLT-05).
- `Modules/Ingestion/` (the SourceAdapter pipeline) — Receipt adapter plugs in as a new arm next to existing CSV/CAMT/MT940/PayPal/ICS adapters. **Critical**: locate `SourceAdapterRegistry`, `HeaderSniffer`, `NormalizeStage`, `FingerprintStage::classify`, `ApplyEnrichments`, `RecordTransactions`. The matcher path becomes one more arm of this pipeline; do not reinvent these.
- `Modules/Ledger/Database/Migrations/2026_05_12_010007_create_merchant_memories_table.php` — Existing table. Phase 7 writes to it via the new `MerchantMemoryWriter`. Schema: `id`, `user_id`, `merchant_id`, `category_id`, `occurrence_count`, `last_seen_at`, `UNIQUE (user_id, merchant_id, category_id)`.
- `Modules/Ledger/Database/Migrations/2026_05_12_010006_create_merchants_table.php` — Existing table. Receipt matchers populate / re-use merchant rows via the standard Ingestion pipeline.
- `Modules/Categorization/Public/Actions/AssignCategory.php` + `Modules/Categorization/Public/Events/TransactionCategorized.php` — The event the new `MerchantMemoryWriter` listens to.
- `Modules/Categorization/Internal/Listeners/SeedDefaultCategoryTree.php` — Listener pattern precedent for `MerchantMemoryWriter`.
- `Modules/Categorization/Internal/Http/Livewire/InlineCategoryPicker.php` — Reuse precedent for the rule-form category picker on `/rules`.
- `Modules/EmailScan/Public/Services/InboxMessageQuery.php` — Phase 6 Public surface. Phase 7's consumer reads `status='fetched'` rows from here.
- `Modules/EmailScan/Public/Dto/InboxMessageDto.php` — Input shape for `SenderMatcher::canHandle()`.
- `Modules/Chains/Public/Events/` — Pattern reference for the new `ChainHintDetected` event Phase 7 emits (D-708).
- `Modules/Chains/Internal/Listeners/ResolveChainLinksOnImport.php` (or equivalent) — The Phase 5 listener that picks up `TransactionImported` events; Phase 7's reference_id population (D-708) makes this work for free.
- `Modules/Transfers/composer.json` + `Modules/Transfers/Providers/TransfersServiceProvider.php` — Reference for the new `Modules/Receipts/` ServiceProvider + composer.json shape.
- `tests/Pest.php` — New `Modules\\Receipts\\Tests\\` PSR-4 entry must be added (3-step pattern documented in Phase 4 D-80b: composer.json autoload-dev + phpunit.xml testsuite + Pest.php).
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 7 service injects.
- `tests/Contracts/BoundaryArchTest.php` — Add `noEmailFetchFromReceipts` invariant (D-701) + ensure existing `noTransactionWritesFromEmailScan` (D-132) remains green.
- `tests/Contracts/IdempotencyContractTest.php` — Phase 7 receipt adapter MUST be added to the contract dataset so re-running the same `.eml` / `.mbox` import produces zero new rows.
- `routes/web.php` — New `GET /rules` route + nested rule actions; new watched-folder toggle endpoint on `/settings` if D-704 secondary path lands in Phase 7 rather than v2.
- `routes/console.php` — New scheduled task for the optional watched-folder scanner (when toggled on).
- `app/Console/Kernel.php` — May host the matcher-consumer schedule (or it may run inline against `TransactionImported`-style triggers — D-719 owns this).

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`merchant_memories` table from Phase 1** — Read+write target for the new `MerchantMemoryWriter`. Schema accommodates Phase 7 without migration changes.
- **FingerprintComposer v3 from Phase 2** — Receipt-derived `SourceTransactionDto` reuses the same fingerprint composition. Cross-format dedup against earlier CSV imports just works.
- **FingerprintStage::classify + ENRICHED disposition + ApplyEnrichments + pending_enrichments table** — D-706 dedup mechanism. No new dedup code in Receipts.
- **HeaderSniffer + SourceAdapterRegistry** — Receipt adapter plugs into both as a new arm (D-704 wizard primary path).
- **Wizard preview-then-confirm pattern from Phase 1/2/4** — D-704 wizard issuer reuses this exact shape.
- **Inbox-message handoff from Phase 6** — `InboxMessageQuery::forStatus('fetched')` + on-disk `.eml` path. Phase 7's consumer is the sole reader.
- **Phase 5's TransactionImported listener (ResolveChainLinksJob)** — D-708 reference_id path feeds it for free. Zero new code in Chains for the common case.
- **Toast pattern from Phase 4/5** — D-707 first-conflict toast + D-712 correction-divergence toast both reuse `$this->dispatch('toast', ...)` + Alpine `x-on:toast.window`.
- **Failed-job listener pattern from Phase 5/6** — Pattern for the new `MerchantMemoryWriter` listener responding to `TransactionCategorized`.
- **Container tagging precedent (the SourceAdapterRegistry uses it)** — Same shape for `MatcherRegistry` (D-702).
- **View Factory composer pattern (issue #12 fix)** — If `/rules` gains a top-nav badge (e.g., count of pending correction-divergence suggestions), it follows this pattern; no `view()` global helper.
- **Cross-user 404 invariant (Phase 3-07 + Phase 4-04 + Phase 5-04 pattern)** — All `/rules` actions + the file-drop pipeline assert `$rule->user_id === $currentUser->id` defensively + via `where('user_id', ...)` clauses.
- **DI-only invariant + raw `DatabaseManager` for whereBetween/whereIn/orderBy** — Every Phase 7 service follows this.
- **BelongsToUser trait + nullable `user_id` on every domain table (FND-03)** — Applies to `categorization_rules`, `file_imports`, the new `pending_enrichment_conflicts` (if introduced per D-715).

### New Code Surface (Phase 7 adds)
- **`Modules/Receipts/` bounded module** — composer.json, ServiceProvider, Public/Internal split, dedicated tests dir.
- **`Modules/Receipts/Public/Contracts/SenderMatcher.php`** — Interface for per-sender matchers.
- **`Modules/Receipts/Public/Dto/ParsedReceiptDto.php`** + **`MatchOutcomeDto.php`** + **`MatcherInputDto.php`**.
- **`Modules/Receipts/Public/Events/ChainHintDetected.php`** — D-708 cross-module event.
- **`Modules/Receipts/Public/Actions/RecordReceipt.php`** — Public entrypoint called by the matcher consumer + the wizard drop-in path.
- **`Modules/Receipts/Public/Services/FileImportQuery.php`** — Wizard preview support.
- **`Modules/Receipts/Internal/Matchers/PaypalReceiptMatcher.php`** + **`IcsReceiptMatcher.php`** + **`GooglePlayReceiptMatcher.php`**.
- **`Modules/Receipts/Internal/MatcherRegistry.php`** — Container-tagged collection (D-702).
- **`Modules/Receipts/Internal/Pipeline/ReceiptSourceAdapter.php`** — `ParsedReceiptDto` → `SourceTransactionDto` bridge feeding the existing Ingestion pipeline (D-703).
- **`Modules/Receipts/Internal/Pipeline/EmlMimeReader.php`** — Thin wrapper around `zbateson/mail-mime-parser` (planner confirms library).
- **`Modules/Receipts/Internal/Pipeline/MboxIterator.php`** — Iterates an `.mbox` file message-by-message (D-705).
- **`Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php`** — Walks `inbox_messages.status='fetched'` from Phase 6 + `file_imports.status='fetched'` from D-705 and dispatches matchers.
- **`Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php`** — Optional watched-folder scanner (D-704 secondary path).
- **`Modules/Receipts/Internal/Http/Livewire/WizardEmailFileStep.php`** — `/imports` wizard arm for `.eml`/`.mbox` upload.
- **`Modules/Receipts/Database/Migrations/*_create_file_imports_table.php`** — D-705.
- **`Modules/Categorization/Internal/Pipeline/ApplyAutoCategoryStage.php`** — New pipeline stage applying rules + merchant memory at import time.
- **`Modules/Categorization/Internal/Services/RuleEvaluator.php`** — D-711 specificity-scored evaluation.
- **`Modules/Categorization/Internal/Listeners/MerchantMemoryWriter.php`** — Listens to `TransactionCategorized` to grow `merchant_memories`.
- **`Modules/Categorization/Public/Actions/CreateCategorizationRule.php`** + **`UpdateCategorizationRule.php`** + **`DeleteCategorizationRule.php`**.
- **`Modules/Categorization/Public/Services/CategorizationRuleQuery.php`** — Backs the `/rules` page.
- **`Modules/Categorization/Public/Dto/CategorizationRuleDto.php`** + **`AutoCategorizationOutcomeDto.php`** (carries the provenance + rule_id/memory_id consumed by D-712).
- **`Modules/Categorization/Internal/Http/Livewire/RulesPage.php`** + **`RuleFormModal.php`** — `/rules` UI.
- **`Modules/Categorization/Database/Migrations/*_create_categorization_rules_table.php`** — `id`, `user_id`, `field`, `match`, `value`, `category_id`, `hits_count`, `active` (D-722), `notes` (D-722), timestamps. `UNIQUE (user_id, field, match, value)`.
- **`Modules/Categorization/Database/Migrations/*_add_receipt_conflict_resolution_to_users.php`** — D-707.
- **`Modules/Categorization/Database/Migrations/*_create_pending_enrichment_conflicts_table.php`** OR a column addition to existing `pending_enrichments` — D-715 (planner picks).
- **`tests/Contracts/BoundaryArchTest::noEmailFetchFromReceipts`** — New invariant per D-701.
- **`Modules/Receipts/tests/fixtures/`** — Anonymised PayPal / ICS / Google Play `.eml` files + one `.mbox` archive. Reuse Phase 6's synthesised email fixtures where possible.

### Established Patterns
- **DI-only — every new service injects collaborators via constructor.** `ReceiptSourceAdapter` injects `EmlMimeReader` + `MatcherRegistry` + `Filesystem`. `ApplyAutoCategoryStage` injects `RuleEvaluator` + `MerchantMemoryQuery` (the read-side).
- **Public/ vs Internal/ split from day one** — `Modules/Receipts/Public/` ships the matcher contract + DTOs + RecordReceipt + ChainHintDetected event so future modules (e.g., a v2 OCR-receipt scanner) consume the same surface.
- **Eloquent direct OK, no facades** — `CategorizationRule::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden; raw `DatabaseManager` injected via constructor for whereBetween/whereIn shapes.
- **BoundaryArchTest invariants** — D-701 (no email fetch from Receipts), D-132 carry-forward (no transaction writes from EmailScan), no facade calls in `Modules/Receipts/`, no helper calls (`auth()`, `request()`, `config()`).
- **Pest test layout** — unit tests next to the code (`Modules/Receipts/tests/Unit/Matchers/...`); feature tests for `/rules` under `tests/Feature/`; cross-module idempotency tests under `tests/Contracts/`.
- **Synthesised fixture-first Wave 0** — Same precedent as Phase 5 D-107 + Phase 6 D-140. Anonymised real PayPal/ICS/Google Play receipts in `Modules/Receipts/tests/fixtures/`.
- **GSD-agnostic code comments** — No D-numbers / REQ-IDs in runtime code or PHPDocs; rationale stays in plain technical language.

### Integration Points
- **Ingestion pipeline** — Receipt adapter plugs into `SourceAdapterRegistry` + `HeaderSniffer`. `ApplyAutoCategoryStage` plugs into the pipeline after `NormalizeStage` and before `RecordTransactions` (planner confirms exact position). This means Phase 7 also auto-categorizes future CSV/CAMT imports (rules apply uniformly per D-710) — a bonus that aligns with the project's "rules pre-categorize on import" requirement (CAT-04).
- **EmailScan handoff** — `ProcessFetchedInboxMessagesJob` reads `InboxMessageQuery::forStatus('fetched')` and transitions to `parsed`/`skipped`/`unmatched` via a new write API on `Modules/EmailScan/Public/` (e.g., `MarkMessageProcessed`) — OR Phase 7 owns the transition directly via `DatabaseManager` (planner picks; cleaner is a Public action on EmailScan).
- **Chains integration** — `transaction.reference_id` populated by matchers (D-708) automatically triggers Phase 5's existing `ResolveChainLinksJob`. The new `ChainHintDetected` event is listened to by `Modules/Chains/` for richer hints — single new listener.
- **Categorization extension** — `Modules/Categorization/` gains 5 new migration tables/columns, 3 new Public actions, 2 new Public services, 1 new pipeline stage, 1 new listener, 2 new Livewire SFCs. Public surface stays back-compatible for v1.0.
- **Top-nav** — New "Rules" item. UI-SPEC pass owns positioning + grouping (D-721).
- **Routes** — NEW `GET /rules`, `POST /rules`, `PATCH /rules/{id}`, `DELETE /rules/{id}`, `POST /imports/email-file` (or wizard-step extension), and optionally `POST /settings/watched-folder-toggle`. All gated by the Phase 1 LoopbackOnly + Fortify auth.
- **Composer** — Likely adds `zbateson/mail-mime-parser` for `.eml` parsing. Composer audit must confirm no `ext-imap` regression (PLT-05).
- **Filesystem** — `storage/app/inbox/{user_id}/file-drop/{YYYY}/{MM}/` is the new partition. Optional `storage/app/inbox-drop/` + `/processed/{YYYY-MM}/` for the watched-folder secondary path.

### Risks Phase 7 Specifically Owns
- **Matcher fragility to receipt-HTML redesigns** — PayPal, Google Play, and ICS Cards periodically restructure their receipt HTML. Mitigations: multiple header-shape fallbacks per matcher, subject-line fallbacks, anonymised fixture coverage of multiple template generations, a "matcher failed but received a candidate receipt" log surface in `inbox_messages.status='unmatched'` so the user (or a future Phase 7.1) can re-parse after a matcher update.
- **`.eml` body charset edge cases** — multipart/alternative ordering, quoted-printable encoding, base64 attachments, mojibake from forwarded receipts. `zbateson/mail-mime-parser` handles most of this; matchers should ALWAYS read `text/plain` body when present and fall back to `text/html` only when missing.
- **Rule explosion / performance** — A user with hundreds of rules + a large `merchant_memories` table means `ApplyAutoCategoryStage` evaluates many candidates per transaction. Index `categorization_rules.user_id + active` and `merchant_memories.user_id + merchant_id`. Planner can short-circuit on first `equals` match. Realistic v1 volume is <50 rules; this is mostly a future-proofing note.
- **Conflict-toast accidental dismiss** — D-707 first-conflict toast is one-shot; if dismissed without action, the setting stays `unset` and future conflicts surface the same toast again until acted on. NOT a one-time-only mechanism — the user must explicitly choose to lock the setting. Document this clearly in the toast copy.
- **Merchant memory + rule co-existence drift** — D-711 says memory grows even when a rule wins. If the user deletes a rule, memory takes over the next ingestion silently. Acceptable but worth a doc note in the `/rules` page header ("Deleting a rule doesn't clear what was learned from past categorizations").
- **`.mbox` files can be HUGE** — A 5-year iCloud mbox export can be GB-scale. The mbox iterator must stream (line-by-line State-machine), not load to memory. Wave 0 fixture should include a deliberately-large synthetic mbox to prove memory bounds.
- **Synthetic Message-ID hash collision** — D-705a uses sha256 of full bytes. Collision probability is negligible but technically nonzero; the UNIQUE constraint treats it as a no-op re-import which is "wrong" but harmless (same bytes = same parse). Acceptable.
- **Phase 6/Phase 7 release cadence** — Phase 7 cannot be demo-tested end-to-end until Phase 6 lands `inbox_messages.status='fetched'` rows. Wave 0 must include a `FakeInboxMessageQuery` stub so CI can prove the pipeline without a real Phase 6 backfill. The `.eml`/`.mbox` drop-in path (D-704/D-705) is independently demo-testable in Wave 1 without Phase 6 readiness.

</code_context>

<specifics>
## Specific Ideas

- **Receipts and CSVs must produce fingerprint-equivalent transactions.** That is the load-bearing invariant. The whole "cross-format dedup just works" payoff (D-706) breaks if the matcher's `ParsedReceiptDto` populates fingerprint inputs differently from the CSV adapter's path. Wave 0 should include a per-sender Pest test that asserts: given a known PayPal CSV row AND its corresponding receipt `.eml`, the FingerprintComposer produces the same fingerprint.
- **Receipts have richer text data than CSVs.** Better merchant names, exact reference IDs, attachment hints. The first-conflict toast (D-707) is how the user tells diederik whether they trust the receipt or the CSV more. Once set, the policy is silent — calm aesthetic preserved.
- **Rules and memory coexist via specificity scoring, not strict precedence.** D-711 is intentional: a user who wrote "contains SPOTIFY" expects it to win over a vaguer memory, but a long-time user whose memory says "Spotify → Music" expects that to win over a fuzzy "contains spot" rule that accidentally matched a one-off charge. Specificity handles both.
- **Correction-divergence has two surfaces.** Toast for the moment-of-action (interrupt-low, dismissable, one-shot per rule per correction). Inline drawer panel for the user who didn't see / dismissed the toast (always-available, no friction). Same action target.
- **Phase 7 deliberately reuses the Phase 1–5 SourceAdapter pipeline.** The match-then-pipeline approach means email receipts share idempotency, dedup, normalization, fingerprinting, and chain-resolution with every other source. No parallel pipeline. No drift risk.
- **The matcher consumer is just a queue worker.** `ProcessFetchedInboxMessagesJob` runs (synchronously or on a schedule — D-719 planner pick), reads `status='fetched'` rows, invokes the registry, writes outcomes. Same shape as Phase 5's `ResolveChainLinksJob`.
- **Wave 0 ships synthesised fixtures + a `FakeInboxMessageQuery` stub.** Same precedent as Phase 5 D-107 and Phase 6 D-140. Real Gmail/Graph integration is exercised end-to-end on the developer's machine separately from CI.
- **`Modules/Receipts/` mirrors `Modules/Transfers/` + `Modules/Chains/` structurally.** Same composer.json shape, same ServiceProvider shape, same Public/Internal split, same BoundaryArchTest invariant pattern. No new architectural ideas — Phase 7 is "more of the same, applied to a new domain."

</specifics>

<deferred>
## Deferred Ideas

- **Regex rules** — D-709 explicitly v2. The `match` enum reserves room (extend with `'regex'` when needed).
- **Multi-condition rules** ("merchant contains X AND amount > Y") — D-709 v2.
- **Free-form DSL for rules** — Rejected complexity tier; deferred indefinitely.
- **Per-field conflict precedence map** — D-707 chose binary toast-then-setting; per-field overrides are a v2 power-user refinement if the binary choice proves too coarse.
- **PDF receipt parsing** — Some ICS receipts arrive as PDF attachments rather than HTML email bodies. Phase 7 v1 covers HTML email; PDF-receipt OCR is a v2 effort (likely a new matcher type or an OCR pre-stage feeding `EmlMimeReader`).
- **Image-receipt / photo-of-receipt ingestion** — Out of scope; never on the Phase 7 roadmap.
- **Watched-folder enabled by default** — D-704 defaults it off. v2 nicety: detect file drops automatically with a one-time onboarding hint.
- **Top-nav grouping ("Categorize" submenu)** — D-721 defers to UI-SPEC. If `/rules` + `/uncategorized` + `/chains/review` overcrowd the bar, group them then.
- **`active` toggle + `notes` field on rules** — D-722 nicety; planner decides if Wave 4 has budget.
- **Re-parse trigger from the UI** — When a new matcher is added (or an existing one updated), the user might want to re-run matchers against `status='unmatched'` rows. D-117 anticipates this; Phase 7 may ship a simple "Re-parse unmatched receipts" button on `/inboxes` or `/rules` if Wave budget allows, otherwise v2.
- **Per-merchant `normalized_merchant` write-side** — Phase 6 D-139 deferred sender_email normalization to Phase 7 / planner. If the planner determines merchant-name normalization (e.g., strip trailing store-number suffixes) is needed for CAT-02 to fire reliably, that work lives in the new `MerchantMemoryWriter` listener. Otherwise v2.
- **Re-parse on rule change** — Changing a rule does not retroactively re-categorize existing transactions in v1. Bulk re-apply is a v2 power-user feature.
- **PROJECT.md amendment to acknowledge `zbateson/mail-mime-parser`** — Owned by Wave 0 plan, atomically with the new composer dep.
- **Inline "create rule from this transaction" affordance** — On `/transactions/{id}`, a "+ Rule from this merchant" quick-action that pre-populates the `/rules` modal. Nice-to-have; v2 if Wave 4 doesn't fit.
- **Confidence indicator on auto-categorization** — A visual indicator (green dot for rule, dim dot for memory) on transaction rows. UI-SPEC may include if budget allows; otherwise v2.
- **Bulk rule import/export (JSON)** — Power-user; v2.

</deferred>

---

*Phase: 7-Email Template Matchers + Categorization Learning*
*Context gathered: 2026-05-17*
