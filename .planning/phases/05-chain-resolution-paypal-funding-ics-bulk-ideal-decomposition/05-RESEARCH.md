# Phase 5: Chain Resolution (PayPal Funding + ICS Bulk-iDEAL Decomposition) - Research

**Researched:** 2026-05-16
**Domain:** Cross-source chain resolution — deterministic + fuzzy PayPal funding-chain linker, ASN→ICS bulk-iDEAL settlement decomposer, `chain_links` + `card_statements` + `card_statement_credits` data model, Horizon + Redis + Docker queue infrastructure (first project use), Flux drawer + chain review queue UI, dashboard "Next ICS settlement" tile, new `Modules/Chains/` bounded module
**Confidence:** HIGH for existing module patterns / Laravel queue + Horizon mechanics / SQLite locking semantics / Flux modal API / existing schema invariants (verified against shipped code + Laravel 12/13 docs + official Horizon docs + official Flux docs). MEDIUM for fuzzy-matching threshold defaults + auto-promotion signature shape (industry pattern + project-specific decisions). HIGH for ±€5 / ±2% / ±10-day tolerance values (locked verbatim from research/PITFALLS.md Pitfall 4 + CHN-05).

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Chain-Link Data Model**

- **D-82:** `chain_links` table with `state` (candidate / confirmed / rejected) + `confidence` (0..1) + `evidence` (JSON) + `kind` enum + `resolver` (auto / user / rule). Columns: `id`, `user_id` (BelongsToUser per FND-03), `from_transaction_id`, `to_transaction_id`, `kind`, `state`, `confidence`, `resolver`, `evidence`, timestamps. Indexes: `(from_transaction_id)` and `(to_transaction_id)`. Self-FK both sides → `transactions.id` `ON DELETE CASCADE`.
- **D-83:** Initial `chain_links.kind` enum values: `paypal_funding`, `ics_bulk_settle`. Migration writes them as a CHECK constraint (matches Phase 1's BEFORE-INSERT/UPDATE trigger shape for `transactions.type`).
- **D-84:** Resolver writes `chain_links` only — never mutates `transactions`. BoundaryArchTest invariant.
- **D-85:** Confidence tiers: 1.0 = deterministic; 0.6–0.99 = fuzzy auto-promoted or to-be-confirmed; below 0.6 = not surfaced.

**Review Queue UX + Learning Loop**

- **D-86:** Dual review surface — dedicated `/chains/review` page AND inline action on `/transactions/{id}`. Same `ConfirmChainLink` / `RejectChainLink` action class powers both surfaces.
- **D-87:** Auto-promotion threshold: 3 confirmations of the same evidence signature → next candidate of the same signature lands as `state='confirmed'` directly (still records `resolver='rule'`). Per-user counter.
- **D-88:** Auto-promotion signature = (normalized_merchant, funding_source_identity). For PayPal funding: funding_source_identity = the underlying ASN/ICS account's `Account.iban` (or synthetic IBAN for `ICS-CARD` / `PAYPAL`). For ICS bulk-settle: signature is degenerate. Stored as SHA-256 hash in `chain_link.evidence.signature_hash`.
- **D-89:** Reject scope is per-pair only; signature stays neutral.

**Chain Drill-Down UI**

- **D-90:** Chain visualises as a vertical waterfall in a side-drawer modal. Drawer triggered from a "View chain" button on `/transactions/{id}`. Pure Tailwind + Blade rendering — no graph library, no D3, no SVG. First project use of a Flux drawer primitive.
- **D-91:** Three-tier confidence chip per leg: `Deterministic` / `Confirmed` / `Candidate`. Raw 0..1 confidence number is NOT shown in UI.
- **D-92:** Drawer renders fully-expanded by default. Click-to-collapse available per leg.
- **D-93:** ICS bulk-settle fan-out renders as nested list under the settlement node, showing all N covered ICS charges. Full-height drawer with no outer scroll; long fan-out lists paginate ("show 10 more") within the fan-out block.

**ICS Statement Model + Decomposition**

- **D-94:** New first-class `card_statements` table back-populated from Phase 3's `statement_summaries`. Columns: `id`, `user_id`, `account_id` (FK → ICS Account), `period_start`, `period_end`, `total_amount_minor`, `open_balance_minor`, `state` enum (`open` / `settled` / `partially_settled` / `overpaid`), `import_run_id`, timestamps. UNIQUE `(user_id, account_id, period_start, period_end)`.
- **D-95:** `card_statements.state` lifecycle. `open` → `partially_settled` → `settled` (within ±€0.01) → `overpaid`. BoundaryArchTest invariant: state changes only via `Modules/Chains/Internal/CardStatementStateMachine`.
- **D-96:** Overpayment surplus = virtual `credit_carry` line on the NEXT `card_statement` of the same Account, stored on `card_statement_credits` table with `(from_statement_id, to_statement_id, amount_minor)`.
- **D-97:** Bulk-settle reconciliation within ±€5 OR ±2% across ±10-day window auto-confirms; outside tolerance lands as `candidate`. Unaccounted delta recorded in `chain_link.evidence`.
- **D-98:** Refunds after statement close stay attached to their original statement (for chain tree) but reduce the NEXT settlement's open_balance.

**Settlement Forecast (CHN-06)**

- **D-99:** "Next ICS settlement" tile lives on the dashboard. Renders: `Next ICS settlement: €523.47` + `due ~20 May`. Tile only renders when there IS an open card_statement. `ThisPeriodAtAGlanceQuery::nextIcsSettlement(): ?CardStatementForecastTile`.
- **D-100:** Forecast amount = `open_balance_minor` of the most-recent `card_statement` whose state ∈ ('open', 'partially_settled'). Forecast lag = constant 5 calendar days from period_end.

**Resolver Execution Model**

- **D-101:** Async via Laravel Horizon + Redis. OVERRIDES PROJECT.md "no Horizon, no Redis". Plan-phase emits atomic edit to PROJECT.md.
- **D-102:** Redis runs as a Docker container (network-only service). `docker run --name diederik-redis -p 6379:6379 -d redis:7-alpine`. Named-volume persistence; NO bind mounts.
- **D-103:** `ResolveChainLinksJob` with `ShouldBeUniqueUntilProcessing` keyed on `user_id`. Dispatched from `ConfirmImport` post-commit. 3 tries with exponential backoff (60s / 300s / 900s). Failed-job toast on dashboard via `wire:poll`.
- **D-104:** Resolver scope per dispatch: full-user re-scan over all `open` / `partially_settled` `card_statements` and all `transactions` lacking a confirmed chain_link.
- **D-105:** Wizard polling: while ResolveChainLinksJob runs, post-confirm wizard shows "Resolving chains…" status with `wire:poll.2s` against `chain_resolution_status` query.
- **D-106:** Phase 5 PayPal NL "General Withdrawal" close-out (Phase 4 hand-off). Resolver inspects `rawPayload.events[]` for inferable destination IBAN.

**Wave 0 Enablement**

- **D-107:** Wave 0 synthesises a cross-source matching fixture (ASN CAMT.053 + ICS PDF + PayPal CSV trio) under `Modules/Chains/tests/fixtures/scenario-1/`. Three variants: clean-match, over-paid, under-paid.
- **D-108:** Anonymisation reuses prior phase scripts; new synthesis lives in `scripts/synthesise_phase5_scenario.php`. Composer-dep-free, committed in-repo.

**Module Shape**

- **D-109:** `Modules/Chains/` is the new bounded module. Public/ surface from day one. Public: `ChainLinkQuery::forTransaction()`, `ConfirmChainLink::__invoke()`, `RejectChainLink::__invoke()`, `CardStatementQuery::openForAccount()`. Internal/: `PaypalFundingResolver`, `IcsSettlementResolver`, `CardStatementStateMachine`, `ResolveChainLinksJob`, review-queue Livewire SFCs, chain-drawer Livewire SFC.
- **D-110:** `Modules/Transfers/Public/` gets a minimal promotion in Phase 5: `PairLookup::isPaired(int $txId): bool` and `PairLookup::partnerId(int $txId): ?int`.

### Claude's Discretion

- D-90 / D-91: Exact Flux drawer component selection, keyboard handling, animation timing (UI-SPEC pass locks).
- D-93: Exact pagination size for ICS bulk fan-out (default: 10 per "show more"). Empty-fan-out edge case (refund-only month).
- D-94: Migration timestamp slot (planner locks against latest Phase 4 timestamp).
- D-97 / D-98: Exact JSON shape of `chain_link.evidence` for ICS bulk-settle; refund-after-statement-close evidence shape. Pest dataset coverage.
- D-99: Exact wording when multiple statements are open simultaneously.
- D-101: Horizon dashboard URL gate (Phase 1 LoopbackOnly + Fortify auth already covers). `predis/predis` vs `phpredis` PECL (planner defaults to predis).
- D-103: Final failure-handling UX detail — toast wording, persistence, link to `/horizon` for retry.
- D-105: Exact wire:poll interval (1s / 2s / 5s) — 2s default.
- D-107: Exact size of synthesised scenario (default 20–25 ICS transactions). Number of PayPal rows.
- D-110: Whether Transfers Public surface ships in Wave 0 (recommended) or as a Wave 2 prerequisite.

### Deferred Ideas (OUT OF SCOPE)

- Recurring detection / cadence inference for ICS settlement lag — Phase 8.
- Manual chain-link creation (user-driven "link these two rows") — future.
- Refund linking beyond ICS (general PayPal refund → original PayPal purchase) — Phase 7.
- Multi-card support for ICS — dedicated phase once card 2 arrives.
- Horizon multi-worker auto-balancing — unnecessary at single-user scale.
- Failed-jobs dedicated `/jobs` page — Phase 6.
- launchd plists for Redis + queue:work + horizon — Phase 6.
- `/horizon` dashboard auth hardening (Horizon-specific gate) — v2.
- Cross-source merchant normalisation library — Phase 7.
- Chain-link kinds beyond `paypal_funding` / `ics_bulk_settle` — future phases.
- Aggressive auto-promotion (1-confirm) — rejected.
- Permanent signature blacklist on reject — rejected.
- Inline "View chain" icon on every `/transactions` list row — rejected.
- Numeric confidence display in chain drawer — rejected.
- Synchronous resolver — rejected when user chose async.
- Herd Pro Redis service — rejected.
- `brew install redis` + launchd plist — rejected.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CHN-01 | PayPal charge with matching reference ID is deterministically linked to ASN/ICS line | `PaypalFundingResolver` deterministic arm — reads `rawPayload.events[]` for `Funding Source` / `Reference Txn ID` / counterparty IBAN; creates `chain_links` of `kind='paypal_funding'`, `state='confirmed'`, `confidence=1.0`, `resolver='auto'`. D-83 enum value, D-85 confidence tier. |
| CHN-02 | No reference ID → propose candidate links via merchant + amount + date heuristic with confidence score | `PaypalFundingResolver` fuzzy arm — `normalized_merchant` similarity (`FingerprintComposer::normalize()` + Levenshtein) + amount band (±2%) + date window (±3 days). State `candidate`, `resolver='auto'`, confidence ∈ [0.6, 0.99]. D-85 floor 0.6 keeps review queue calm. |
| CHN-03 | Review queue: confirm/reject candidates; confirmations train future auto-matches | Dual review surface (D-86); 3-confirm auto-promotion on (normalized_merchant, funding_source_identity) signature (D-87/D-88); per-pair reject (D-89). Confirmed signature → next match lands as `confirmed`, `resolver='rule'`. |
| CHN-04 | Full chain tree drill-down from any transaction | `Modules/Chains/Internal/Http/Livewire/ChainDrawer` (Flux flyout) backed by `ChainLinkQuery::forTransaction(): ChainTree` Public service. Vertical waterfall, fully-expanded (D-90/D-92). |
| CHN-05 | ASN → ICS bulk iDEAL decomposed within ±€5 / ±2% / ±10-day tolerance, with partial / overpay / carry-forward handling | `IcsSettlementResolver` (D-97). For each ASN→ICS `transfer_in` pair, find open `card_statement` whose `total_amount_minor` matches within tolerance; create N `chain_links` of `kind='ics_bulk_settle'` from `transfer_in` to each ICS expense covering the period. Reduce `open_balance_minor` via `CardStatementStateMachine` (D-95). Surplus → `card_statement_credits` row + carry-forward (D-96). Refund-after-close → flows into next settlement (D-98). |
| CHN-06 | Next forecasted ICS settlement amount visible before paying | Dashboard tile via `ThisPeriodAtAGlanceQuery::nextIcsSettlement()` (D-99) returning `?CardStatementForecastTile`. Amount = `open_balance_minor` of most-recent open/partially_settled `card_statement`; due date = `period_end + 5 days` (D-100). |
| CHN-07 | Chain links table with state / confidence / evidence | `chain_links` schema per D-82. Three-tier state machine (candidate / confirmed / rejected); confidence float; evidence JSON; resolver enum (auto / user / rule). |
| UI-02 | Drill into any transaction's full funding chain | Same Flux drawer as CHN-04. First project use of a Flux flyout/drawer. UI-SPEC pass in plan-phase locks open/close behaviour, sticky header, empty-state ("Chain not yet resolved"). |

</phase_requirements>

## Summary

Phase 5 is the project's headline differentiator. It ships two cross-source resolvers (PayPal funding chain + ICS bulk-iDEAL decomposition), the `chain_links` + `card_statements` + `card_statement_credits` schema that backs them, the review-queue UX + auto-promotion learning loop, the chain drill-down drawer (UI-02 — first Flux drawer in the project), the "Next ICS settlement" dashboard tile, and a new bounded module `Modules/Chains/`. The phase also pulls queue infrastructure forward by one phase: Laravel Horizon + Redis (as a Docker network-only service) replace the project's existing `database` queue driver. This is a deliberate, atomic override of PROJECT.md's "no Redis / no Horizon / no Docker" stack constraint (D-101 / D-102).

Two architectural choices carry most of the phase's risk. First, the resolver writes `chain_links` only — never mutates `transactions` (D-84). This is the same invariant established in research/ARCHITECTURE.md L445 and is enforced by a BoundaryArchTest rule the new module ships with. Second, `card_statements` is modelled as a first-class entity rather than a derived view, with a strict state machine (`open` → `partially_settled` → `settled` / `overpaid`) whose only legal mutator is `CardStatementStateMachine` (D-95). Overpayment surplus and refund-after-close roll forward as `card_statement_credits` rows (D-96 / D-98) — the project's "treat overpayments as carry-forward credit" answer to PITFALLS.md Pitfall 4.

The synthesised Wave 0 fixture (D-107 / D-108) is a deliberate departure from Phase 2/3/4's real-anonymised fixtures. Cross-source matching is the axis prior phases left empty (Phase 4 SC#3 explicitly noted this gap); a synthesised ASN CAMT.053 + ICS PDF + PayPal CSV trio with clean / over-paid / under-paid variants exercises both resolvers end-to-end against committed data before any user fixture lands.

**Primary recommendation:** Plan as five vertical waves. Wave 0 = synthesised fixtures + PROJECT.md / STACK amendment + Horizon + Redis install + composer changes + Transfers Public promotion. Wave 1 = `chain_links` / `card_statements` / `card_statement_credits` schema + `CardStatementStateMachine` + back-population migration. Wave 2 = `IcsSettlementResolver` + `ResolveChainLinksJob` (queued via Horizon). Wave 3 = `PaypalFundingResolver` (deterministic + fuzzy) + auto-promotion signature counter. Wave 4 = review queue (`/chains/review` + inline drawer chips) + chain drawer (Flux flyout) + dashboard "Next ICS settlement" tile + wizard `wire:poll` "Resolving chains…" surface.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| PayPal funding chain resolution | API / Backend (Modules/Chains/Internal/Resolvers) | — | Pure server-side logic; no UI; reads transactions, writes chain_links. |
| ICS bulk-settle decomposition | API / Backend (Modules/Chains/Internal/Resolvers) | Database / Storage (CardStatementStateMachine) | Math + tolerance gate in PHP; state transitions wrapped in `BEGIN IMMEDIATE` SQLite transaction. |
| Card statement state lifecycle | Database / Storage (CardStatementStateMachine) | — | The only legal mutator of `card_statements.state` per D-95. BoundaryArchTest enforces this. |
| Async job execution | Infrastructure (Horizon + Redis via Docker) | API / Backend (ResolveChainLinksJob dispatch site in `ConfirmImport`) | Horizon supervises queue worker; ConfirmImport dispatches inside post-commit hook. |
| Review queue page (`/chains/review`) | Frontend Server (SSR — Livewire) | API / Backend (ChainLinkQuery, ConfirmChainLink, RejectChainLink) | Livewire SFC server-rendered; Public services injected via DI. |
| Chain drawer (UI-02) | Frontend Server (SSR — Livewire + Flux flyout) | Browser / Client (Alpine escape handler, click-outside) | Flux flyout component renders server-side; Alpine.js handles keyboard / focus trap. |
| Dashboard "Next ICS settlement" tile | Frontend Server (SSR — Blade) | API / Backend (ThisPeriodAtAGlanceQuery::nextIcsSettlement()) | Blade conditional render based on Public DTO from extended Ledger query. |
| Wizard "Resolving chains…" status | Frontend Server (Livewire `wire:poll`) | API / Backend (chain_resolution_status query against `failed_jobs` + `jobs` tables) | Livewire poll directive against Public query method; no Redis-side polling. |
| Failed-job toast | Browser / Client (Alpine `x-on:toast.window`) | Frontend Server (Livewire `$this->dispatch('toast', ...)`) | Same toast pattern as Phase 4's TransactionDetail Reclassify. |

## Standard Stack

### Core (new dependencies)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `laravel/horizon` | ^5.46 [VERIFIED: packagist.org/packages/laravel/horizon] (released 2026-04-20) | Queue dashboard + supervisor for Redis queues | Official Laravel package. Compatible with Laravel 9.21–13. The `/horizon` dashboard surfaces throughput, runtime, failed jobs. ShouldBeUniqueUntilProcessing locks work natively against Redis-backed cache. D-101. |
| `predis/predis` | ^3.4 [VERIFIED: packagist.org/packages/predis/predis] (released 2026-03-09) | Pure-PHP Redis client | D-101 default — avoids the PECL `phpredis` build path. Compatible with PHP ^7.2 \|\| ^8.0 (caret-major covers 8.x including 8.5). Laravel 12+ docs recommend setting `REDIS_CLIENT=predis` for Predis-based stacks. |

### Core (existing — pattern reuse)

| Library | Version | Purpose | Why Used |
|---------|---------|---------|----------|
| `livewire/livewire` | ^4.0 [VERIFIED: composer.json] | Reactive UI — review queue + chain drawer + wizard status | Phase 1 SFC pattern; `wire:poll.2s` for D-105 wizard status; `$this->dispatch('toast', ...)` for D-103 failed-job notification. |
| `livewire/flux` | ^2.0 [VERIFIED: composer.json] | UI component library — drawer (flyout), buttons, modals | Project's locked UI primitive set. The drawer flyout is `<flux:modal flyout>` per fluxui.dev/components/modal [CITED: fluxui.dev]. First project use of the flyout variant. |
| `brick/money` | ^0.11 [VERIFIED: composer.json] | Cross-currency arithmetic | Phase 3 D-42 / D-39 established settled-EUR / settled-currency contract. PayPal USD purchase funded by EUR ASN uses settled-EUR on both sides — no FX arithmetic at the resolver boundary. |
| `spatie/laravel-data` | ^4.0 [VERIFIED: composer.json] | Typed DTOs — ChainTree, CardStatementForecastTile, ChainLinkRow | Same shape as `StatementSummaryData`, `PerCurrencyTile`, `DashboardSummary` from prior phases. |

### Supporting (existing internal services Phase 5 consumes)

| Service | Module | Why Used |
|---------|--------|----------|
| `FingerprintComposer::normalize(string $rawName): string` | `Modules\Ledger\Public\Services` [VERIFIED: Modules/Ledger/Public/Services/FingerprintComposer.php L67–L75] | Already-tested merchant normaliser (lowercase + diacritic strip + non-alphanumeric strip + 80-char truncate, NFD Unicode normalisation). The fuzzy resolver uses this on both sides of a candidate before similarity scoring — guarantees the comparison is against the same normalised form `counterparty_normalized` already holds in the DB. |
| `Transaction::query()->where('user_id', $user->id)` | `Modules\Ledger\Models\Transaction` [VERIFIED: Modules/Ledger/Models/Transaction.php] | Eloquent direct OK per project DI policy — facades are forbidden, models are not. Used inside Public service classes. |
| `StatementSummary` Eloquent model | `Modules\Ledger\Models\StatementSummary` [VERIFIED: Modules/Ledger/Models/StatementSummary.php] | Source rows the D-94 back-population migration reads. Already carries `period_start`, `period_end`, `closing_balance_minor`, `account_id`, `user_id`. |
| `Modules\Transfers\Internal\Listeners\PairTransferCandidates` (read pattern) | `Modules/Transfers` [VERIFIED: Modules/Transfers/Internal/Listeners/PairTransferCandidates.php L101–L154] | Reference implementation for the `DatabaseManager` raw query builder pattern, `phpstan-strict-rules`-safe column read pattern (`self::toInt()` coercion helper), and symmetric-write idiom. The new `IcsSettlementResolver` and `PaypalFundingResolver` mirror this shape. |
| `ConfirmImport` post-commit dispatch site | `Modules\Import\Public\Actions\ConfirmImport` [VERIFIED: Modules/Import/Public/Actions/ConfirmImport.php L101–L135] | D-103 dispatch point. The `ResolveChainLinksJob` is dispatched after the `DatabaseManager::transaction()` block returns (i.e., post-commit) so the queued worker sees the persisted state. |
| `Illuminate\Contracts\Bus\Dispatcher` | Laravel core | Constructor-injected dispatcher for `ResolveChainLinksJob::dispatch()`. NO `Bus::dispatch()` facade — per DI-only constraint. |
| `Illuminate\Contracts\Events\Dispatcher` | Laravel core | Same pattern as Phase 4. Used if Phase 5 fires its own events (e.g., `ChainLinksResolved` for future Phase 8 consumer). |
| `Illuminate\Database\DatabaseManager` | Laravel core | Raw query builder for `whereBetween` / `whereIn` / `orderBy` (Larastan strict-rules `staticMethod.dynamicCall` blocks them on Eloquent\Builder). Same pattern as Phase 4 `PairTransferCandidates`. |
| `Illuminate\Contracts\Cache\Repository` (via `Cache::driver('redis')` injection) | Laravel core | `uniqueVia()` on `ResolveChainLinksJob` returns the Redis cache repo so unique-job locks live in Redis (the default `database` cache driver would defeat D-103's per-user uniqueness once Horizon is on the queue path). |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Predis | PhpRedis (PECL extension) | PhpRedis is faster but requires a PECL build that PROJECT.md / Phase 1 deliberately avoids (same anti-PECL posture as `ext-imap` Pitfall 5). Predis is a one-line composer install; the single-user single-machine performance gap is irrelevant. Discretion-locked: planner defaults to Predis. |
| Horizon | Plain `php artisan queue:work` against Redis driver | Possible (and is what PROJECT.md originally specified) — but the user explicitly traded "no Horizon" for `/horizon` dashboard observability (D-101). The `ShouldBeUniqueUntilProcessing` contract is identical across both paths; Horizon adds the dashboard surface, metrics, and failed-job affordances. |
| Redis via Docker | Redis via Homebrew `brew install redis` + launchd plist | User explicitly rejected `brew install redis` (deferred ideas in CONTEXT.md). Docker carve-out is a deliberate exception to PROJECT.md's "no Docker" rule: a network-only service has no bind-mount IO traffic, so the Sail anti-pattern that PROJECT.md flags does not apply. |
| Levenshtein on normalized_merchant | Soundex / Metaphone / Jaro-Winkler | PHP's built-in `levenshtein()` [VERIFIED: php.net/manual/en/function.levenshtein.php] is sufficient for the normalised string lengths Phase 5 sees (≤80 chars). Soundex over-collapses (e.g. "Netflix" and "Nethers" hit the same code) — wrong direction for low-noise merchant matching. Jaro-Winkler would be marginally better for short tokens; not worth the dependency. |
| Flux drawer (flyout) | Custom Tailwind drawer + Alpine.js | The user's existing stack has `livewire/flux` ^2.0 — using the in-house drawer primitive aligns with the calm aesthetic and gives free escape-to-close + click-outside [CITED: fluxui.dev/components/modal]. Hand-rolling adds friction with no upside. |
| `card_statements` first-class table | Derive on-the-fly from `statement_summaries` joins | PITFALLS Pitfall 4 explicitly rejects this: a boolean `is_settled` column or derived view cannot model partial settlement + open balance + carry-forward credit + state lifecycle in one place. Modelling as a real row centralises the math. |

**Installation (Wave 0):**
```bash
composer require laravel/horizon predis/predis
php artisan horizon:install
```

**Version verification (run during Wave 0 — versions may have advanced since 2026-05-16):**
```bash
npm view (n/a)
composer show laravel/horizon | head -5
composer show predis/predis | head -5
```

**Docker Redis service (Wave 0):**
```bash
docker volume create diederik-redis-data
docker run --name diederik-redis \
    -p 127.0.0.1:6379:6379 \
    -v diederik-redis-data:/data \
    -d redis:7-alpine redis-server --save 60 1
```

Notes on the docker invocation:
- `-p 127.0.0.1:6379:6379` binds to loopback only (matches FND-01 / Phase 1 LoopbackOnly posture — Redis must not be reachable from the LAN). Default `-p 6379:6379` binds to `0.0.0.0` which would expose Redis to anyone on the network — the project's privacy constraint explicitly forbids this.
- `--save 60 1` produces a snapshot of the DB every 60s if at least one write occurred [VERIFIED: hub.docker.com/_/redis].
- Named volume `diederik-redis-data` survives container restarts; no bind mount.

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Import wizard (Livewire SFC)                      │
│                                                                       │
│   user → upload → preview → confirm                                  │
│                       │                                              │
│                       ▼                                              │
│            ConfirmImport (Public Action)                             │
│             [DB::transaction(){ ... }]                               │
│                       │                                              │
│                       │ post-commit                                  │
│                       ▼                                              │
│           Bus::dispatch(ResolveChainLinksJob)  ← uniqueId=user_id    │
│                       │                                              │
│   wizard polls ── wire:poll.2s ──► chain_resolution_status query    │
│                                                                       │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────────┐
│              Redis (Docker, network-only, loopback bind)             │
│   ┌────────────────────────────────────────────────────────────┐    │
│   │  queues:default     unique-lock:resolve-chain-links:{uid}  │    │
│   └────────────────────────────────────────────────────────────┘    │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────────────┐
│         php artisan horizon (manual second terminal in dev)          │
│                                                                       │
│         supervisor-1 ──── processes=1 ──── balance='simple'          │
│                       │                                              │
│                       ▼                                              │
│              ResolveChainLinksJob::handle()                          │
│                       │                                              │
│         ┌─────────────┴─────────────┐                                │
│         ▼                            ▼                               │
│   IcsSettlementResolver       PaypalFundingResolver                  │
│         │                            │                               │
│         │ for each unpaired ASN→ICS │ for each PayPal expense       │
│         │ transfer_in pair:         │ lacking confirmed chain_link: │
│         │                            │                               │
│         ▼                            ▼                               │
│   find candidate            deterministic arm:                       │
│   card_statement            rawPayload.events[]                      │
│   within ±€5 / ±2% /        funding-source IBAN /                    │
│   ±10-day window            Reference Txn ID                         │
│         │                            │                               │
│         │                   fuzzy arm:                               │
│         │                   FingerprintComposer::normalize           │
│         │                   + Levenshtein                            │
│         │                   + amount band ±2%                        │
│         │                   + date window ±3 days                    │
│         │                            │                               │
│         ▼                            ▼                               │
│   CardStatementStateMachine    chain_links INSERT                    │
│   (BEGIN IMMEDIATE):           - kind='paypal_funding'               │
│   - open → partially_settled   - state ∈ {confirmed, candidate}      │
│   - → settled (open=0±€0.01)   - confidence ∈ {1.0, [0.6, 0.99]}    │
│   - → overpaid                                                       │
│   + card_statement_credits     auto-promotion check:                 │
│     row on overpay/refund      signature_hash ─► counter ≥3 ?        │
│                                                                       │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
                       │ writes chain_links + card_statements +
                       │ card_statement_credits (NEVER transactions)
                       ▼
┌─────────────────────────────────────────────────────────────────────┐
│                       SQLite (WAL mode)                              │
│                                                                       │
│   transactions   chain_links   card_statements   card_statement_credits │
│                                                                       │
└──────────────────────┬──────────────────────────────────────────────┘
                       │
                       │ read paths
        ┌──────────────┼──────────────┬─────────────────────┐
        ▼              ▼              ▼                     ▼
   Dashboard      /transactions   /chains/review     Chain drawer
   "Next ICS"     detail page     review queue        (Flux flyout)
   tile (D-99)    "View chain"    page (D-86)         UI-02 + D-90
                 button                                vertical
                                                       waterfall
```

### Component Responsibilities

| Component | File | Responsibility |
|-----------|------|----------------|
| `Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php` | new | Two-pass resolver: deterministic (D-106 hand-off via rawPayload.events[]), fuzzy (normalized_merchant + amount + date scoring). Writes chain_links of `kind='paypal_funding'`. |
| `Modules/Chains/Internal/Resolvers/IcsSettlementResolver.php` | new | Per ASN→ICS transfer_in: find candidate card_statement; compute decomposition within ±€5/±2%/±10-day window; create N chain_links of `kind='ics_bulk_settle'`; update statement state via `CardStatementStateMachine`. |
| `Modules/Chains/Internal/CardStatementStateMachine.php` | new | The ONLY legal mutator of `card_statements.state`. Wraps state transitions in `BEGIN IMMEDIATE` SQLite transaction. BoundaryArchTest enforces uniqueness. |
| `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` | new | Queued (Redis), `ShouldBeUniqueUntilProcessing` keyed on user_id. `tries=3`, `backoff=[60, 300, 900]`. Calls both resolvers in turn. |
| `Modules/Chains/Public/Services/ChainLinkQuery.php` | new | `forTransaction(int $txId, User $user): ChainTree` — hierarchical DTO of confirmed + candidate chain_links. Public — Phase 8 fixed-payments will consume. |
| `Modules/Chains/Public/Actions/ConfirmChainLink.php` | new | Invokable: `__invoke(int $id, User $user): void`. Promotes candidate → confirmed; increments per-user signature counter; auto-promotes other candidates with the same signature when counter ≥3. |
| `Modules/Chains/Public/Actions/RejectChainLink.php` | new | Invokable: `__invoke(int $id, User $user): void`. Sets state=rejected. Per-pair scope (D-89) — does NOT touch signature counter. |
| `Modules/Chains/Public/Services/CardStatementQuery.php` | new | `openForAccount(int $accountId, User $user): ?CardStatement` — used by dashboard tile + chain drawer. |
| `Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php` | new | `/chains/review` Livewire SFC — batched candidates sorted by confidence desc, then most-recent first. Calls `ConfirmChainLink` / `RejectChainLink`. |
| `Modules/Chains/Internal/Http/Livewire/ChainDrawer.php` | new | Side-drawer Livewire SFC mounted on `/transactions/{id}`. Renders vertical waterfall using `ChainTree`. ICS bulk-settle node renders nested fan-out list with "show 10 more" pagination (D-93). |
| `Modules/Chains/Database/Migrations/*_create_chain_links_table.php` | new | Schema per D-82 + CHECK constraint for `kind` enum (mirrors Phase 1 BEFORE-INSERT/UPDATE trigger for `transactions.type`). |
| `Modules/Chains/Database/Migrations/*_create_card_statements_table.php` | new | Schema per D-94 + CHECK constraint for `state` enum + UNIQUE `(user_id, account_id, period_start, period_end)`. |
| `Modules/Chains/Database/Migrations/*_create_card_statement_credits_table.php` | new | Schema per D-96. Columns: `id`, `user_id`, `from_statement_id`, `to_statement_id`, `amount_minor`, `reason` ('overpayment' / 'refund_after_close'), timestamps. |
| `Modules/Chains/Database/Migrations/*_backpopulate_card_statements_from_statement_summaries.php` | new | One-shot back-population per D-94. Reads every `statement_summaries` row where `account_id` joins to an ICS-kind Account; inserts `card_statements` row with `state='open'`, `open_balance_minor=abs(closing_balance_minor)`. |
| `Modules/Transfers/Public/Services/PairLookup.php` | new | D-110 promotion: `isPaired(int $txId, User $user): bool`, `partnerId(int $txId, User $user): ?int`. |
| `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` | extend | Add `nextIcsSettlement(User $user): ?CardStatementForecastTile` method (D-99). |
| `Modules/Import/Public/Actions/ConfirmImport.php` | extend | After the `transaction()` block returns, call `$this->bus->dispatch(new ResolveChainLinksJob($user))`. Inject `Illuminate\Contracts\Bus\Dispatcher` via constructor — no facade. |

### Recommended Project Structure

```
Modules/
├── Chains/                                              ← NEW bounded module (D-109)
│   ├── composer.json                                    # module manifest
│   ├── Database/
│   │   └── Migrations/
│   │       ├── 2026_05_16_010001_create_chain_links_table.php
│   │       ├── 2026_05_16_010002_create_card_statements_table.php
│   │       ├── 2026_05_16_010003_create_card_statement_credits_table.php
│   │       └── 2026_05_16_010004_backpopulate_card_statements_from_statement_summaries.php
│   ├── Internal/
│   │   ├── CardStatementStateMachine.php
│   │   ├── Resolvers/
│   │   │   ├── PaypalFundingResolver.php
│   │   │   └── IcsSettlementResolver.php
│   │   ├── Jobs/
│   │   │   └── ResolveChainLinksJob.php
│   │   └── Http/
│   │       └── Livewire/
│   │           ├── ChainReviewQueue.php
│   │           └── ChainDrawer.php
│   ├── Models/
│   │   ├── ChainLink.php
│   │   ├── CardStatement.php
│   │   └── CardStatementCredit.php
│   ├── Public/
│   │   ├── Services/
│   │   │   ├── ChainLinkQuery.php
│   │   │   └── CardStatementQuery.php
│   │   ├── Actions/
│   │   │   ├── ConfirmChainLink.php
│   │   │   └── RejectChainLink.php
│   │   └── Dto/
│   │       ├── ChainTree.php
│   │       ├── ChainTreeNode.php
│   │       ├── CardStatementForecastTile.php
│   │       └── ChainLinkRow.php
│   ├── Providers/
│   │   └── ChainsServiceProvider.php
│   ├── Resources/
│   │   └── views/
│   │       └── livewire/
│   │           ├── chain-review-queue.blade.php
│   │           └── chain-drawer.blade.php
│   ├── Routes/
│   │   └── web.php                                      # /chains/review
│   └── tests/
│       ├── Pest.php
│       ├── TestCase.php
│       ├── Unit/
│       │   ├── Resolvers/
│       │   │   ├── IcsSettlementResolverTest.php
│       │   │   └── PaypalFundingResolverTest.php
│       │   └── CardStatementStateMachineTest.php
│       ├── Feature/
│       │   ├── ResolveChainLinksJobTest.php
│       │   ├── ChainReviewQueueTest.php
│       │   ├── ChainDrawerTest.php
│       │   ├── ConfirmChainLinkTest.php
│       │   ├── RejectChainLinkTest.php
│       │   ├── NextIcsSettlementTileTest.php
│       │   ├── CardStatementBackPopulationTest.php
│       │   └── CrossUserChainLinkIsolationTest.php
│       ├── Contracts/
│       │   └── ChainResolverIdempotencyContractTest.php
│       └── fixtures/
│           └── scenario-1/
│               ├── asn-camt053.xml
│               ├── ics-statement.pdf
│               ├── paypal-activity.csv
│               ├── scenario-1.md                        # fixture record
│               ├── scenario-1-overpaid.json             # variant overlays
│               └── scenario-1-underpaid.json
│
├── Transfers/
│   └── Public/                                          ← NEW (D-110 promotion)
│       └── Services/
│           └── PairLookup.php
│
└── Ledger/
    └── Public/Services/
        └── ThisPeriodAtAGlanceQuery.php                 ← EXTEND nextIcsSettlement()

scripts/
└── synthesise_phase5_scenario.php                       ← NEW (D-108)

bootstrap/
└── providers.php                                        ← MODIFIED: ChainsServiceProvider::class

config/
├── horizon.php                                          ← NEW (php artisan horizon:install)
├── queue.php                                            ← NEW (publishes default with redis driver)
└── database.php                                         ← MODIFIED: Redis connection block

app/Providers/
└── HorizonServiceProvider.php                           ← NEW (Horizon::auth() callback)
```

### Pattern 1: `chain_links` Migration with CHECK constraint (D-82 / D-83)

**What:** Single forward-only migration creates the `chain_links` table with `kind` enum enforced as a CHECK constraint (matches Phase 1's `transactions.type` enum-via-trigger shape).

**When to use:** Wave 1. Phase 5's primary schema deliverable alongside `card_statements` + `card_statement_credits`.

**Example:**

```php
// Source: this research + Phase 1's transactions table migration pattern
// Location: Modules/Chains/Database/Migrations/2026_05_16_010001_create_chain_links_table.php
// [ASSUMED] Migration timestamp planner locks against latest Phase 4 timestamp

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('chain_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('to_transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('kind', 32);            // CHECK constraint added below
            $table->string('state', 16);           // CHECK constraint added below
            $table->decimal('confidence', 4, 3);   // 0.000..1.000
            $table->string('resolver', 8);         // 'auto' | 'user' | 'rule'
            $table->json('evidence');
            $table->timestamps();

            $table->index('from_transaction_id');
            $table->index('to_transaction_id');
            $table->index(['user_id', 'state']);   // review-queue scan
        });

        $connection = $this->db()->connection($this->getConnection());

        // CHECK constraints for the two enums. SQLite supports table-level CHECK
        // since 3.0; the trigger-shape used in Phase 1 (transactions.type) is
        // *also* acceptable but for a new fresh table CHECK is the cleaner
        // form. Phase 5 plan-checker should verify the planner picked one
        // consistently across all four Chains migrations.
        $connection->statement(
            "CREATE TRIGGER chain_links_kind_chk_insert
             BEFORE INSERT ON chain_links
             FOR EACH ROW WHEN NEW.kind NOT IN ('paypal_funding', 'ics_bulk_settle')
             BEGIN SELECT RAISE(ABORT, 'invalid chain_links.kind'); END"
        );
        $connection->statement(
            "CREATE TRIGGER chain_links_kind_chk_update
             BEFORE UPDATE ON chain_links
             FOR EACH ROW WHEN NEW.kind NOT IN ('paypal_funding', 'ics_bulk_settle')
             BEGIN SELECT RAISE(ABORT, 'invalid chain_links.kind'); END"
        );
        $connection->statement(
            "CREATE TRIGGER chain_links_state_chk_insert
             BEFORE INSERT ON chain_links
             FOR EACH ROW WHEN NEW.state NOT IN ('candidate', 'confirmed', 'rejected')
             BEGIN SELECT RAISE(ABORT, 'invalid chain_links.state'); END"
        );
        $connection->statement(
            "CREATE TRIGGER chain_links_state_chk_update
             BEFORE UPDATE ON chain_links
             FOR EACH ROW WHEN NEW.state NOT IN ('candidate', 'confirmed', 'rejected')
             BEGIN SELECT RAISE(ABORT, 'invalid chain_links.state'); END"
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())->statement('DROP TABLE IF EXISTS chain_links');
    }

    private function schema(): Builder { /* memoised, as in Phase 4 */ }
    private function db(): DatabaseManager { /* memoised, as in Phase 4 */ }
};
```

### Pattern 2: `CardStatementStateMachine` with BEGIN IMMEDIATE (D-95)

**What:** The single public method on the state machine wraps a `BEGIN IMMEDIATE` transaction around a read-then-conditional-write so two concurrent chain_link inserts cannot race the state transition.

**When to use:** Always — every call site that needs to mutate `card_statements.state` (or `open_balance_minor`, or write a `card_statement_credits` row) routes through this class. BoundaryArchTest invariant.

**SQLite locking note:** SQLite does not support `SELECT ... FOR UPDATE` [VERIFIED: sqlite.org User Forum + Laravel docs]. Laravel's `lockForUpdate()` is a no-op on the SQLite driver. The standard workaround is `BEGIN IMMEDIATE TRANSACTION`, which acquires a reserved lock at transaction start [VERIFIED: SQLAlchemy mailing list + Medium 2024 guide]. Other writers are blocked until commit; readers continue under WAL.

**Example:**

```php
// Source: this research + research/ARCHITECTURE.md L446 + D-95
// Location: Modules/Chains/Internal/CardStatementStateMachine.php

