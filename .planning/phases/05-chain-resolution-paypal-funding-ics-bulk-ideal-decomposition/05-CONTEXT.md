# Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) - Context

**Gathered:** 2026-05-16
**Status:** Ready for planning

<domain>
## Phase Boundary

Phase 5 ships the project's headline differentiator: cross-source chain resolution. Two distinct resolvers land together.

**PayPal funding chain** — when a PayPal charge appears, trace it back to the ASN bank account or ICS card that ultimately funded it. Deterministic where a shared reference ID (PayPal `Funding Source` column / `Reference Txn ID` / ASN counterparty IBAN in a sweep memo) exists; fuzzy with confidence scoring on merchant + amount + date when no shared reference does.

**ICS bulk-iDEAL decomposition** — the monthly ASN→ICS lump-sum iDEAL settlement carries no per-purchase reference. Decompose it back into the N ICS card transactions it covers, with explicit partial-payment / overpayment / carry-forward credit handling within the locked ±€5 / ±2% / ±10-day tolerances. The ASN→ICS row pair already exists (Phase 4 `pair_transaction_id` via synthetic IBAN `ICS-CARD`); Phase 5 adds N `chain_links` of `kind='ics_bulk_settle'` from the ICS `transfer_in` to each underlying ICS expense.

**Plus** a candidate review surface — both a dedicated `/chains/review` page and an inline action on `/transactions/{id}` — for the fuzzy chain_links the resolver cannot auto-confirm; an auto-promotion learning loop that confirms recurring (merchant + funding-source-pair) signatures after three same-signature confirmations; a chain drill-down drawer triggered from `/transactions/{id}` that renders the full waterfall (UI-02); and a dashboard "Next ICS settlement amount" tile (CHN-06) computed from the open `card_statements` row.

**Async execution.** This phase pulls queue infrastructure forward by one phase (originally scoped for Phase 6) and introduces Redis + Laravel Horizon — overriding the PROJECT.md "no Redis" stack constraint by explicit user decision (rationale: production-grade queue observability outweighs the single-user simplicity argument once a chain-resolution job exists). Redis runs as a Docker container (network-only service — does not invoke the Sail-Docker bind-mount footgun). PROJECT.md and STACK guidance are amended atomically alongside Phase 5 plans, mirroring how Phase 4 amended ROADMAP.md SC #2 and REQUIREMENTS.md for ING-09 deferral.

**Phase 5 does NOT add:** recurring detection (Phase 8), drift alerts (Phase 9), forecasting / what-if (Phase 10), email ingestion (Phase 6), multi-card support for ICS (deferred, Phase 3 ICS-CARD synthetic-IBAN model holds). It does NOT re-type transactions — the resolver writes `chain_links` only (research/ARCHITECTURE.md invariant) and never mutates `transactions` rows.

</domain>

<decisions>
## Implementation Decisions

### Chain-Link Data Model

- **D-82:** **`chain_links` table with `state` (candidate / confirmed / rejected) + `confidence` (0..1) + `evidence` (JSON) + `kind` enum + `resolver` (auto / user / rule).** Locked from research/CHN-07 + research/ARCHITECTURE.md. Schema columns: `id`, `user_id` (BelongsToUser invariant per FND-03), `from_transaction_id`, `to_transaction_id`, `kind`, `state`, `confidence`, `resolver`, `evidence`, timestamps. Indexes per research/ARCHITECTURE.md L511: `(from_transaction_id)` and `(to_transaction_id)`. Self-FK both sides → `transactions.id` `ON DELETE CASCADE` so chain rows never orphan their endpoints.
- **D-83:** **Initial `chain_links.kind` enum values: `paypal_funding`, `ics_bulk_settle`.** Two kinds Phase 5 produces. The migration writes them as a CHECK constraint (matches Phase 1's `transactions.type` enum shape via BEFORE-INSERT/UPDATE trigger). Future kinds (`refund_of`, `recurring_member`) are explicitly out-of-scope and live in their own future phases.
- **D-84:** **Resolver writes `chain_links` only — never mutates `transactions`.** Research invariant repeated for emphasis. The single allowed exception (per research/ARCHITECTURE.md L445) is writing a derived `funding_account_id` on a future `RecurringSeries` row — that table doesn't exist until Phase 8 and is out-of-scope. A `BoundaryArchTest` enforcement rule (mirroring Phase 4's `BoundaryArchTest::noPaypalApiRoute`) asserts no `Modules/Chains/` file calls `Transaction::update()` / raw `update` against `transactions`.
- **D-85:** **`chain_links` confidence: 1.0 = deterministic match; 0.6–0.99 = fuzzy auto-promoted or to-be-confirmed; below 0.6 = not surfaced.** Tier mapping matches the three-tier UI chip from D-91. The 0.6 floor keeps the review queue calm — very weak candidates are dropped, not surfaced as noise.

### Review Queue UX + Learning Loop