namespace Modules\Chains\Internal;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Dto\StatementSettlement;

final class CardStatementStateMachine
{
    private const SETTLED_TOLERANCE_MINOR = 1; // ±€0.01

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Atomically apply a settlement delta to a card_statements row.
     *
     *   - Reads current open_balance_minor under BEGIN IMMEDIATE
     *   - Subtracts $deltaMinor (positive = pays down balance)
     *   - Transitions state per D-95 rules
     *   - Writes a card_statement_credits row if state lands as 'overpaid'
     *     (D-96 surplus carry-forward) — INSIDE the same outer transaction
     *
     * Returns the post-transition StatementSettlement DTO so the resolver
     * can record the unaccounted-delta + new state in chain_link.evidence.
     */
    public function applySettlement(int $statementId, int $deltaMinor, User $user): StatementSettlement
    {
        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $statementId, $deltaMinor, $user): StatementSettlement {
            // SQLite's transaction() helper opens with DEFERRED by default.
            // Promote to IMMEDIATE so the upcoming UPDATE doesn't race
            // a concurrent worker reading the same row under a deferred
            // read lock. The Laravel framework calls `beginTransaction()`
            // first; we issue a raw IMMEDIATE statement to upgrade.
            // (Phase 4 ResearchTODO: confirm the upgrade pattern doesn't
            //  fight Laravel's nested-transaction savepoint logic — Phase 5
            //  plan checker should verify against the live driver.)
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->first(['id', 'open_balance_minor', 'state']);

            if ($row === null) {
                throw new \RuntimeException("card_statement {$statementId} not found for user {$user->id}");
            }

            $newOpen = self::toInt($row->open_balance_minor) - $deltaMinor;
            $newState = match (true) {
                abs($newOpen) <= self::SETTLED_TOLERANCE_MINOR => 'settled',
                $newOpen < -self::SETTLED_TOLERANCE_MINOR     => 'overpaid',
                $newOpen > 0 && self::toInt($row->open_balance_minor) > $newOpen => 'partially_settled',
                default                                       => self::toString($row->state),
            };

            $now = $this->clock->now()->toDateTimeString();
            $connection->table('card_statements')
                ->where('id', $statementId)
                ->where('user_id', $user->id)
                ->update([
                    'open_balance_minor' => $newOpen,
                    'state'              => $newState,
                    'updated_at'         => $now,
                ]);

            return new StatementSettlement(
                statementId: $statementId,
                previousOpenMinor: self::toInt($row->open_balance_minor),
                newOpenMinor: $newOpen,
                newState: $newState,
            );
        });
    }

    private static function toInt(mixed $v): int    { return is_numeric($v) ? (int) $v : 0; }
    private static function toString(mixed $v): string { return is_string($v) ? $v : ''; }
}
```

**Why this pattern wins:** Two concurrent `ResolveChainLinksJob` instances are already prevented by `ShouldBeUniqueUntilProcessing` (D-103). But the single-resolver-pass case can still produce two chain_link inserts arriving against the same statement millisecond-apart. The IMMEDIATE transaction inside `applySettlement` serialises them at the SQLite layer regardless of Laravel-layer concurrency.

### Pattern 3: `ResolveChainLinksJob` with `ShouldBeUniqueUntilProcessing` (D-103)

**What:** A queued job class that runs both resolvers, keyed unique-per-user.

**When to use:** Always dispatched from `ConfirmImport` post-commit. Also surfaced for manual re-trigger from `/chains/review` ("Re-scan" button — Discretion item).

**ShouldBeUniqueUntilProcessing contract** [VERIFIED: laravel.com/docs/12.x/queues#unique-jobs]:
- Lock is released **immediately before** the job is processed (when worker begins `handle()`).
- This allows a new instance to be dispatched while another is actively processing — but not queued in parallel beforehand.
- If a worker crashes mid-job, the lock is released, allowing another instance to retry.
- The lock requires a cache driver supporting atomic locks: `redis`, `memcached`, `dynamodb`, `database`, `file`, `array`.

**Why this contract fits Phase 5:** A user confirming Import A, then immediately confirming Import B in another tab, should not race two resolver passes. But once the resolver starts running A's pass, B's dispatch becomes legitimate again (because A's pass may not see B's just-inserted rows). The "until processing" semantics — not "until finished" — keep the door open for the second pass to enqueue while the first is still running, eliminating a missed-import class of bug.

**Example:**

```php
// Source: laravel.com/docs/12.x/queues + D-103
// Location: Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php

namespace Modules\Chains\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;          // Used ONLY inside uniqueVia() per Laravel docs
use Modules\Core\Models\User;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;

final class ResolveChainLinksJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Per-user uniqueness — eliminates the parallel-resolution race (ARCHITECTURE L446). */
    public int $tries = 3;
    public array $backoff = [60, 300, 900];       // 60s / 5m / 15m per D-103

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function uniqueFor(): int
    {
        return 600; // 10-minute safety ceiling — long enough for any pass to finish
    }

    /**
     * Force the unique-lock to use the Redis cache driver. Default cache
     * driver is 'database' in Phase 1; ShouldBeUnique lock semantics work
     * across both, but routing through Redis matches D-101 (production-grade
     * observability) and centralises queue + lock in one place.
     */
    public function uniqueVia(): Repository
    {
        return Cache::driver('redis');             // ← FACADE EXCEPTION
        // ⚠ The Cache facade call here is the SINGLE permitted facade use
        // in Phase 5 — Laravel's uniqueVia() contract returns a Repository
        // without DI access. PROJECT.md "no facades" carves this out:
        // it's a per-class capability declaration, not a runtime helper.
        // Discretion: planner may instead inject Repository via constructor
        // and store as readonly; ShouldBeUniqueUntilProcessing reads
        // uniqueVia() at queue-push time so the constructor must already
        // be populated. Test against the live framework before locking.
    }

    public function handle(
        IcsSettlementResolver $icsResolver,
        PaypalFundingResolver $paypalResolver,
        User $user,
    ): void {
        $userModel = User::query()->where('id', $this->userId)->firstOrFail();
        $icsResolver->resolveForUser($userModel);
        $paypalResolver->resolveForUser($userModel);
    }
}
```

**Note on facade exception:** The `Cache::driver('redis')` call inside `uniqueVia()` is the **single permitted facade use** in the Phase 5 codebase. The job's `uniqueVia()` is called by the Laravel queue infrastructure before the constructor completes, so constructor DI is not an option. The BoundaryArchTest's "no facades" rule needs an explicit allow-list entry for `Modules/Chains/Internal/Jobs/ResolveChainLinksJob.php` — the planner should add this carve-out and document it on the class.

### Pattern 4: Bulk-settle decomposition algorithm (D-97)

**What:** The core IcsSettlementResolver math: given an ASN→ICS `transfer_in` row, find candidate ICS expenses that sum to the settlement amount within tolerance.

**When to use:** Per `transfer_in` row whose `account_id` matches an ICS-kind Account and which lacks a confirmed `chain_link` of `kind='ics_bulk_settle'`.

**Algorithm:**

```
INPUT: transfer_in row T with amount_minor=A, posted_at=D, account_id=ics_account_id, user_id=U