- **D-86:** **Dual review surface — dedicated `/chains/review` page AND inline action on `/transactions/{id}`.** Dedicated page batches all `state='candidate'` chain_links across the user's transactions (sort: highest confidence first, then most-recent first). Inline action lives inside the chain drill-down drawer (D-92): candidates render dimmed with Confirm / Reject chips so the user can act from within the chain context. Same `ConfirmChainLink` / `RejectChainLink` action class powers both surfaces (one Livewire `wire:dispatch` call shape).
- **D-87:** **Auto-promotion threshold: 3 confirmations of the same evidence signature → next candidate of the same signature lands as `state='confirmed'` directly (still records `resolver='rule'`).** Per-user counter on the signature. Matches research/ARCHITECTURE.md "if this feature combination was confirmed N times, auto-confirm next time" with the conservative N=3 default.
- **D-88:** **Auto-promotion signature = (normalized_merchant, funding_source_identity).** For PayPal funding: funding_source_identity = the underlying ASN/ICS account's `Account.iban` (or synthetic IBAN for `ICS-CARD` / `PAYPAL`). For ICS bulk-settle: signature is degenerate (always confirms because the math + tolerance gate already does the work — see D-94). The signature is stored as a SHA-256 hash of the tuple in `chain_link.evidence.signature_hash` for stable counting across imports.
- **D-89:** **Reject scope is per-pair only; signature stays neutral.** Setting one `chain_link.state='rejected'` does not blacklist the signature for future matches. Future candidates of the same (merchant, funding-source) signature still surface in the review queue. Rationale: a user's "wrong this once" click should not poison legitimate future matches. The rejected row stays in the DB so the resolver does not re-propose THAT exact pair, but the user can hand-link them later via a future manual-link action if needed.

### Chain Drill-Down UI