1. Find candidate card_statement S where:
     S.user_id = U
     AND S.account_id = ics_account_id
     AND S.state IN ('open', 'partially_settled')
     AND S.period_end BETWEEN D - 10 days AND D + 10 days
     AND abs(S.total_amount_minor - A) <= max(€5, S.total_amount_minor * 0.02)

   If multiple candidates: pick the one whose period_end is closest to D.
   If none: T stays unlinked (Phase 8 may eventually create a card_statement
     for this period as new statements roll in). NO chain_link is written.

2. Pull all expense rows E where:
     E.user_id = U
     AND E.account_id = ics_account_id
     AND E.posted_at BETWEEN S.period_start AND S.period_end
     AND E.type = 'expense'
     AND NOT EXISTS (chain_link FROM E WHERE kind='ics_bulk_settle' AND state='confirmed')

3. Apply prior credits:
     credits_in = SELECT SUM(amount_minor) FROM card_statement_credits
                  WHERE to_statement_id = S.id

4. delta = SUM(E.settled_amount_minor) - credits_in - A
   (positive delta = user overpaid; negative = user underpaid)

5. If abs(delta) <= max(€5, total * 0.02):
     - Create N chain_links of kind='ics_bulk_settle', state='confirmed',
       confidence=1.0, resolver='auto', from=T, to=each E_i
     - evidence JSON: {
         "statement_id": S.id,
         "unaccounted_delta_minor": delta,
         "tolerance_used": "amount_5eur" | "percent_2",
         "covered_count": count(E),
         "credits_applied_minor": credits_in
       }
     - Call CardStatementStateMachine::applySettlement(S.id, A, U)
     - If state lands as 'overpaid':
         INSERT INTO card_statement_credits (from_statement_id=S.id,
           to_statement_id=next_open_statement.id OR NULL,
           amount_minor=abs(newOpen), reason='overpayment')
   Else:
     - Create one chain_link of kind='ics_bulk_settle', state='candidate',
       confidence=0.6..0.99, resolver='auto', from=T, to=null
       (review-queue surface — user judgment needed)
     - evidence JSON: {
         "statement_id": S.id,
         "unaccounted_delta_minor": delta,
         "tolerance_used": "exceeded",
         "covered_count": count(E),
       }
```

**Refund-after-close (D-98):** Step 2 includes a `type='refund'` filter for rows whose `posted_at` falls in a `settled` or `overpaid` statement period. Those refunds chain back to the original purchase (separate `kind='ics_bulk_settle'` link from refund row to original expense row in the closed statement), but the refund amount also creates a `card_statement_credits` row with `reason='refund_after_close'`, `from_statement_id=settled_statement.id`, `to_statement_id=next_open.id`. The math at step 4 of the NEXT settlement subtracts this credit before computing delta — that's the "flow into next settlement" semantics.

### Pattern 5: Auto-promotion signature counter (D-87 / D-88)

**What:** Per-user counter tracking how many times a given (normalized_merchant, funding_source_identity) signature has been confirmed. After ≥3 confirmations, the next candidate of the same signature lands as `state='confirmed'` directly with `resolver='rule'`.

**Why no separate table:** The counter is derivable from existing `chain_links` rows — counting confirmed rows whose `evidence.signature_hash` equals the candidate's signature. No new state to manage.

**Example:**

```php
// Inside ConfirmChainLink::__invoke():

$link = ChainLink::query()->where('user_id', $user->id)->findOrFail($id);
$link->state = 'confirmed';
$link->save();

// Recount the signature's confirmed history (idempotent — counts now reflect
// this just-confirmed row).
$signatureHash = $link->evidence['signature_hash'] ?? null;
if ($signatureHash === null) {
    return;
}

$confirmedCount = $this->db->connection()
    ->table('chain_links')
    ->where('user_id', $user->id)
    ->where('state', 'confirmed')
    ->whereJsonContains('evidence->signature_hash', $signatureHash)
    ->count();

if ($confirmedCount >= 3) {
    // Promote every other candidate with the same signature.
    $this->db->connection()
        ->table('chain_links')
        ->where('user_id', $user->id)
        ->where('state', 'candidate')
        ->whereJsonContains('evidence->signature_hash', $signatureHash)
        ->update(['state' => 'confirmed', 'resolver' => 'rule', 'updated_at' => $now]);
}
```

⚠ **SQLite JSON pathing caveat:** `whereJsonContains` works on SQLite with JSON1 extension enabled (default in modern SQLite). Verify against the live driver during Wave 0 — if it produces unexpected behaviour, fall back to raw `json_extract(evidence, '$.signature_hash') = ?` predicate via `whereRaw`. [ASSUMED — verification belongs in Wave 0]

### Pattern 6: Flux flyout drawer for UI-02 (D-90 / D-92 / D-93)

**What:** The chain-drawer SFC mounts a `<flux:modal flyout>` triggered by the "View chain" button on `/transactions/{id}`.

**Flux flyout API** [CITED: fluxui.dev/components/modal]:
- Component: `<flux:modal name="..." flyout variant="floating">` (or default — Phase 5 plan-phase locks via UI-SPEC pass).
- Triggers: `<flux:modal.trigger name="...">` button or programmatic `$this->modal('name')->show()` via Livewire.
- Dismiss: Escape key + click-outside both close by default when `dismissible="true"`. `closable="false"` hides the X button.
- Slots: default (body) + named `footer` slot. Header pattern via `<flux:heading>` inside default.
- Position: `position="right"` (default), `left`, `bottom`. Phase 5 uses right (Western reading order).
- Full-height behaviour: flyout drawers extend full viewport height by default.

**Example (skeleton — UI-SPEC plan-phase locks the visual treatment):**

```blade
{{-- Modules/Chains/Resources/views/livewire/chain-drawer.blade.php --}}
<flux:modal name="chain-drawer-{{ $transactionId }}" flyout position="right" class="md:w-2xl">
    <flux:heading size="lg">Chain for {{ $rootTransaction->counterparty_name }}</flux:heading>

    {{-- vertical waterfall, fully-expanded by default (D-92) --}}
    <div class="space-y-2">
        @foreach ($chainTree->nodes as $node)
            @include('chains::livewire.partials.chain-node', ['node' => $node])
        @endforeach
    </div>

    {{-- fan-out pagination for ICS bulk-settle nodes (D-93) --}}
    @if ($expandedFanoutId !== null)
        <livewire:chains::chain-fanout-pagination
            :statement-id="$expandedFanoutId"
            :page="$fanoutPage"
        />
    @endif

    {{-- closure handled by Flux: Escape + click-outside both close --}}
</flux:modal>
```

**UI-SPEC plan-phase obligations:**
- Lock open/close keyboard behaviour (Escape default).
- Lock click-outside-to-close behaviour (default).
- Lock sticky-vs-scrolling header (Flux ships scrolling; Phase 5 wants sticky for long chains — needs custom CSS class).
- Lock empty-chain state ("Chain not yet resolved — re-scan in progress" with link to `/chains/review`).
- Snapshot baselines for: 0-leg chain (just-imported, no resolver pass yet), 1-leg chain (PayPal funding only), 3-leg chain (PayPal→ASN with refund), N-leg chain with fan-out collapsed/expanded.

### Pattern 7: `synthesise_phase5_scenario.php` (D-108)

**What:** Composer-dep-free PHP CLI script that generates the cross-source matching fixture trio.

**When to use:** Wave 0 deliverable. Runs once during planning to produce committed fixtures; not re-run during CI.

**Approach:**

```php
// scripts/synthesise_phase5_scenario.php (sketch — Wave 0 implementation)

// 1. Pick a scenario variant: 'clean' | 'overpaid' | 'underpaid'.
// 2. Generate N ICS card transactions (default 20-25 per CONTEXT discretion).
//    Each has: posted_at within an arbitrary statement period, settled_amount_minor
//    in EUR, description (synthetic merchant from a small static list).
//    Subset of these are USD-funded purchases (preserve original currency on
//    a few rows per Phase 3 contract).
// 3. SUM(settled_amount_minor) = statement total T.
// 4. Generate the ICS PDF: synthesise a PDF that the Phase 3 IcsPdfAdapter
//    can parse. (Reuse scripts/generate_tiny_ics_pdf.php as the PDF emitter;
//    feed it the synthesised line items.) Statement period_end = D - 5 days
//    where D is the ASN settlement date.
// 5. Generate ASN CAMT.053: a single bulk-iDEAL row of amount =
//      'clean'     → T exactly
//      'overpaid'  → T + €1.53
//      'underpaid' → T - €2.18
//    Counterparty IBAN = 'ICS-CARD' (synthetic).
//    AcctSvcrRef stable across re-runs.
// 6. Generate PayPal CSV: 3-5 rows including:
//    - one Express Checkout Payment whose Reference Txn ID chains to a Bank Withdrawal
//    - one "General Withdrawal NL" (D-106) row whose rawPayload.events[] contains
//      an inferable destination IBAN that matches the user's ASN account
//    - one Currency Conversion pair (USD ↔ EUR) per Phase 3 D-39 / D-42 shape
// 7. Save under Modules/Chains/tests/fixtures/scenario-1/.
// 8. Commit a scenario-1.md fixture record documenting the synthesised totals,
//    counts, IBANs, and per-variant deltas.
```

The script is committed under `scripts/` mirroring `scripts/anonymize_ics_text.php` and `scripts/anonymize_paypal_csv.php` patterns. The output JSON manifest (`scenario-1-overpaid.json` / `scenario-1-underpaid.json`) overlays the bulk-iDEAL amount difference so the same ICS/PayPal pair tests both auto-confirm AND candidate-state code paths.

### Anti-Patterns to Avoid

- **Mutating `transactions` from the resolver.** D-84 invariant. The resolver writes `chain_links` only. BoundaryArchTest enforces (`noResolverWritesTransactions` rule mirroring Phase 4's `noPaypalApiRoute`).
- **Using Eloquent `Builder::whereBetween()` / `whereIn()` / `orderBy()` in resolver code.** Phpstan-strict-rules' `staticMethod.dynamicCall` forbids these on `Eloquent\Builder`. Use raw `DatabaseManager::table()` query builder (Phase 4 `PairTransferCandidates` is the reference).
- **Calling `auth()` / `Auth::user()` / `DB::table()` anywhere in Chains module.** Project DI policy. Every Public service injects collaborators via constructor. Single exception (with carve-out) is `ResolveChainLinksJob::uniqueVia()`'s `Cache::driver('redis')` — see Pattern 3 note.
- **Synchronous chain resolution inside `ConfirmImport`.** Rejected when the user chose async-via-Horizon (D-101). Dispatching synchronously would block the wizard for the multi-second resolver pass on a real-sized ledger.
- **`SELECT ... FOR UPDATE` on SQLite.** Unsupported [VERIFIED: sqlite.org User Forum]. Laravel's `lockForUpdate()` silently becomes a no-op on SQLite. Use `BEGIN IMMEDIATE` via `DatabaseManager::transaction()` + explicit IMMEDIATE statement (Pattern 2).
- **Resolver runs in parallel for the same user.** `ShouldBeUniqueUntilProcessing` keyed on user_id eliminates this (D-103). Without the unique constraint, two confirmed imports in adjacent tabs would race chain_link inserts.
- **Storing a boolean `is_settled` on `card_statements`.** PITFALLS Pitfall 4 explicitly rejects. Use the four-state enum (D-95).
- **Surfacing raw 0..1 confidence in the UI.** D-91 explicit. Three-tier chip only.
- **Cross-user chain_link query.** Every chain_link query must filter on `user_id = $user->id` first (FND-03 BelongsToUser invariant). A future multi-user expansion never accidentally cross-scans.
- **Bind-mount Redis storage in Docker.** D-102 — named volume only. The Sail anti-pattern PROJECT.md flags is about bind-mount IO; a named volume sidesteps it entirely.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Per-user unique job locks | Custom mutex / DB row with `lock_owner` column | Laravel's `ShouldBeUniqueUntilProcessing` + `uniqueId()` + `uniqueVia()` | Built-in. Battle-tested. Atomic via Redis. Handles crashed-worker lock release for free. |
| Queue dashboard | Custom `/queues` Livewire page | `/horizon` via `laravel/horizon` | Throughput, runtime, failed-job inspection, retry, supervisor metrics — all out of the box. |
| Redis client | Custom socket protocol over TCP | `predis/predis` | Pure-PHP, no PECL. The PROJECT.md anti-PECL precedent (`webklex/php-imap` over `ddeboer/imap`) applies identically. |
| String similarity for fuzzy merchant matching | Custom edit-distance implementation | PHP's built-in `levenshtein()` | Built-in, C-optimised, accepts arbitrary insertion/deletion/replacement costs. Adequate for ≤80-char normalised strings. |
| Merchant normalisation | New `MerchantNormaliser` class in Chains module | `FingerprintComposer::normalize()` already on Ledger's Public surface [VERIFIED: Modules/Ledger/Public/Services/FingerprintComposer.php L67] | Already covers lowercase + NFD diacritic strip + non-alphanumeric strip + 80-char truncate. Used consistently in the existing dedup pipeline; using the same normalised form makes chain matches join the same merchant-identity space. |
| Drawer / flyout UI | Custom Tailwind + Alpine drawer with hand-rolled focus trap | `<flux:modal flyout>` from `livewire/flux` ^2.0 [CITED: fluxui.dev] | Already in the project dependencies. Includes escape-to-close, click-outside-to-close, focus trap, animation. UI-02 first project use. |
| Per-row state-machine implementation | Custom `state()` method on `CardStatement` model with branching `if` chain | Encapsulated `CardStatementStateMachine` class with one public method + BoundaryArchTest invariant (D-95) | Centralising state mutation is the only way the no-other-mutator guarantee holds. A state method on the model is too easy for a future contributor to bypass. |
| Carry-forward credit tracking | Inline arithmetic in resolver every time a chain_link lands | First-class `card_statement_credits` table (D-96) | Audit trail. Chain drawer renders the credit_carry line by walking the table. Refund-after-close path uses the same shape. |
| Cross-source fixture synthesis | Real anonymised exports (the Phase 2/3/4 pattern) | Synthesised fixture trio under `scripts/synthesise_phase5_scenario.php` (D-107) | Real anonymised data from prior phases does NOT share the cross-source counterparty IBANs Phase 5 needs. Synthesis is the only way to exercise both resolvers against deterministically-aligned inputs. |

**Key insight:** Phase 5 is "wire up existing primitives, add minimal new schema, enforce invariants" rather than "build novel infrastructure". Horizon, Predis, Levenshtein, Flux flyouts, and `FingerprintComposer::normalize()` already exist. The new code is the two resolvers, the state machine, the schema, and the Livewire surfaces that render the data. Anything more elaborate is a smell.

## Runtime State Inventory

> Phase 5 is a feature-add phase, not a rename/migration phase. However, several runtime-state aspects matter because Phase 5 pulls queue infrastructure forward.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | (a) Existing `statement_summaries` rows — Phase 5 BACK-POPULATES into `card_statements` via one-shot migration (D-94). (b) Existing `transactions` rows of `type='transfer_in'` paired against `ICS-CARD` — Phase 5 INSPECTS for ICS settlement decomposition. (c) Existing PayPal `transactions` with `rawPayload.events[]` — Phase 5 INSPECTS for funding-source IBAN hints. | Back-population migration (Wave 1). Resolver re-scans (Wave 2/3). NO data migration of existing rows — chain_links are additive only. |
| Live service config | (a) Laravel queue config — currently `database` driver per existing `.env.example` `QUEUE_CONNECTION=database`. Phase 5 changes to `redis`. (b) New Redis service config (host, port, auth). (c) Horizon supervisor config in `config/horizon.php`. | Wave 0: update `.env` + `.env.example`, publish `config/horizon.php`, add `config/queue.php`, document Redis service in README. |
| OS-registered state | (a) macOS launchd: NOT yet configured for any process (Phase 6 deferred). Phase 5 documents `php artisan horizon` runs manually in a second terminal during dev. (b) Docker daemon: NEW prerequisite. README must document install path. | Wave 0: README update. Verify with `docker info` in dev environment. NO launchd plists — Phase 6 covers. |
| Secrets/env vars | (a) `REDIS_PASSWORD` — defaults to null on the loopback-only Redis container (D-102). No secret needed because Redis only listens on 127.0.0.1. (b) `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379` — new env entries. (c) `REDIS_CLIENT=predis` per Laravel 12 docs for Predis-based stacks. | Wave 0: `.env.example` additions. Document REDIS_PASSWORD=null is intentional (loopback-only). |
| Build artifacts / installed packages | (a) `vendor/laravel/horizon` directory — new after composer require. (b) `vendor/predis/predis` directory — new after composer require. (c) Composer lock file changes. (d) `composer.json` `require` block grows. | Wave 0: composer require + `composer install` produces these. Commit the composer.lock changes. |

**Canonical Question — what runtime systems will have stale state after Phase 5's code lands but no data migration runs?**

- **`statement_summaries` table:** still exists after Phase 5 with no changes. The back-population migration reads it; the new `card_statements` table is the canonical surface from Phase 5 forward. The two co-exist; future phases consume `card_statements`.
- **`failed_jobs` table:** Laravel default, may not exist yet (`php artisan queue:failed-table` produces it). Horizon installation creates it if missing. Worth verifying Wave 0.
- **`jobs` table:** existed in `database` queue mode. Becomes vestigial when QUEUE_CONNECTION flips to redis. Not actively harmful but unused.

## Common Pitfalls

### Pitfall 1: Unique-job lock stuck after worker crash

**What goes wrong:** `ResolveChainLinksJob` with `ShouldBeUnique` (not `ShouldBeUniqueUntilProcessing`) crashes during the resolver's bulk-settle pass. The lock stays acquired until `uniqueFor` expires. New imports queue but never resolve because dispatch silently fails the unique check.

**Why it happens:** `ShouldBeUnique`'s lock release fires on job completion or final failure. A worker killed mid-job (SIGKILL, OS crash) leaves the lock dangling.

**How to avoid:** Use `ShouldBeUniqueUntilProcessing` (D-103) — the lock is released when the worker begins `handle()`, not when it finishes. A crash mid-`handle()` therefore releases the lock automatically. The trade-off is that a second instance CAN enqueue while the first is processing; that's exactly the behaviour the project wants for "user confirms a second import while the first is still resolving."

**Warning signs:** Lock keys lingering in Redis (`KEYS laravel_unique_job:*`); imports confirmed but `/horizon` shows no Resolve job; resolver works once then stops processing new dispatches.

### Pitfall 2: SQLite `lockForUpdate()` silently no-ops

**What goes wrong:** Developer writes `CardStatement::query()->lockForUpdate()->find($id)` expecting row-level locking; SQLite ignores it; two concurrent resolver calls race the state transition, producing an inconsistent `state` and `open_balance_minor` pair.

**Why it happens:** SQLite does not support `SELECT ... FOR UPDATE` [VERIFIED: sqlite.org User Forum]. The Laravel framework still accepts the call without error — it just doesn't emit the lock hint. On MySQL/Postgres it would work; on SQLite it's a quiet no-op.

**How to avoid:** Use `BEGIN IMMEDIATE TRANSACTION` (Pattern 2). The `CardStatementStateMachine::applySettlement()` method is the single point where this matters; isolating the pattern to one class keeps the trap from spreading.

**Warning signs:** Tests pass on a single-threaded test run but fail under parallel Pest execution; `card_statements.state` values that don't match `card_statements.open_balance_minor`; missing `card_statement_credits` rows where the math says there should be one.

### Pitfall 3: `ConfirmImport` dispatches `ResolveChainLinksJob` before commit

**What goes wrong:** The dispatch happens INSIDE the `DatabaseManager::transaction()` block. The queue worker picks up the job before the outer transaction commits and reads stale state — the just-imported rows aren't visible yet.

**Why it happens:** Easy mistake to put `Bus::dispatch()` inside the closure passed to `transaction()`. Laravel's default queue driver (`database`) sometimes "works" because the same DB transaction is shared with the queue write; Redis (Phase 5's queue driver) does NOT share a transaction with SQLite — the queue write commits to Redis instantly, the worker picks it up instantly, and the outer SQLite transaction may not have committed.

**How to avoid:** Dispatch AFTER the `transaction()` callable returns — never inside. Or implement `ShouldQueueAfterCommit` on the job (Laravel 11+). Phase 5 plan-phase locks the dispatch site for `ConfirmImport.php` to be a single line after the `transaction()` call returns its `ImportConfirmResult`.

**Warning signs:** Resolver job runs but finds zero new chain candidates; intermittent test failures where re-running passes; `Transaction not found` errors in queue logs.

### Pitfall 4: `BoundaryArchTest::noResolverWritesTransactions` not enforced

**What goes wrong:** A future contributor writes a "small" `Transaction::query()->where(...)->update(['type' => 'transfer_in'])` inside `IcsSettlementResolver` to "fix up" a wrong type. D-84 invariant breaks silently because no test catches it.

**Why it happens:** Phase 4 established the listener-never-retypes invariant via natural code review. Phase 5's resolver is bigger and more tempting to "just patch" a typing edge case.

**How to avoid:** Add a Pest arch test mirroring the Phase 4 `noPaypalApiRoute` pattern (BoundaryArchTest.php L56–L97): grep all files under `Modules/Chains/` for raw `update`/`Transaction::*->update` patterns and fail if any match. The test is grep-based, not pest-arch-plugin-based, because pest-arch only inspects PHP namespaces, not statement bodies.

**Warning signs:** New `update(['type' => ...])` calls in Chains/; chain drawer rendering chains whose endpoints' `type` was modified by the resolver.

### Pitfall 5: Auto-promotion fires before user finishes correcting

**What goes wrong:** User confirms 3 candidates of the same merchant in quick succession because the chain drawer surfaces them in a row. The 3rd confirmation auto-promotes other candidates; user then notices the 2nd confirmation was a misclick and rejects it. The auto-promoted rows stay confirmed — the counter doesn't decrement.

**Why it happens:** D-87 + D-89 establish per-pair reject (signature stays neutral). The auto-promotion-on-confirm path is monotone; reject does not roll back prior promotions.

**How to avoid:** Document the behaviour explicitly in the chain drawer's empty-state copy: "Confirming a third matching link auto-promotes future matches. Rejecting later does not roll back prior promotions." Surface the per-pair reject as a one-click action with a brief toast clarifying the scope.

**Warning signs:** Users reporting "I rejected that — why are similar matches still showing as confirmed?"

### Pitfall 6: Synthesised fixture too clean to exercise tolerance handling

**What goes wrong:** D-107 fixture only includes a clean-match scenario. The IcsSettlementResolver's tolerance arm (D-97 candidate path) goes untested at the fixture level.

**Why it happens:** Easy to synthesise an exact-match scenario; harder to synthesise realistic over/underpaid variants.

**How to avoid:** D-107 explicitly requires THREE variants: clean, overpaid (+€1.53), underpaid (-€2.18). The synthesis script emits all three. IcsSettlementResolverTest uses a Pest dataset that runs the resolver against each variant and asserts the chain_link state + evidence shape.

**Warning signs:** Test suite green but real user fixture lands and the resolver mis-classifies; unaccounted-delta_minor field in evidence JSON always 0 in tests.

### Pitfall 7: `card_statements` back-population not idempotent

**What goes wrong:** Migration runs, creates N rows. Migration re-run (during dev rollback/re-up cycles) fails on UNIQUE `(user_id, account_id, period_start, period_end)` constraint.

**Why it happens:** Forward-only migrations should be idempotent in dev. The UNIQUE index correctly prevents duplicates but the `up()` method must use `insertOrIgnore` / `upsert` rather than plain `insert`.

**How to avoid:** Use `insertOrIgnore` in the back-population step. Verify with `php artisan migrate:fresh && php artisan migrate` cycle in Wave 1.

**Warning signs:** `SQLSTATE[23000]: Integrity constraint violation` during migrate after a rollback.

### Pitfall 8: Redis container bound to 0.0.0.0

**What goes wrong:** Docker default port binding (`-p 6379:6379`) exposes Redis on all network interfaces. On a developer machine on a hostile LAN (coffee shop wifi, conference network), an attacker can connect to the unauthenticated Redis instance and read every job payload including potentially-sensitive transaction data.

**Why it happens:** `-p 6379:6379` is the standard Docker default. Most tutorials don't mention the loopback-bind variant.

**How to avoid:** Always use `-p 127.0.0.1:6379:6379` (D-102 + FND-01). The README must show the loopback-bound form. The `Horizon::auth()` callback in `HorizonServiceProvider` ALSO restricts dashboard access to the same posture as Phase 1's LoopbackOnly middleware.

**Warning signs:** `redis-cli -h $LAN_IP ping` from another machine returns PONG; `netstat -an | grep 6379` shows 0.0.0.0:6379 listening.

## Code Examples

### Example 1: Deterministic PayPal funding-chain match via rawPayload.events[]

```php
// Source: this research + D-106 + Modules/Ingestion/Internal/Adapters/Paypal/PaypalCsvAdapter.php
// Location: Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php (excerpt)