- **D-90:** **Chain visualises as a vertical waterfall in a side-drawer modal.** Drawer triggered from a "View chain" button on `/transactions/{id}` (entry point intentionally narrow — no per-row icon on the `/transactions` list, keeps the list calm). Top of the waterfall = the merchant charge the user clicked into; each step downward = the funder. Pure Tailwind + Blade rendering — no graph library, no D3, no SVG. Reads like a payment receipt the user can scan top-down. This is the project's first Flux drawer primitive — UI-SPEC pass in plan-phase locks the open/close/escape behaviour, sticky vs scrolling header, and the empty-chain "Chain not yet resolved" state.
- **D-91:** **Three-tier confidence chip per leg: `Deterministic` / `Confirmed` / `Candidate`.** Maps to `chain_link.state` + `resolver`: `state='confirmed' AND resolver='auto' AND confidence=1.0` → "Deterministic"; `state='confirmed'` otherwise → "Confirmed"; `state='candidate'` → "Candidate" (dimmed with Confirm/Reject chips inline). Raw 0..1 confidence number is NOT shown in the UI (matches calm aesthetic — UI-05).
- **D-92:** **Drawer renders fully-expanded by default.** Every resolved leg of the chain is visible on first paint. Click-to-collapse stays available per leg, but the default is "see exactly where the money came from" with zero additional clicks. Chains are short in practice (≤5 levels for the project's payment topology); auto-expanding scales.
- **D-93:** **ICS bulk-settle fan-out renders as a nested list under the settlement node, showing all N covered ICS charges.** When the user drills into a Netflix-on-ICS charge whose ICS line is settled by a bulk-iDEAL, the drawer shows the settlement node followed by "covers 23 ICS charges (€847.32)" with the 23 individual charges listed beneath (each tappable to open a fresh drawer scoped to that charge's own chain). The drawer is full-height with no outer scroll; long fan-out lists paginate ("show 10 more") within the fan-out block itself. The full-height-no-scroll choice is a deliberate departure from the default Flux drawer scroll pattern — UI-SPEC plan-phase locks pagination size + the "X of N" affordance.

### ICS Statement Model + Decomposition

- **D-94:** **New first-class `card_statements` table back-populated from Phase 3's `statement_summaries`.** Columns: `id`, `user_id`, `account_id` (FK → ICS Account), `period_start`, `period_end`, `total_amount_minor` (negative because outstanding), `open_balance_minor` (positive remaining to settle; updated as chain_links of `kind='ics_bulk_settle'` accumulate), `state` enum (`open` / `settled` / `partially_settled` / `overpaid`), `import_run_id` (the import that created/refreshed this statement row), timestamps. Each row sourced from a `statement_summaries` row whose `account_id` is ICS-kind. A new Phase 5 migration writes the table + a one-shot back-population from existing `statement_summaries`. UNIQUE on `(user_id, account_id, period_start, period_end)`.
- **D-95:** **`card_statements.state` lifecycle.** `open` on creation; transitions to `partially_settled` when first chain_link of `kind='ics_bulk_settle'` lands and `open_balance_minor > 0`; transitions to `settled` when `open_balance_minor == 0` (within ±€0.01 rounding); transitions to `overpaid` when `open_balance_minor < 0` (surplus exists). The `overpaid` state is the trigger for D-96's carry-forward. A `BoundaryArchTest` rule asserts `card_statements.state` only changes via the `Modules/Chains/Internal/CardStatementStateMachine` class.
- **D-96:** **Overpayment surplus = virtual `credit_carry` line on the NEXT `card_statement` of the same Account.** When statement A goes `overpaid` by €1.53, the next-period statement B (for the same Account) gets a virtual `credit_carry` row applied to its `open_balance_minor` at creation time (statement B's effective open = `total - credit_carry_in`). The credit_carry is stored as a row on a new tiny `card_statement_credits` table with `(from_statement_id, to_statement_id, amount_minor)` so the audit trail is intact. The chain drill-down drawer renders the credit_carry as a "↩ €1.53 credit carried from Feb statement" line on statement B's settlement node when the chain walks through it. Matches research/PITFALLS.md Pitfall 4's "treat overpayments as carry-forward credit, not as a reconciliation failure".
- **D-97:** **Bulk-settle reconciliation within ±€5 OR ±2% across ±10-day window auto-confirms; outside tolerance lands as `candidate`.** CHN-05 + research/PITFALLS.md Pitfall 4 tolerance values, locked verbatim. When a bulk-iDEAL settlement amount is within tolerance of an open `card_statement` total: chain_link rows of `kind='ics_bulk_settle'` are created from the ICS `transfer_in` to each ICS expense covering the statement period; chain_link state = `confirmed`, resolver = `auto`, confidence = 1.0. The unaccounted delta (e.g. €1.53 surplus / €2.18 underpayment) is recorded in chain_link.evidence as `{ "unaccounted_delta_minor": +153, "statement_id": ..., "tolerance_used": "amount_5eur" }` for audit. If the delta exceeds tolerance, the chain_link stays `candidate` and the review queue surfaces it for user judgment.
- **D-98:** **Refunds after statement close stay attached to their original statement (for chain tree) but reduce the NEXT settlement's open_balance.** When an ICS refund arrives whose `posted_at` falls inside a `settled` or `overpaid` card_statement period, the refund chain-links back to the original purchase via `kind='ics_bulk_settle'` (so the chain drill-down shows refund → original purchase → original settlement). For accounting, the refund amount is applied as a `credit_carry` to the next-open `card_statement` (same shape as D-96 overpayment). Pitfall 4: "Refunds after statement close stay attached to the statement they belong to (purchase date), but flow into the next settlement amount."

### Settlement Forecast (CHN-06)

- **D-99:** **"Next ICS settlement" tile lives on the dashboard `this month at a glance` view alongside the existing in/out/net + per-currency tiles.** Renders as: top line = `Next ICS settlement: €523.47`, secondary line = `due ~20 May` (computed from the open card_statement's period_end + a small forecast lag based on the user's prior settlement cadence — Phase 5 ships the lag as a constant 5 days, Phase 8's recurring-cadence inference refines it later). Tile is only rendered when there IS an open card_statement; empty / settled state hides the tile rather than rendering "—". `ThisPeriodAtAGlanceQuery` gains a `nextIcsSettlement(): ?CardStatementForecastTile` method (returns null when no open statement) which the Dashboard Blade conditionally includes.
- **D-100:** **Forecast amount = `open_balance_minor` of the most-recent `card_statement` whose state ∈ ('open', 'partially_settled').** No clever cadence inference in Phase 5 — the open balance IS the forecast (post-D-96 credit_carry application; post-D-98 refund accounting). Phase 8 layers recurring-charge projection on top later. Forecast lag (when the user typically pays) is a constant 5 calendar days from period_end; user-configurable forecast lag is deferred to v2.

### Resolver Execution Model

- **D-101:** **Async via Laravel Horizon + Redis.** This OVERRIDES PROJECT.md "What NOT to Use → Laravel Horizon for this project" and the database-queue-driver choice. User decision: production-grade queue observability outweighs the single-user simplicity argument once chain resolution exists. Plan-phase emits an atomic edit to PROJECT.md (move Horizon from "What NOT to Use" to recommended; flip queue driver from `database` to `redis`; add `predis/predis` or `phpredis` extension note; add Horizon installation + `/horizon` dashboard route; document Redis Docker container in README). Mirrors the Phase 4 close-out posture of editing REQUIREMENTS.md atomically with the phase's plans.
- **D-102:** **Redis runs as a Docker container (network-only service).** `docker run --name diederik-redis -p 6379:6379 -d redis:7-alpine` is the README setup line. NOT a full Sail / Docker-Compose-the-app posture — only the Redis service runs in Docker. Bind-mounts are NOT used; Redis persists to a named Docker volume. The Sail anti-pattern PROJECT.md flags (slow bind-mount IO) does not apply. PROJECT.md gets a small amendment carving Docker-for-Redis as an explicit exception to the "no Docker" rule.
- **D-103:** **`ResolveChainLinksJob` with `ShouldBeUniqueUntilProcessing` keyed on `user_id`.** Per-user job uniqueness eliminates the parallel-resolution race research/ARCHITECTURE.md L446 warns about. Job is dispatched from `ConfirmImport`'s post-commit hook (matches Phase 4's `TransactionImported` listener pattern, but as a queued dispatch instead of a sync listener). Failure retries: 3 tries with exponential backoff (60s / 300s / 900s). On final failure, a Filament-notifications-style toast surfaces on the dashboard via Livewire `wire:poll` polling against a `failed_jobs` count query.
- **D-104:** **Resolver scope per dispatch: full-user re-scan over all `open` / `partially_settled` `card_statements` and all `transactions` lacking a confirmed chain_link.** Catches the "late ICS import retroactively decomposes a prior ASN settlement" case. SQLite + WAL handles 30k-row scans in milliseconds; not a perf concern at single-user scale. The scan is bounded by the user_id filter (FND-03 BelongsToUser invariant) so a future multi-user expansion never accidentally cross-scans.
- **D-105:** **Wizard polling: while ResolveChainLinksJob runs, the post-confirm wizard surface shows a "Resolving chains…" status with `wire:poll.2s` against a `chain_resolution_status` query.** Status updates per-second-ish: `pending` → `running` → `complete` (with `linked_count`). Once complete, the wizard auto-navigates to the import summary surface which surfaces "Imported X transactions · linked Y chain candidates · Z pending review".
- **D-106:** **Phase 5 PayPal NL "General Withdrawal" close-out (Phase 4 hand-off).** Phase 4 verification documented: "PayPal General Withdrawal NL form classification depends on counterparty-IBAN check only; if IBAN absent, row defaults to expense — Phase 5 chain resolver resolves via deterministic IBAN match." The Phase 5 `PaypalFundingResolver` honors this: when a PayPal `transfer_out` lacks a counterparty IBAN but its rawPayload `events[]` contains a "Bankstorting" / "General Withdrawal" event with an inferable destination IBAN, the resolver creates a deterministic `chain_link` of `kind='paypal_funding'` to the matching ASN row. This back-fills the Phase 4 hand-off in code without requiring a Phase 4 deferred-items revisit.

### Wave 0 Enablement

- **D-107:** **Wave 0 synthesises a cross-source matching fixture.** Phase 2/3/4 redacted fixtures do not share an iDEAL-settlement counterparty IBAN pair (Phase 4 SC#3 explicitly noted this gap and validated at the listener-contract level instead of via fixtures). Phase 5 Wave 0 commits a synthetic-but-realistic fixture trio under `Modules/Chains/tests/fixtures/scenario-1/`: (a) anonymised ASN CAMT.053 with a bulk-iDEAL row whose amount sums (within tolerance) to (b) anonymised ICS PDF statement totals containing N transactions; plus (c) an anonymised PayPal CSV row whose `Reference Txn ID` chains back to a counterparty present in the ASN export. Exercises both resolvers end-to-end against committed data. Mirrors the Phase 2/3/4 fixture-first Wave 0 protocol but is synthetic rather than user-sourced (chain matching is the cross-source axis Phase 2/3/4 explicitly deferred).
- **D-108:** **Anonymisation reuses the Phase 2 / Phase 3 / Phase 4 scripts where applicable; new synthesis lives in `scripts/synthesise_phase5_scenario.php`.** Composer-dep-free, committed in-repo (mirrors Phase 3's `scripts/anonymize_ics_text.php` and Phase 4's `scripts/anonymize_paypal_csv.php`). Wave 0 plan delivers the synthesised fixture trio + a Wave-0 findings document describing the matched scenarios (the bulk-iDEAL total, the ICS transactions it covers, the PayPal→ASN deterministic link). Plan 05-01 commits the fixtures; Plans 05-02 onward consume them.

### Module Shape

- **D-109:** **`Modules/Chains/` is the new bounded module.** Public/ surface from day one — Phase 8's fixed-payments view (and Phase 10's forecasting) WILL query chain_links for the funding-source icons, so the Public read API matters now. Public surface: `ChainLinkQuery::forTransaction($txId): ChainTree` (returns hierarchical DTO of confirmed + candidate chain_links), `ConfirmChainLink::__invoke(int $id, User $user): void`, `RejectChainLink::__invoke(int $id, User $user): void`, `CardStatementQuery::openForAccount($accountId): ?CardStatement`. Internal/: `PaypalFundingResolver`, `IcsSettlementResolver`, `CardStatementStateMachine`, `ResolveChainLinksJob`, the review-queue Livewire SFCs. Follows Phase 4 Transfers module's composer.json + ServiceProvider pattern.
- **D-110:** **`Modules/Transfers/Public/` gets a minimal promotion in Phase 5: `PairLookup::isPaired(int $txId): bool` and `PairLookup::partnerId(int $txId): ?int`.** Phase 4 deferred the Public surface; Phase 5's chain resolver is the projected first consumer (Phase 4 D-80). The promotion is the entire Phase-4-deferred Public surface — minimal, single-purpose, reuses the existing internal listener's query patterns.

### Claude's Discretion

- **D-90 / D-91:** Exact Flux drawer component selection, open/close keyboard handling (Esc, click-outside-to-close), animation timing. UI-SPEC pass in plan-phase locks these — they don't change the data contract.
- **D-93:** Exact pagination size for ICS bulk fan-out blocks (default: 10 per "show more" click; planner can tune). The empty-fan-out edge case (statement with 0 covered transactions due to refund-only month).
- **D-94:** Migration timestamp slot for `card_statements` table (planner locks against the existing Phase 4 latest migration timestamp).
- **D-97 / D-98:** Exact JSON shape of `chain_link.evidence` for ICS bulk-settle (the unaccounted-delta + tolerance-used keys); refund-after-statement-close evidence shape. Pest dataset coverage in `IcsSettlementResolverTest`.
- **D-99:** Exact wording of the Next-ICS-Settlement tile (singular vs plural lag wording when multiple statements are open simultaneously — rare but possible).
- **D-101:** Horizon dashboard URL gate — does `/horizon` require auth (Phase 1 LoopbackOnly + Fortify auth already covers this — planner verifies). Whether to ship the `predis/predis` PHP package or require the `phpredis` PECL extension (planner defaults to predis to avoid PECL builds).
- **D-103:** Final failure-handling UX detail — toast wording, click-to-dismiss vs persistent, link to `/horizon` for retry / inspect.
- **D-105:** Exact wire:poll interval (1s / 2s / 5s) — planner tunes against perceived responsiveness; 2s default.
- **D-107:** Exact size of the synthesised scenario — how many ICS transactions covered by the bulk-iDEAL (default 20–25, mirrors realistic statement density); how many PayPal rows in the fixture.
- **D-110:** Whether the Transfers Public surface ships as part of Phase 5 Wave 0 (recommended) or as a Wave 2 prerequisite once the resolver actually needs it. Planner picks.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project-Level
- `.planning/PROJECT.md` — Project constraints (PHP 8.5 + Laravel 13, DI-only, `nwidart/laravel-modules`, Larastan level 10 strict, Pint, Pest, localhost-only, calm aesthetic). **AMENDED in Phase 5 plan-phase:** Horizon + Redis move from "What NOT to Use" to recommended; Docker-for-Redis carved as an explicit exception to the "no Docker" rule.
- `.planning/REQUIREMENTS.md` — Phase 5 covers CHN-01..07 + UI-02. Status table rows flip Pending → Pending (no deferral this phase).
- `.planning/ROADMAP.md` §"Phase 5" — Goal + four success criteria (chain tree, bulk decomposition, review queue, next-settlement forecast).

### Prior Phase Artefacts (read for continuity — same patterns apply)
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-CONTEXT.md` — Module split, DI-only, wizard preview-then-confirm, idempotency philosophy, fingerprint evolution, transactions.type enum origin
- `.planning/phases/01-foundation-asn-csv-vertical-slice/01-SKELETON.md` — explicit forward-declaration "Phase 5: New `Modules\Chains` module — owns `chain_links` table + deterministic + fuzzy resolvers + bulk-iDEAL decomposer; consumes Ledger's `TransactionListQuery` via Public surface only" — the contract this phase honors
- `.planning/phases/02-asn-statement-coverage-camt-053-mt940/02-CONTEXT.md` — Wave 0 anonymisation pattern, fingerprint v3 + ENRICHED cross-format dedup contract, stateful-adapter `lastStatementSummary()` shape, source-format rank function
- `.planning/phases/03-ics-cards-multi-currency-display/03-CONTEXT.md` — ICS PDF adapter + statement_summaries population (the source rows D-94 promotes to `card_statements`), synthetic-IBAN `ICS-CARD` account modeling, dual-currency display pattern, settings page pattern
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-CONTEXT.md` — PayPal CSV adapter + Transaction-ID rollup, `pair_transaction_id` schema + symmetric listener pattern (D-72/D-74/D-75), `Modules/Transfers/` bounded module shape that D-110 promotes, ClassifyTransactionType stage that Phase 5 resolver builds on but never re-runs
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-VERIFICATION.md` — PayPal NL "General Withdrawal" hand-off (D-106 closes this loop)

### Research
- `.planning/research/SUMMARY.md` — §"Phase 3: Chain Resolution" (numbered as the research's phase 3, maps to project's Phase 5) defines the chain-resolution scope; identifies Pitfall 4 + Pitfall 9 as the load-bearing risks
- `.planning/research/PITFALLS.md` — **Pitfall 4 (ICS bulk-settlement) is the load-bearing reference for D-94 / D-95 / D-96 / D-97 / D-98.** Pitfall 3 informs D-106 (PayPal funding-chain hints). Pitfall 9 (cross-source merchant/FX divergence) informs D-88 (signature normalisation).
- `.planning/research/ARCHITECTURE.md` — §"Chain-Resolution Engine" L403–L446 defines: ChainLink state/confidence/evidence model (D-82), resolver-writes-chain_links-only invariant (D-84), per-user job uniqueness (D-103), async post-load shape (D-101), bulk-settle SUM verification (D-97), forecast computation (D-100). §"Async post-load enrichment" L382–L389 names `ResolveChainLinksJob` (D-103).
- `.planning/research/FEATURES.md` — (no direct chain-resolution sections beyond what SUMMARY/ARCHITECTURE/PITFALLS cover; read for context)

### Existing Source (read before extending)
- `Modules/Ledger/Models/Transaction.php` — `Transaction::TYPES` enum (no change); `pair_transaction_id` column from Phase 4 (the resolver READS this via D-110 PairLookup, never writes it)
- `Modules/Ledger/Models/StatementSummary.php` — Phase 2/3 statement_summaries — the source rows D-94 back-populates into `card_statements`
- `Modules/Ledger/Public/Services/StatementSummaryWriter.php` — How statement_summaries get written today; D-94 extends with a post-insert event the card_statements writer subscribes to
- `Modules/Ledger/Public/Services/TransactionListQuery.php` — Used by the review queue's "show me both sides of this candidate" affordance (read-only)
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — Extends with `nextIcsSettlement(): ?CardStatementForecastTile` per D-99
- `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` — Reference for the symmetric-write pattern + raw DatabaseManager query shape the resolver mirrors; Phase 5 PROMOTES the read API to Public per D-110
- `Modules/Transfers/composer.json` — Reference for the new `Modules/Chains/composer.json` shape
- `Modules/Import/Public/Actions/ConfirmImport.php` — Where the post-commit dispatch of `ResolveChainLinksJob` lives (D-103); mirrors Phase 4's TransactionImported event firing
- `Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php` — Phase 4's classifier that the resolver does NOT re-run; resolver only LINKS already-typed transfer legs
- `Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php` — `rawPayload.events[]` shape D-106 reads to surface deterministic funding hints
- `Modules/Ingestion/Internal/Adapters/Ics/IcsPdfAdapter.php` — `statementMetadata()` returns the `StatementSummaryData` D-94 promotes to card_statements
- `Modules/Core/Public/Contracts/CurrentUser.php` — DI-only contract every Phase 5 service injects (no `auth()` / `Auth::user()`)

### External Documentation
- Laravel Horizon docs (https://laravel.com/docs/12.x/horizon) — `/horizon` dashboard, `ShouldBeUniqueUntilProcessing` job contract, supervisor configuration for the single-machine case
- Laravel Queues 12.x (https://laravel.com/docs/12.x/queues) — Redis queue driver setup, retry / backoff shape (D-103), failed-jobs storage
- predis/predis README (https://github.com/predis/predis) — PHP-only Redis client (D-101 default; avoids PECL `phpredis` build)
- Redis Docker official image (https://hub.docker.com/_/redis) — `redis:7-alpine` is the D-102 default; pin version in plan-phase
- Livewire 4 `wire:poll` docs (https://livewire.laravel.com/docs/wire-poll) — D-105 polling shape for the wizard "Resolving chains…" surface
- Flux UI drawer component (https://fluxui.dev/) — D-90 first project use of a drawer; UI-SPEC plan-phase pass locks props

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **`Transaction::TYPES` enum** — unchanged; resolver reads `type`, never writes it (D-84 invariant).
- **`pair_transaction_id` from Phase 4** — already paired ASN↔ICS / PayPal↔ASN rows are the chain_link endpoints the resolver builds on. Resolver reads `pair_transaction_id` via the Phase 5 PairLookup Public service (D-110) — never queries the column directly across module boundaries.
- **`statement_summaries` table from Phase 3** — the source for the D-94 back-populated `card_statements`. Phase 3's IcsPdfAdapter already writes the period totals + opening/closing balance; Phase 5 just needs the projection.
- **`ConfirmImport` action** — fires `TransactionImported` events per row inside its outer DB transaction (Phase 4). Phase 5 adds a post-commit dispatch of `ResolveChainLinksJob` keyed on user_id.
- **`PairTransferCandidates` listener (Phase 4)** — reference for the symmetric-write pattern, raw DatabaseManager query shape (`whereBetween`, `whereIn`, `orderBy`), and the user_id BelongsToUser invariant. Phase 5 IcsSettlementResolver mirrors the structure.
- **`ThisPeriodAtAGlanceQuery`** — extends with `nextIcsSettlement()` per D-99 — same shape Phase 3 used for the per-currency tiles (`for()` + `forByCurrency()` siblings).
- **Two-step wizard issuer→format picker** — unchanged; chain resolution runs post-import.
- **Phase 3's transaction-detail Livewire SFC** — chain drawer's "View chain" button mounts on this surface (D-90).
- **Phase 4's TransactionDetail Reclassify action** — defensive layering pattern (cross-user 404 + atomic save) the Phase 5 ConfirmChainLink action follows.
- **DI-only enforcement (Larastan boundary rule)** — every new Chains module service uses constructor DI; no facades, no helpers.
- **`brick/money` for any cross-currency arithmetic** in PayPal funding-chain when the funder amount differs from the merchant amount (USD purchase funded by EUR ASN account — uses settled-EUR amounts on both sides per Phase 3 D-42 / D-39).

### New Code Surface (Phase 5 adds)
- **`Modules/Chains/` bounded module** — Public: `ChainLinkQuery`, `ConfirmChainLink`, `RejectChainLink`, `CardStatementQuery`. Internal: `PaypalFundingResolver`, `IcsSettlementResolver`, `CardStatementStateMachine`, `ResolveChainLinksJob`, the review-queue Livewire SFCs, and the chain-drawer Livewire SFC.
- **`Modules/Chains/Database/Migrations/*_create_chain_links_table.php`** — `chain_links` schema per D-82.
- **`Modules/Chains/Database/Migrations/*_create_card_statements_table.php`** — `card_statements` schema per D-94.
- **`Modules/Chains/Database/Migrations/*_create_card_statement_credits_table.php`** — `card_statement_credits` schema per D-96.
- **`Modules/Chains/Database/Migrations/*_backpopulate_card_statements_from_statement_summaries.php`** — one-shot back-population per D-94.
- **`Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php`** — queued job per D-103.
- **`Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php`** — deterministic + fuzzy PayPal funding-chain logic, including D-106 hand-off.
- **`Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php`** — bulk-iDEAL decomposition per D-97 / D-98.
- **`Modules/Chains/Internal/CardStatementStateMachine.php`** — D-95 lifecycle transitions; only allowed mutator of `card_statements.state`.
- **`Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php`** — `/chains/review` Livewire SFC per D-86.
- **`Modules/Chains/Internal/Http/Livewire/ChainDrawer.php`** — side-drawer chain visualisation per D-90 / D-92 / D-93.
- **`Modules/Transfers/Public/Services/PairLookup.php`** — promotion of Phase 4's deferred Public surface per D-110.
- **`Modules/Chains/tests/fixtures/scenario-1/`** — synthesised cross-source matching fixture per D-107.
- **`scripts/synthesise_phase5_scenario.php`** — fixture generator per D-108.

### Established Patterns
- **DI-only** — every new Chains service injects collaborators via constructor; no facades, no global helpers. `ResolveChainLinksJob` injects `DatabaseManager` + `Modules\Transfers\Public\Services\PairLookup` + the two resolvers.
- **`Public/` vs `Internal/`** — Public surface ships with Phase 5 (D-109) because Phase 8 fixed-payments view + Phase 10 forecasting will consume it. Internal/ holds resolvers + the listener-shaped job + the state machine + the Livewire components.
- **Eloquent direct OK, but no facade calls** — `Transaction::query()->where('user_id', $user->id)` allowed; `DB::table(...)` forbidden (use `DatabaseManager` injected via constructor — same pattern as Phase 4's PairTransferCandidates listener).
- **Raw DatabaseManager query builder for whereBetween / whereIn / orderBy** — required by phpstan-strict-rules `staticMethod.dynamicCall` (D-103 resolver follows Phase 4 listener's pattern).
- **BoundaryArchTest invariants** — D-84 (resolver writes chain_links only), D-95 (only CardStatementStateMachine mutates card_statements.state), and a new "no Modules/Chains/ file calls `DB::*` facade" rule extend the existing `BoundaryArchTest` suite per the Phase 4 `noPaypalApiRoute` pattern.
- **Pest test layout** — resolver tests live next to the resolver (`Modules/Chains/tests/Unit/Resolvers/IcsSettlementResolverTest.php`). Feature tests for `/chains/review` + the chain drawer live under `tests/Feature/`. Cross-module idempotency / cross-user safety tests live under `tests/Contracts/` per Phase 1 convention.
- **Synthesised fixture-first Wave 0** — Phase 2/3/4 used real-anonymised fixtures; Phase 5 uses synthesised cross-source fixtures because cross-source matching is the deliberate gap prior phases left.
- **Toast for failed jobs** — D-103's failed-job notification follows Phase 4's TransactionDetail toast dispatch pattern (`$this->dispatch('toast', message: $message)` + Alpine `x-on:toast.window`).

### Integration Points
- **Schema** — THREE new migrations: `chain_links`, `card_statements`, `card_statement_credits`. ONE back-population migration. ZERO changes to `transactions` (D-84 invariant).
- **Composer dependencies** — TWO new direct dependencies: `laravel/horizon` (D-101) + `predis/predis` (D-101 default). NO new dev dependencies.
- **System dependencies** — Redis via Docker container (D-102). Docker daemon must be installed/running on the dev machine. README setup gains a "Redis service" section.
- **`ext-imap` invariant (PLT-05)** — Untouched (no email path in Phase 5).
- **Queue infrastructure** — NEW in Phase 5 (pulled forward from Phase 6). Redis driver + Horizon supervisor + `/horizon` dashboard + Filament-notifications-style failed-jobs toast. Phase 6 will extend with launchd plists for Redis + queue worker + Horizon process; Phase 5 requires `php artisan horizon` to run manually in a second terminal (documented in README).
- **PROJECT.md / STACK amendment** — D-101 + D-102 require an atomic edit to PROJECT.md alongside Phase 5 plans (mirrors Phase 4's ROADMAP SC#2 + REQUIREMENTS.md ING-09 edits). Plan 05-01 owns this.

### Risks Phase 5 Specifically Owns
- **PROJECT.md STACK override** — Reversing the "no Horizon, no Redis, no Docker" stack decision requires a careful PROJECT.md edit + README setup-doc update. Plan-phase owns the diff. Future phases inherit the new stack assumption; Phase 6 launchd plists will include Redis + queue:work + horizon.
- **First Docker dependency in the project** — the Sail anti-pattern PROJECT.md flags does NOT apply (network-only service, no bind mounts), but Docker daemon install is now a developer prerequisite. README documentation must be unambiguous.
- **First Flux drawer component** — D-90 introduces a new UI primitive. UI-SPEC plan-phase pass validates Flux's drawer ergonomics + keyboard handling against the calm aesthetic; snapshot tests for the drawer need fresh baselines.
- **First queued job in production code** — `ResolveChainLinksJob` is the project's first real queue dispatch. Per-user uniqueness, retry strategy, failed-job notification, Horizon dashboard wiring all need verification end-to-end. Wave 0 includes a smoke test that the job actually runs and completes.
- **D-94 back-population migration** — Existing `statement_summaries` rows must back-populate cleanly into `card_statements` without losing fidelity. Test against the Phase 3 ICS PDF fixture's statement_summary row. One-shot migration with explicit rollback strategy.
- **Synthesised fixture realism** — D-107's synthesised cross-source scenario must match real-world tolerances; if the synthesis is too clean (e.g. exact-to-the-cent reconciliation), the IcsSettlementResolver's tolerance handling (D-97) won't be exercised. Wave 0 synthesises BOTH a clean-match scenario AND an over-paid scenario AND an under-paid scenario to exercise all three tolerance arms.
- **Bulk-settle fan-out display** — D-93's full-height-no-scroll drawer with paginated fan-outs is a deliberate departure from typical drawer patterns. UI-SPEC plan-phase locks pagination interaction; snapshot tests cover the empty/single/N-page fan-out states.
- **Phase 4 "General Withdrawal NL" hand-off** — D-106 retroactively resolves the Phase 4 verification's documented limitation. Wave 0 fixture must include a PayPal NL "General Withdrawal" row whose deterministic IBAN match is exercised by the resolver, to demonstrate the close-out.
- **Per-user job uniqueness against same-user concurrent imports** — Two browser tabs both confirming an import within milliseconds: `ShouldBeUniqueUntilProcessing` keyed on user_id ensures only one resolver job runs. Test against this scenario in Wave 1.
- **`card_statements.state` machine race** — Two chain_link inserts arriving from a single resolver pass could both observe the same `partially_settled` state and write conflicting `settled` transitions. The CardStatementStateMachine (D-95) wraps state transitions in `SELECT ... FOR UPDATE` semantics (or SQLite equivalent: `BEGIN IMMEDIATE` + row read + state-conditional write).

</code_context>

<specifics>
## Specific Ideas

- **Async via Horizon is a deliberate override.** The user weighed the simplicity argument (PROJECT.md's "no Redis, no Horizon" stack) against production-grade queue observability and chose the observability side. The chain-resolution job is the project's first real async workload — having Horizon's `/horizon` dashboard surface in/out throughput, failed jobs, and per-job timing is worth the new Redis + Docker dependency.
- **Redis via Docker is a carve-out, not a full Sail pivot.** PROJECT.md's anti-Sail / anti-Docker stance is about bind-mount IO performance on Docker Desktop for Mac. A network-only Redis container has no bind mounts (data persists to a named Docker volume) and the IO traffic is HTTP-shaped, not filesystem-shaped. Docker daemon dependency is a real cost but is bounded to "have docker installed and running" — no Composer-level coupling, no app-in-container, no docker-compose-for-the-whole-stack.
- **The dedicated `/chains/review` page + inline drawer chips is intentional duplication.** Both surfaces exist because they serve different moments: dedicated page = "I'm going to batch-process my pending candidates this evening"; inline chips = "I just opened this transaction's chain and noticed an unconfirmed leg I want to fix RIGHT NOW". Same underlying action class powers both — minimal code duplication, two real user moments served.
- **Three-tier confidence chip hides the raw 0–1 number.** A personal-finance tool isn't an ML inspector. The user wants "is this a real link?" not "what's the model's posterior probability?". The three tiers (Deterministic / Confirmed / Candidate) map cleanly onto the resolver's actual confidence floor (1.0 / >=0.6+confirmed / >=0.6+unconfirmed).
- **`card_statements` as a first-class entity is the load-bearing data model choice.** Pitfall 4 is explicit: boolean `is_settled` is insufficient. A statement has period + total + open balance + state lifecycle + carry-forward credit attachment. Modelling it as a real row (back-populated from Phase 3's already-captured `statement_summaries`) is what makes D-96 carry-forward, D-98 refund-after-close, and D-100 forecast all clean. Trying to derive everything on-the-fly would scatter the same logic across queries.
- **Auto-promote after 3 confirmations is the conservative-learning default.** Aggressive 1-confirm auto-promotion poisons future matches on a single misclick. Never-promote leaves the user doing the same confirmation forever. 3 splits the difference cleanly and matches research/ARCHITECTURE.md's "if this feature combination was confirmed N times" pattern.
- **Reject is per-pair only.** The reject button is for "you got THIS one wrong"; it must not turn into "you got this whole MERCHANT wrong for life". Per-pair reject keeps the negative signal local and recoverable.
- **Chain drawer renders fully-expanded.** UI-02 says "user can drill into the FULL funding chain" — fully expanded honors that literally. Chains in the project's payment topology are short (≤5 levels); auto-expansion scales without becoming overwhelming.
- **Full-height, no-scroll drawer with paginated fan-outs.** This is a deliberate departure from typical drawer patterns. The reasoning: a chain with a 23-charge ICS fan-out should feel like a complete document, not a scroll-trap. Pagination within the fan-out block (rather than outer drawer scrolling) keeps the chain structure legible at a glance.
- **D-106 closes the Phase 4 PayPal NL "General Withdrawal" hand-off.** Phase 4 verification documented that NL "General Withdrawal" rows defaulted to expense when counterparty IBAN was absent, with Phase 5 chain resolver named as the resolution. D-106 makes good on that — the PayPal funding resolver inspects rawPayload.events[] for inferable destination IBANs and creates deterministic chain_links.
- **Wave 0 synthesises rather than waits for real data.** Phase 2/3/4 all used real-anonymised fixtures. Phase 5 can't — cross-source matching requires aligned data that no single phase produces. Synthesised fixtures (with deliberately varied reconciliation scenarios) exercise the resolver's full tolerance behavior end-to-end. The user's real ASN+ICS+PayPal exports validate Phase 5 in a follow-up "real-data smoke test" once they line up, but Phase 5 doesn't gate on that.

</specifics>

<deferred>
## Deferred Ideas

- **Recurring detection / cadence inference for ICS settlement lag** — Phase 5 ships a constant 5-day forecast lag (D-100). Phase 8's recurring-series detector refines the lag from the user's actual settlement history. Per-user configurable lag is v2.
- **Manual chain-link creation (user-driven "link these two rows" action)** — If the resolver misses a match the user wants to encode, Phase 5 has no manual-link UI. Add when proven needed; the chain drawer is the natural home for such an action.
- **Refund linking beyond ICS (PayPal refund → original PayPal purchase)** — Phase 5 closes ICS refund-after-statement-close (D-98). General PayPal refund linking is mentioned in research/PITFALLS.md L657 as commonly-missed; deferred to Phase 7 where email receipt matchers add another deterministic signal.
- **Multi-card support for ICS** — Phase 3 ICS-CARD synthetic-IBAN model holds; multi-card requires v4 fingerprint bump + per-card identity in chain_links. Deferred to a dedicated phase once card 2 actually arrives.
- **Horizon for multi-worker auto-balancing** — Phase 5 ships Horizon for observability with a single supervisor. Multi-supervisor balancing (Horizon's headline feature at scale) is unnecessary at single-user scale; ships out of the box but isn't exercised.
- **failed-jobs dedicated surface** — Phase 5 surfaces failed jobs via a Filament-notifications-style toast (D-103). A dedicated `/jobs` page with retry/inspect actions is Phase 6 work when launchd plists add scheduled-job orchestration.
- **launchd plists for Redis + queue:work + horizon** — Phase 5 requires manual `php artisan horizon` in a second terminal during development. Phase 6's launchd polish covers email scanning + chain resolution + redis-server orchestration uniformly.
- **`/horizon` dashboard auth hardening** — Phase 1's LoopbackOnly middleware + Fortify auth covers `/horizon` automatically (it's just another route). A Horizon-specific auth gate (`Horizon::auth(...)`) for finer-grained admin scoping is v2.
- **Cross-source merchant normalisation library** — D-88's signature uses `normalized_merchant`. Phase 1's merchant_memories table already does basic normalisation. A more sophisticated merchant alias / canonicalisation system (PITFALLS Pitfall 9) is Phase 7 work when email receipts surface the same merchant through multiple channels.
- **Chain-link kinds beyond `paypal_funding` and `ics_bulk_settle`** — Future kinds (`refund_of`, `recurring_member`, `email_receipt_match`) land in their own phases when those signals exist.
- **Aggressive auto-promotion threshold (after 1 confirmation)** — Rejected in favor of the conservative 3-confirm default (D-87). User can revisit if the 3-confirm threshold feels too slow in practice.
- **Permanent signature blacklist on reject** — Rejected in favor of per-pair reject (D-89). User can revisit if false-positive candidates re-surface repeatedly for the same signature.
- **Inline "View chain" icon on every `/transactions` list row** — Rejected in favor of detail-page-only entry point (calm aesthetic). User can revisit if the extra click to drill into detail proves friction-y.
- **Numeric confidence display in the chain drawer** — Rejected in favor of three-tier chip (D-91). The raw confidence is still in `chain_link.confidence` for any future audit / debug surface.
- **Synchronous resolver with structured-action escape hatch (option C from the sync/async question)** — Rejected when the user chose async-via-Horizon. The sync wrapper would have deferred queue infra to Phase 6; the async choice pulls it forward to Phase 5.
- **Herd Pro Redis service** — Rejected in favor of Docker Redis container (D-102). Keeps the project on free Herd as PROJECT.md assumes.
- **`brew install redis` + launchd plist** — Rejected in favor of Docker Redis container (D-102). User's explicit preference.

</deferred>

---

*Phase: 5-Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition)*
*Context gathered: 2026-05-16*