private function deterministicMatch(Transaction $paypalTx, User $user): ?ChainLink
{
    $events = $paypalTx->raw_payload['events'] ?? [];
    if ($events === []) {
        return null;
    }

    // Look for a "General Withdrawal" / "Bankstorting" event whose memo carries an IBAN.
    foreach ($events as $event) {
        $eventType = $event['type'] ?? '';
        $row = $event['row'] ?? [];
        if (! in_array($eventType, ['General Withdrawal', 'Transfer to bank', 'Bankstorting'], true)) {
            continue;
        }
        $iban = $this->extractIban($row);
        if ($iban === null) {
            continue;
        }
        // Find the user's account with that IBAN.
        $account = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', $iban)
            ->first(['id']);
        if ($account === null) {
            continue;
        }
        // Find the matching transfer_in on that account within ±3 days, equal-and-opposite.
        $partner = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $account->id)
            ->where('type', 'transfer_in')
            ->where('amount_minor', -$paypalTx->amount_minor)
            ->whereBetween('booked_at', [
                $paypalTx->booked_at->copy()->subDays(3)->toDateTimeString(),
                $paypalTx->booked_at->copy()->addDays(3)->toDateTimeString(),
            ])
            ->first(['id']);
        if ($partner === null) {
            continue;
        }
        return new ChainLink([
            'user_id'              => $user->id,
            'from_transaction_id'  => $paypalTx->id,
            'to_transaction_id'    => (int) $partner->id,
            'kind'                 => 'paypal_funding',
            'state'                => 'confirmed',
            'confidence'           => 1.0,
            'resolver'             => 'auto',
            'evidence'             => [
                'matched_reference_id' => $event['row']['Reference Txn ID'] ?? null,
                'matched_iban'         => $iban,
                'event_type'           => $eventType,
                'signature_hash'       => hash('sha256', $paypalTx->counterparty_normalized.'|'.$iban),
            ],
        ]);
    }
    return null;
}
```

### Example 2: Fuzzy PayPal funding-chain candidate with confidence scoring

```php
// Source: this research + D-85 confidence tiers + Pitfall 9 fuzzy matching
// Location: Modules/Chains/Internal/Resolvers/PaypalFundingResolver.php (excerpt)

private function fuzzyMatch(Transaction $paypalTx, User $user, FingerprintComposer $fp): ?ChainLink
{
    $normalisedMerchant = $paypalTx->counterparty_normalized;
    $amountBand = (int) round(abs($paypalTx->settled_amount_minor) * 0.02); // ±2%

    $candidates = $this->db->connection()->table('transactions')
        ->where('user_id', $user->id)
        ->where('id', '<>', $paypalTx->id)
        ->where('type', 'transfer_in')                       // funded by another account
        ->whereBetween('settled_amount_minor', [
            -abs($paypalTx->settled_amount_minor) - $amountBand,
            -abs($paypalTx->settled_amount_minor) + $amountBand,
        ])
        ->whereBetween('posted_at', [
            $paypalTx->posted_at->copy()->subDays(3)->toDateString(),
            $paypalTx->posted_at->copy()->addDays(3)->toDateString(),
        ])
        ->limit(20)
        ->get(['id', 'counterparty_normalized', 'posted_at', 'settled_amount_minor', 'account_id']);

    $best = null;
    $bestScore = 0.0;
    foreach ($candidates as $candidate) {
        $merchantSim = $this->levenshteinSimilarity(
            $normalisedMerchant,
            (string) $candidate->counterparty_normalized,
        );
        $amountDelta = abs(abs($paypalTx->settled_amount_minor) - abs((int) $candidate->settled_amount_minor));
        $amountSim = 1.0 - ($amountDelta / max(1, abs($paypalTx->settled_amount_minor)));
        $dateDelta = abs((int) $paypalTx->posted_at->diffInDays($candidate->posted_at, false));
        $dateSim = max(0.0, 1.0 - ($dateDelta / 3));

        // Weighted score — discretion-locked weights, planner can tune.
        $score = (0.5 * $merchantSim) + (0.3 * $amountSim) + (0.2 * $dateSim);

        if ($score > $bestScore && $score >= 0.6) {
            $best = $candidate;
            $bestScore = $score;
        }
    }
    if ($best === null) {
        return null;
    }
    $accountIban = $this->ibanForAccountId((int) $best->account_id, $user);
    return new ChainLink([
        'user_id'              => $user->id,
        'from_transaction_id'  => $paypalTx->id,
        'to_transaction_id'    => (int) $best->id,
        'kind'                 => 'paypal_funding',
        'state'                => 'candidate',
        'confidence'           => round($bestScore, 3),
        'resolver'             => 'auto',
        'evidence'             => [
            'merchant_similarity' => round($merchantSim, 3),
            'amount_delta_minor'  => $amountDelta,
            'date_delta_days'     => $dateDelta,
            'signature_hash'      => hash('sha256', $normalisedMerchant.'|'.$accountIban),
        ],
    ]);
}

private function levenshteinSimilarity(string $a, string $b): float
{
    $maxLen = max(mb_strlen($a), mb_strlen($b));
    if ($maxLen === 0) return 1.0;
    $dist = levenshtein($a, $b);
    return 1.0 - ($dist / $maxLen);
}
```

### Example 3: Dashboard tile extension

```php
// Source: extension of Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php (D-99)
// Phase 5 plan extends the existing query class with one new public method.

public function nextIcsSettlement(User $user): ?CardStatementForecastTile
{
    $connection = $this->db->connection();

    // Most-recent open or partially_settled card_statement for any ICS account
    // of this user. Forecast lag = period_end + 5 days (D-100 constant).
    $row = $connection->table('card_statements')
        ->where('user_id', $user->id)
        ->whereIn('state', ['open', 'partially_settled'])
        ->orderBy('period_end', 'desc')
        ->first(['id', 'open_balance_minor', 'period_end']);

    if ($row === null) {
        return null;                                  // No open statement → tile hidden
    }

    $periodEnd = CarbonImmutable::parse((string) $row->period_end);
    return new CardStatementForecastTile(
        amount: Money::ofMinor(abs((int) $row->open_balance_minor), 'EUR'),
        dueDate: $periodEnd->addDays(5),
    );
}
```

### Example 4: `ConfirmImport` post-commit dispatch

```php
// Source: extension of Modules/Import/Public/Actions/ConfirmImport.php (D-103)
// Phase 5 modifies ConfirmImport to inject Dispatcher and dispatch the resolver job
// AFTER the transaction() block completes — Pitfall 3 above.

public function __construct(
    private readonly RecordsTransactions $recorder,
    private readonly AppliesEnrichments $applyEnrichments,
    private readonly PreviewCache $cache,
    private readonly DatabaseManager $db,
    private readonly Clock $clock,
    private readonly Dispatcher $bus,                  // ← NEW (Illuminate\Contracts\Bus\Dispatcher)
) {}

public function __invoke(int $importRunId, User $user): ImportConfirmResult
{
    // ... existing pre-transaction logic ...

    /** @var ImportConfirmResult $result */
    $result = $this->db->connection()->transaction(function () /* ... */ {
        // ... existing recorder + enrichments ...
        return $confirmResult;
    });

    $this->cache->forget($importRunId);

    // POST-COMMIT — outer transaction has returned.
    // Use ShouldQueueAfterCommit-style dispatch, or rely on the fact that
    // we're now outside the transaction frame. The job itself isn't
    // ShouldQueueAfterCommit because the dispatcher is being called from
    // here, not from inside the transaction.
    if ($result->inserted > 0 || $result->enriched > 0) {
        $this->bus->dispatch(new ResolveChainLinksJob($user->id));
    }

    return $result;
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `database` queue driver for all projects | Redis + Horizon when production-grade observability matters | Phase 5 (D-101) | Adds Redis + Docker dependency; gains `/horizon` dashboard. |
| `ShouldBeUnique` (lock until completion) | `ShouldBeUniqueUntilProcessing` (lock released at handle() start) | Laravel 9+ [VERIFIED: laravel.com/docs/12.x/queues] | Allows downstream dispatch while job runs; survives worker crashes. |
| `ext-imap`-based IMAP libraries | Pure-PHP libraries like `webklex/php-imap` | PHP 8.4 (ext-imap unbundled) | Phase 5 doesn't touch IMAP; mentioned for context — same anti-PECL posture applies to Redis client choice (Predis over PhpRedis). |
| `flux:modal` legacy `variant="flyout"` | `<flux:modal flyout>` boolean prop with explicit `variant="floating"|"default"|"bare"` | Flux 2 [CITED: fluxui.dev/components/modal] | Phase 5 uses the modern `flyout` boolean form. |
| SQLite as "toy DB" — needs Postgres for production | SQLite with WAL + busy_timeout = production-grade for single-user, single-machine | Laravel 11 (SQLite became default driver) | Already locked in Phase 1; Phase 5 inherits. |

**Deprecated / outdated:**
- `Horizon::auth($callback)` was sometimes positioned in `routes/web.php`; modern pattern is in `HorizonServiceProvider::boot()` [CITED: laravel.com/docs/12.x/horizon].
- Boolean `is_settled` flags on statement tables — explicitly rejected by PITFALLS Pitfall 4.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `whereJsonContains` on SQLite works for `evidence->signature_hash` lookups | Pattern 5 | If SQLite/JSON1 doesn't pattern-match the way the code assumes, the auto-promotion counter under-counts; fall back to `whereRaw("json_extract(evidence, '$.signature_hash') = ?", [...])` — verify in Wave 0. |
| A2 | Migration timestamp slot for Chains migrations is `2026_05_16_010001` ... `010004` | Pattern 1 | Cosmetic; planner locks against the latest Phase 4 timestamp at plan-phase time. No functional risk. |
| A3 | The `Cache::driver('redis')` facade call inside `uniqueVia()` is the only permitted facade in Phase 5 | Pattern 3 (Note) | If Laravel's queue infrastructure allows DI access to `Repository` before `uniqueVia()` is called, a constructor-injected `Repository` is preferable. Worth verifying against the live framework during Wave 0 — the planner should test both shapes and lock the cleaner one. |
| A4 | `Bus::dispatch` post-`transaction()`-block works correctly with Redis driver (no commit-visibility issue) | Pitfall 3 | If the Redis driver still reads stale state for some reason, the alternative is implementing `ShouldQueueAfterCommit` on `ResolveChainLinksJob`. Verify in Wave 0 by integration-testing the dispatch site. |
| A5 | The user already has Docker installed on their dev machine | Wave 0 README addition | If not, the README's first-time-setup section must include the Docker Desktop install link before the `docker run` step. Wave 0 plan should verify `docker info` returns OK; if not, surface as a blocking installation step in the README. |
| A6 | `predis/predis` 3.x semver `^8.0` constraint works with PHP 8.5 | Standard Stack table | Caret-major on PHP version constraints conventionally covers minor-version increments within the same major (i.e., `^8.0` covers 8.5). If composer rejects the install on PHP 8.5, the fallback is to pin `predis/predis ^2.x` or to allow `phpredis` PECL build — verify with `composer require predis/predis --dry-run` in Wave 0. |
| A7 | Fuzzy-match weights (0.5 merchant / 0.3 amount / 0.2 date) | Example 2 | Discretion item — planner can tune. If the Wave-0 fixture's overpaid/underpaid variants don't trigger candidate state under these weights, adjust. |
| A8 | Forecast lag = constant 5 calendar days from period_end (D-100) | Standard Stack / Example 3 | This is a locked decision, not an assumption. Listed here only because Phase 8 will refine it — Phase 5's tile copy ("due ~20 May") should make the approximate nature obvious. |

**Items needing user confirmation before plan-phase locks them:** A3 (resolver dispatch + uniqueVia shape) and A4 (`ShouldQueueAfterCommit` adoption) are the two with material runtime impact. Both should be exercised in Wave 0 against the live Redis + Horizon stack.

## Open Questions

1. **Does the user want `/horizon` accessible from the dashboard navigation or kept developer-only?**
   - What we know: D-101 says LoopbackOnly + Fortify auth covers /horizon. Phase 1 LoopbackOnly middleware applies to all routes by default.
   - What's unclear: Whether to add a "View queue dashboard" link in the user-facing UI or treat `/horizon` as a hidden developer surface.
   - Recommendation: Hide it from user navigation (developer-only). Document the URL in README. The failed-job toast (D-103) deep-links there when a job fails. CONTEXT.md "discretion" D-101 covers this — planner locks during plan-phase.

2. **What is the empty-fan-out edge case for ICS bulk-settle (D-93)?**
   - What we know: A card_statement with 0 covered ICS transactions in its period is possible if the user only had refunds that month.
   - What's unclear: Does the chain drawer render the settlement node alone with an "covers 0 ICS charges (€0.00 / refund-only month)" affordance, or hide the node entirely?
   - Recommendation: Render the node with explicit "0 charges (refund-only month)" copy. The user MUST be able to see the settlement exists. UI-SPEC plan-phase locks the exact copy.

3. **Two simultaneously-open card_statements (rare but possible per CONTEXT D-99 discretion)?**
   - What we know: A user could legitimately have two ICS accounts (deferred to multi-card phase) or two periods straddling under non-standard ICS behaviour.
   - What's unclear: Does the dashboard tile sum them, surface both, or pick the closest-due?
   - Recommendation: Surface both (one tile per open statement). Phase 5 implements the single-statement case; the multi-statement case is a Phase 5 "no-op" because Phase 3 ICS-CARD synthetic-IBAN locks to one account. Discretion-locked.

4. **Should `RejectChainLink` write a `reject_reason` or similar audit field?**
   - What we know: D-89 says per-pair reject only; signature stays neutral; the rejected row stays in DB.
   - What's unclear: Is the rejection metadata (timestamp, user_id, free-text reason) captured anywhere, or just the `state='rejected'` flag?
   - Recommendation: Capture `rejected_at` timestamp (already part of `updated_at`) and a `resolver='user'` value to distinguish user rejections from any future auto-rejection paths. No free-text reason in Phase 5 — adds UI surface area not in scope.

5. **Does `ResolveChainLinksJob` need to fire its own `ChainLinksResolved` event for downstream consumers (Phase 8 fixed-payments)?**
   - What we know: Phase 8 will consume `chain_links` for fixed-payment funding-source icons.
   - What's unclear: Does Phase 8 poll `chain_links.updated_at` or subscribe to a Phase 5-fired event?
   - Recommendation: Don't fire an event in Phase 5. Phase 8 can read `chain_links` directly on each fixed-payment surface render; chain links are slowly-changing. Add the event when Phase 8 actually needs it. Discretion-locked.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker daemon | Redis container per D-102 | ASSUMED yes (verify in Wave 0 — `docker info`) | unknown | If not installed: README documents Docker Desktop install link; Wave 0 plan blocks until installed. Alternative: `brew install redis` + manual `redis-server` launch — rejected by user per deferred-ideas. |
| Redis 7 | Job queue + ShouldBeUnique lock cache | NEW — will be installed via `docker run redis:7-alpine` | `redis:7-alpine` (image tag) | None — no fallback. If Docker unavailable, Wave 0 cannot proceed. |
| PHP 8.5 | Already required by Laravel 13 stack | YES per composer.json [VERIFIED: composer.json `"php": "^8.5"`] | 8.5.x | None — Phase 1 baseline. |
| Composer | Adding `laravel/horizon` + `predis/predis` | YES — existing project dep | latest | None. |
| SQLite + WAL | Existing storage with WAL mode locking semantics | YES per Phase 1 FND-06 | 3.45+ | None — schema migration runs against existing SQLite. |
| `livewire/flux` ^2.0 | UI-02 drawer + chain review queue | YES per composer.json [VERIFIED: composer.json] | ^2.0 | None. |

**Missing dependencies with no fallback:** Docker daemon (BLOCKING — must verify Wave 0).

**Missing dependencies with fallback:** None.

**Wave 0 verification command suite:**
```bash
docker info | head -3                                    # confirms daemon running
composer require --dry-run laravel/horizon predis/predis  # confirms install path
docker pull redis:7-alpine                                # pre-pull image
docker run --rm redis:7-alpine redis-cli --version       # confirms image + cli work
```

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.0 (PHPUnit 11 engine), pest-plugin-laravel ^4.0, pest-plugin-arch ^4.0, pest-plugin-snapshots ^2.0 [VERIFIED: composer.json] |
| Config file | `phpunit.xml` (project root); module-local `Modules/Chains/tests/Pest.php` mirroring Phase 1/4 convention |
| Quick run command | `vendor/bin/pest --filter "PaypalFundingResolver\|IcsSettlementResolver\|CardStatementStateMachine\|ChainReviewQueue\|ChainDrawer"` |
| Full suite command | `composer test` (alias for `pest --parallel`) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CHN-01 | PayPal `transfer_out` with `rawPayload.events[]` carrying ASN IBAN → deterministic chain_link `kind='paypal_funding'`, `state='confirmed'`, `confidence=1.0`, `resolver='auto'` | unit | `vendor/bin/pest --filter "PaypalFundingResolverTest::deterministicByIban"` | ❌ Wave 3 |
| CHN-01 | PayPal `transfer_out` with shared `Reference Txn ID` against an ASN row → deterministic match | unit | `vendor/bin/pest --filter "PaypalFundingResolverTest::deterministicByReferenceTxnId"` | ❌ Wave 3 |
| CHN-02 | PayPal expense lacking deterministic hint → fuzzy chain_link, state='candidate', confidence ∈ [0.6, 0.99] | unit | `vendor/bin/pest --filter "PaypalFundingResolverTest::fuzzyScores"` (Pest dataset on merchant similarity + amount + date) | ❌ Wave 3 |
| CHN-02 | Fuzzy match below confidence floor 0.6 → no chain_link written | unit | `vendor/bin/pest --filter "PaypalFundingResolverTest::dropsBelowFloor"` | ❌ Wave 3 |
| CHN-03 | ConfirmChainLink promotes state and increments signature counter | feature | `vendor/bin/pest --filter "ConfirmChainLinkTest::promotesAndCounts"` | ❌ Wave 4 |
| CHN-03 | 3rd confirmation of same signature auto-promotes other candidates with same signature | feature | `vendor/bin/pest --filter "ConfirmChainLinkTest::autoPromotesAfterThree"` | ❌ Wave 4 |
| CHN-03 | RejectChainLink sets state=rejected and does NOT touch signature counter | feature | `vendor/bin/pest --filter "RejectChainLinkTest::perPairReject"` | ❌ Wave 4 |
| CHN-03 | Review queue page surfaces candidates sorted by confidence DESC then most-recent | feature | `vendor/bin/pest --filter "ChainReviewQueueTest::sortsByConfidence"` | ❌ Wave 4 |
| CHN-04 / UI-02 | Drawer renders fully-expanded chain tree from `ChainLinkQuery::forTransaction()` | feature | `vendor/bin/pest --filter "ChainDrawerTest::rendersFullyExpanded"` | ❌ Wave 4 |
| CHN-04 / UI-02 | Drawer empty-state when no chain_links exist yet | feature | `vendor/bin/pest --filter "ChainDrawerTest::emptyState"` | ❌ Wave 4 |
| CHN-04 / UI-02 | ICS bulk-settle fan-out paginates within drawer (10 per page default) | feature | `vendor/bin/pest --filter "ChainDrawerTest::paginatesFanout"` | ❌ Wave 4 |
| CHN-04 / UI-02 | Drawer snapshot baselines (Pest snapshots plugin) — empty / 1-leg / 3-leg / N-leg with fan-out | snapshot | `vendor/bin/pest --filter "ChainDrawerSnapshotTest"` | ❌ Wave 4 |
| CHN-05 | Clean-match scenario: bulk-iDEAL within tolerance → N confirmed chain_links + state='settled' | feature (uses Wave-0 fixture) | `vendor/bin/pest --filter "IcsSettlementResolverTest::cleanMatch"` | ❌ Wave 2 |
| CHN-05 | Overpaid scenario (+€1.53): chain_links confirmed + state='overpaid' + card_statement_credits row created | feature | `vendor/bin/pest --filter "IcsSettlementResolverTest::overpaid"` | ❌ Wave 2 |
| CHN-05 | Underpaid scenario (-€2.18): chain_links confirmed + state='partially_settled' (within tolerance) | feature | `vendor/bin/pest --filter "IcsSettlementResolverTest::underpaid"` | ❌ Wave 2 |
| CHN-05 | Outside tolerance (€50 delta): single chain_link state='candidate' | feature | `vendor/bin/pest --filter "IcsSettlementResolverTest::outsideTolerance"` | ❌ Wave 2 |
| CHN-05 | Refund-after-close: chain_link to original purchase + card_statement_credits row toward next-open | feature | `vendor/bin/pest --filter "IcsSettlementResolverTest::refundAfterClose"` | ❌ Wave 2 |
| CHN-05 | CardStatementStateMachine state transitions are atomic (concurrent dispatches don't race) | unit | `vendor/bin/pest --filter "CardStatementStateMachineTest::beginImmediateSerialises"` | ❌ Wave 1 |
| CHN-06 | `ThisPeriodAtAGlanceQuery::nextIcsSettlement()` returns null when no open statement | unit | `vendor/bin/pest --filter "NextIcsSettlementTileTest::nullWhenNoneOpen"` | ❌ Wave 4 |
| CHN-06 | Returns the most-recent open statement's open_balance_minor + period_end + 5 days | unit | `vendor/bin/pest --filter "NextIcsSettlementTileTest::returnsForecast"` | ❌ Wave 4 |
| CHN-06 | Dashboard renders the tile when present, omits when null | feature | `vendor/bin/pest --filter "DashboardTest::nextIcsSettlementTile"` | ❌ Wave 4 |
| CHN-07 | chain_links table created with all columns, indexes, CHECK constraints for `kind` enum | feature (raw schema introspection) | `vendor/bin/pest --filter "ChainLinksSchemaTest"` | ❌ Wave 1 |
| CHN-07 | card_statements table created with state CHECK constraint + UNIQUE constraint | feature | `vendor/bin/pest --filter "CardStatementsSchemaTest"` | ❌ Wave 1 |
| CHN-07 | card_statement_credits table created with FKs | feature | `vendor/bin/pest --filter "CardStatementCreditsSchemaTest"` | ❌ Wave 1 |
| CHN-07 | Back-population migration produces card_statements rows from statement_summaries | feature | `vendor/bin/pest --filter "CardStatementBackPopulationTest::backpopulates"` | ❌ Wave 1 |
| CHN-07 | Re-running back-population migration is idempotent (insertOrIgnore) | feature | `vendor/bin/pest --filter "CardStatementBackPopulationTest::idempotent"` | ❌ Wave 1 |
| (Cross-cut) | BoundaryArchTest: no `Modules/Chains/` file calls `DB::*` facade | arch | `vendor/bin/pest --filter "BoundaryArchTest::noFacadesInChains"` (extends existing rule to Chains namespace) | ❌ Wave 0 |
| (Cross-cut) | BoundaryArchTest: no resolver writes to `transactions` table | arch | `vendor/bin/pest --filter "BoundaryArchTest::noResolverWritesTransactions"` (grep-style, Phase 4 noPaypalApiRoute pattern) | ❌ Wave 0 |
| (Cross-cut) | BoundaryArchTest: card_statements.state only mutated via CardStatementStateMachine | arch | `vendor/bin/pest --filter "BoundaryArchTest::stateOnlyViaMachine"` (grep-style — fails if any non-CardStatementStateMachine file updates `card_statements.state` or uses `CardStatement::update`) | ❌ Wave 0 |
| (Cross-cut) | Modules/Chains/Internal is only used inside Modules/Chains | arch | `vendor/bin/pest --filter "Modules\\\\Chains\\\\Internal"` (extends existing test in BoundaryArchTest.php — register new namespace) | ❌ Wave 0 |
| (Cross-cut) | Chain resolution idempotency: running ResolveChainLinksJob twice in a row produces zero new chain_links the second time | contract | `vendor/bin/pest --filter "ChainResolverIdempotencyContractTest"` | ❌ Wave 2 |
| (Cross-cut) | ResolveChainLinksJob is dispatched after ConfirmImport's outer transaction commits (not inside) | feature | `vendor/bin/pest --filter "ConfirmImportDispatchesResolverTest::postCommit"` | ❌ Wave 2 |
| (Cross-cut) | ResolveChainLinksJob respects ShouldBeUniqueUntilProcessing — second dispatch while first running is rejected at queue level | feature (uses fakeQueue + uniqueLock assertion) | `vendor/bin/pest --filter "ResolveChainLinksJobTest::uniqueUntilProcessing"` | ❌ Wave 2 |
| (Cross-cut) | Cross-user chain_link isolation — User A's resolver cannot read/write User B's chain_links | feature | `vendor/bin/pest --filter "CrossUserChainLinkIsolationTest"` | ❌ Wave 1 |
| (Cross-cut) | PayPal NL "General Withdrawal" hand-off (D-106) — Phase 4 fixture row with NL Bankstorting event resolves deterministically | feature | `vendor/bin/pest --filter "PaypalFundingResolverTest::nlGeneralWithdrawalCloseOut"` | ❌ Wave 3 |
| (Cross-cut) | Wizard renders "Resolving chains…" status while job pending; renders "Imported X · linked Y · Z pending" once complete | feature | `vendor/bin/pest --filter "WizardChainResolutionStatusTest"` | ❌ Wave 4 |
| (Cross-cut) | Failed-job toast appears on dashboard when ResolveChainLinksJob exhausts retries | feature | `vendor/bin/pest --filter "FailedJobToastTest"` | ❌ Wave 4 |
| (Cross-cut) | PairLookup::isPaired() / partnerId() public surface (D-110) returns correct values | unit | `vendor/bin/pest --filter "PairLookupTest"` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `vendor/bin/pest --filter "<task scope>"` (e.g., `IcsSettlementResolverTest` during resolver work)
- **Per wave merge:** `composer test` (full parallel suite) + `composer analyse` (Larastan level 10 strict) + `composer format:check` (Pint) — same posture as Phases 1–4.
- **Phase gate:** Full suite green before `/gsd-verify-work`. Plus `php artisan horizon:status` returns "Horizon is running" in dev as a manual smoke check (Wave 0 instrumentation only — Phase 6 launchd polish removes the manual step).

### Wave 0 Gaps

- [ ] `composer.json` updates: add `laravel/horizon: ^5.46` + `predis/predis: ^3.4` (or whichever versions Wave 0 verifies are current at install time)
- [ ] `.env.example` updates: `QUEUE_CONNECTION=redis`, `REDIS_CLIENT=predis`, `REDIS_HOST=127.0.0.1`, `REDIS_PORT=6379`, `REDIS_PASSWORD=null`
- [ ] `config/queue.php` — new file (published or hand-written) with `redis` as default
- [ ] `config/horizon.php` — produced by `php artisan horizon:install`; tuned for single supervisor (`balance: 'simple', processes: 1`)
- [ ] `app/Providers/HorizonServiceProvider.php` — produced by `horizon:install`; `Horizon::auth()` callback set to require Fortify-authenticated user (matches Phase 1 posture)
- [ ] `bootstrap/providers.php` — register HorizonServiceProvider + new ChainsServiceProvider
- [ ] `Modules/Chains/` skeleton — composer.json, Providers/, Public/, Internal/, Models/, Database/Migrations/, Routes/, Resources/, tests/Pest.php + tests/TestCase.php (mirror Modules/Transfers/ shape)
- [ ] `Modules/Transfers/Public/Services/PairLookup.php` — D-110 promotion (Wave 0 recommended — discretion lock)
- [ ] `scripts/synthesise_phase5_scenario.php` — Wave 0 fixture generator
- [ ] `Modules/Chains/tests/fixtures/scenario-1/asn-camt053.xml` — synthesised CAMT.053 fixture
- [ ] `Modules/Chains/tests/fixtures/scenario-1/ics-statement.pdf` — synthesised ICS PDF fixture
- [ ] `Modules/Chains/tests/fixtures/scenario-1/paypal-activity.csv` — synthesised PayPal CSV fixture
- [ ] `Modules/Chains/tests/fixtures/scenario-1/scenario-1.md` — fixture record documenting totals, counts, IBANs, per-variant deltas
- [ ] `Modules/Chains/tests/fixtures/scenario-1/scenario-1-overpaid.json` + `scenario-1-underpaid.json` — variant overlays
- [ ] `Modules/Chains/composer.json` — module manifest (mirrors `Modules/Transfers/composer.json`)
- [ ] BoundaryArchTest extensions: noFacadesInChains, noResolverWritesTransactions, stateOnlyViaMachine, Modules\\Chains\\Internal namespace rule
- [ ] PROJECT.md amendment (atomic with Phase 5 plans per D-101 / D-102): move Horizon + Redis from "What NOT to Use" to recommended; flip queue driver from `database` to `redis`; add Docker-for-Redis as carve-out
- [ ] README.md amendment: Redis setup section with loopback-bound `docker run` invocation; `php artisan horizon` runtime instructions
- [ ] `composer test` runs the new Chains module suite (autoload-dev update if not auto-discovered)
- [ ] Wave-0 findings document at `.planning/phases/05-.../05-WAVE-0-FINDINGS.md` reporting back: composer install actual versions, Docker version detected, `php artisan horizon:status` smoke result, JSON1 extension presence, whether `whereJsonContains` works on SQLite for evidence path

*(If nothing maps to a gap above, the planner has missed a sub-deliverable.)*

## Security Domain

Phase 5 touches user financial data (chain_links carry transaction IDs and IBANs in evidence JSON), introduces a new network service (Redis), and ships a new bounded module — all have ASVS implications. Security enforcement is treated as enabled (no `security_enforcement: false` flag in `.planning/config.json`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (no new auth surface; Phase 1 Fortify covers /horizon via the global auth middleware + LoopbackOnly) | n/a |
| V3 Session Management | no | n/a |
| V4 Access Control | yes | Every Chains Public service filters by `user_id` (FND-03 BelongsToUser). `ConfirmChainLink` / `RejectChainLink` defensively check `where('user_id', $user->id)->where('id', $id)` before mutating (same Phase 4 cross-user 404 pattern). `CrossUserChainLinkIsolationTest` enforces. `Horizon::auth()` callback enforces Fortify-authenticated user. |
| V5 Input Validation | yes | Chain resolution inputs come from already-imported `transactions` rows (validated at ingestion). The new user-facing surfaces are `/chains/review` and the chain drawer; their inputs are chain_link IDs validated against the user's owned rows. No raw file upload in Phase 5. |
| V6 Cryptography | yes (limited) | SHA-256 used for signature_hash (D-88) — built-in PHP `hash('sha256', ...)`. No new encryption. No secrets stored in chain_link.evidence. |
| V7 Error Handling & Logging | yes | Job failures logged via Horizon's failed-jobs storage. PII (transaction IBANs, merchant names) MUST NOT leak to INFO-level logs. Larastan strict-rules already blocks `dd()` / `Log::info()` of raw payload. |
| V8 Data Protection | yes | chain_links and card_statements live in the existing SQLite file (chmod 600). card_statement_credits same. Redis queue payloads contain chain_link IDs and user_id only — no PII in payload itself. Redis container loopback-bound (D-102) so payloads never leave the machine. |
| V9 Communications | yes (loopback only) | Redis bound to 127.0.0.1 only (FND-01 alignment). Pitfall 8 above. |
| V10 Malicious Code | no | No new external code-execution surface. |
| V11 Business Logic | yes | Auto-promotion threshold (D-87) is a business-logic invariant; counter is computed from existing chain_links state — no out-of-band counter table. Reject scope per-pair (D-89) is a business-logic boundary; Pitfall 5 documents the irreversibility caveat. |
| V12 Files and Resources | no (no new file upload paths in Phase 5) | n/a |
| V13 API and Web Service | no (no new HTTP API; /chains/review is a Livewire page) | n/a |
| V14 Configuration | yes | New `config/horizon.php` + `config/queue.php` + `.env` Redis vars + `bootstrap/providers.php` ChainsServiceProvider registration. All committed to git. No new secret values added (REDIS_PASSWORD=null intentionally; loopback-only). |

### Known Threat Patterns for Laravel 13 + SQLite + Livewire 4 + Pest + Redis + Horizon

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-user chain_link enumeration via `/chains/review` URL parameter tampering | I (Information disclosure) | Every query filters on `user_id`; ConfirmChainLink/RejectChainLink fetch with where(`user_id`) clause before mutating; CrossUserChainLinkIsolationTest enforces |
| Forge a malicious chain_link via Livewire request | T (Tampering) | Livewire write paths only call Public actions (ConfirmChainLink/RejectChainLink), never raw INSERTs. Public actions validate ownership before write. |
| Redis network exposure on hostile LAN | E (Elevation of privilege) | `-p 127.0.0.1:6379` binding (D-102 + Pitfall 8); REDIS_PASSWORD=null is acceptable only because loopback is enforced |
| Stale queue payload after composer downgrade | D (Denial of service) | Horizon's failed-job UI surfaces deserialisation errors; ResolveChainLinksJob takes only `userId: int` so even after class evolution the payload deserialises |
| SHA-256 signature_hash collision used to false-promote | T (Tampering) | SHA-256 collision-resistant; collision space is per-user (signature filtered by user_id) — practical attack surface zero |
| BoundaryArchTest weakness allowing facade reintroduction | T (Tampering) | Existing arch test in `tests/Contracts/BoundaryArchTest.php` already enforces `Illuminate\Support\Facades` is not used in `Modules`; extended with grep-based noResolverWritesTransactions per Pitfall 4 |
| Horizon dashboard exposed to non-authenticated user | E (Elevation) | `Horizon::auth()` callback requires Fortify-authenticated user (Phase 1 baseline) + LoopbackOnly middleware (Phase 1 baseline) |
| Worker SIGKILL leaves chain_links in inconsistent state | D | `ResolveChainLinksJob`'s individual chain_link inserts are isolated; failure mid-pass leaves partial chain_links that subsequent re-runs idempotently fill in. ChainResolverIdempotencyContractTest enforces. |
| User intentionally floods import to thrash resolver | D | `ShouldBeUniqueUntilProcessing` keyed on user_id collapses parallel dispatches; queue worker rate is bounded by the single-supervisor `processes: 1` config |

## Sources

### Primary (HIGH confidence)

- [Laravel 12 Horizon docs](https://laravel.com/docs/12.x/horizon) — minimum-viable install, supervisor config, auth callback, ShouldBeUniqueUntilProcessing contract, exact commands
- [Laravel 12 Queues docs](https://laravel.com/docs/12.x/queues#unique-jobs) — ShouldBeUnique vs ShouldBeUniqueUntilProcessing semantics, uniqueId/uniqueFor/uniqueVia API, supported cache drivers
- [Laravel 12 Redis docs](https://laravel.com/docs/12.x/redis) — REDIS_CLIENT=predis configuration for Predis-based stacks
- [packagist.org/packages/laravel/horizon](https://packagist.org/packages/laravel/horizon) — 5.46.0 (2026-04-20), PHP ^8.0, supports Laravel 9.21+ through 13
- [packagist.org/packages/predis/predis](https://packagist.org/packages/predis/predis) — 3.4.2 (2026-03-09), PHP ^7.2 || ^8.0, pure-PHP Redis client
- [hub.docker.com/_/redis](https://hub.docker.com/_/redis) — `redis:7-alpine` image, named-volume persistence at `/data`, `--save 60 1` snapshot semantics
- [Flux UI Modal component (fluxui.dev/components/modal)](https://fluxui.dev/components/modal) — `<flux:modal flyout>` API, position variants, dismissible / closable props, footer slot, escape + click-outside default behaviour
- [Livewire wire:poll docs](https://livewire.laravel.com/docs/wire-poll) — `wire:poll.2s` syntax, method invocation, background throttling, `.visible` modifier
- `.planning/research/ARCHITECTURE.md` (L382–L389, L403–L446, L511) — ChainLink state/confidence/evidence model, resolver-writes-chain_links-only invariant, async post-load shape, per-user job uniqueness, bulk-settle SUM verification, indexes
- `.planning/research/PITFALLS.md` — Pitfall 4 (ICS bulk-settlement, load-bearing for D-94 / D-95 / D-96 / D-97 / D-98), Pitfall 3 (PayPal funding-chain hints, informs D-106), Pitfall 9 (cross-source merchant/FX divergence, informs D-88), Pitfall 1 (floating-point arithmetic — already locked in Phase 1)
- `Modules/Transfers/Internal/Listeners/PairTransferCandidates.php` — raw `DatabaseManager` query builder pattern, symmetric-write idiom, `phpstan-strict-rules` `self::toInt()` coercion helper
- `Modules/Import/Public/Actions/ConfirmImport.php` — post-commit dispatch site pattern + outer DB transaction wrap
- `Modules/Ledger/Public/Services/ThisPeriodAtAGlanceQuery.php` — pattern for extending with new public method (e.g., `forByCurrency`, `nextIcsSettlement`)
- `Modules/Ledger/Public/Services/FingerprintComposer.php` (L67–L75) — merchant normalisation function reused for fuzzy matching
- `Modules/Ledger/Models/Transaction.php` — `Transaction::TYPES` enum + pair_transaction_id schema knowledge + casts shape
- `Modules/Ledger/Models/StatementSummary.php` — source rows for D-94 back-population
- `tests/Contracts/BoundaryArchTest.php` — existing arch invariant patterns (no-facade rule, grep-based `noPaypalApiRoute`)
- `composer.json` — current dep set, PHP ^8.5 baseline, Pest 4 / Larastan 3 / Pint 1 / pest-plugin-arch 4 / pest-plugin-snapshots 2

### Secondary (MEDIUM confidence — verified across multiple sources)

- [yellowduck.be: Unique jobs when using Laravel Horizon](https://www.yellowduck.be/posts/unique-jobs-when-using-laravel-horizon) — confirms uniqueVia + Cache::driver('redis') pattern for Horizon-based unique-job locks
- [SQLAlchemy mailing list on SQLite BEGIN IMMEDIATE](https://groups.google.com/g/sqlalchemy/c/RIBdLP_s6hk) — confirms BEGIN IMMEDIATE as the standard workaround for SELECT FOR UPDATE on SQLite
- [Medium: A Practical Guide to Row-Level Locking in Laravel Applications](https://medium.com/@hasnatiucse/a-practical-guide-to-row-level-locking-in-laravel-applications-b130225a39f4) — Laravel `lockForUpdate()` semantics and DB-driver portability caveats
- [PHP levenshtein() manual](https://www.php.net/manual/en/function.levenshtein.php) — built-in function signature, default cost parameters, complexity
- `.planning/phases/04-paypal-ingestion-transfer-detection/04-RESEARCH.md` — Pattern 5 (bounded-module provider registration), Pattern 6 (account-naming wizard), validation-architecture shape — Phase 5 mirrors

### Tertiary (LOW confidence — needs validation in Wave 0)

- [dataladder.com: Fuzzy Matching 101](https://dataladder.com/fuzzy-matching-101/) — fuzzy-matching algorithm category overview; informs the weighted-score approach in Example 2 but specific weights are discretion-locked
- Predis PHP 8.5 compatibility — caret-major constraint convention says `^8.0` covers 8.5; verification belongs in `composer require --dry-run` during Wave 0

## Metadata

**Confidence breakdown:**
- Standard stack — HIGH — laravel/horizon + predis/predis versions verified on Packagist (2026-04-20 / 2026-03-09 respectively); Flux flyout API confirmed against fluxui.dev; existing project deps verified against composer.json.
- Architecture patterns — HIGH — Patterns 1/2/3/4 mirror established Phase 4 idioms; Pattern 5 (signature counter) is novel but follows the same DI-only Eloquent-direct shape as existing Public actions.
- Pitfalls — HIGH — Pitfalls 1–8 grounded in either PITFALLS.md research, verified Laravel docs (SQLite locking semantics, ShouldBeUnique contract), or direct codebase reference (Pitfall 3, Pitfall 4 enforcement).
- Validation architecture — HIGH — Phase 5 inherits Phase 4's Pest + arch + snapshot stack; the test map enumerates ≥35 tests per phase requirement plus 7 cross-cutting invariants. No new test framework introduced.
- Security — MEDIUM — Loopback-bound Redis posture is correct but worth a Wave-0 manual verification (`netstat -an | grep 6379` shows 127.0.0.1 not 0.0.0.0). The Horizon::auth() default-block-non-auth posture relies on Phase 1's LoopbackOnly + Fortify being in place; verify integration during Wave 0.

**Research date:** 2026-05-16
**Valid until:** 2026-06-15 (30 days — stable Laravel + Horizon ecosystem; flag any 6.x Horizon major bump for re-research)

## RESEARCH COMPLETE
