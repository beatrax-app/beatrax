# `Chains` — architecture

The `Chains` module resolves the cross-source funding chains the app
exists to surface: which PayPal payment was funded by which ASN
withdrawal, and which ICS card purchases were settled by which ASN→ICS
bulk-iDEAL payment. It owns the `chain_links` ledger, the
`card_statements` lifecycle, the per-user `ResolveChainLinksJob` that
runs the resolver pass, and the review-queue + chain-drawer UI that
lets the user confirm or reject candidates.

## What this module is for

The user has three connected accounts (ASN, ICS, PayPal) that move
money between each other. Without chain resolution, a single
"Spotify €10" charge on a PayPal CSV is opaque — the user needs to
trace it back to the SEPA withdrawal on the ASN statement that
actually paid for it. Worse, the ICS bulk-iDEAL settlement on ASN
("ICS Cards €420") tells the user nothing about which of the eighteen
ICS card charges that month's settlement actually covered.

The module's job is to close those loops automatically where the
evidence is unambiguous (deterministic match) and to surface a
candidate for human confirmation where it isn't (the review queue).
The cross-cutting design is documented in the
[chain-resolution architecture topic](../../architecture/chain-resolution.md);
this page describes the module's surface.

What the module explicitly does NOT do:

- It never mutates `transactions` directly. The `transactions` table is
  the canonical store; chain rows reference it via FKs but the resolver
  is read-mostly over `transactions`. (The arch invariant
  `noResolverWritesTransactions` is the standing guard.)
- It never decides the same chain twice. Every resolver run is keyed
  on the deterministic `signature_hash` of the evidence; a re-run
  observes the existing chain_link row and skips it.
- It never auto-confirms a chain it isn't certain about. The
  `state` enum is `candidate` / `confirmed` / `rejected`; a candidate
  arrives in the review queue for the user to decide, unless a
  learning-loop threshold has been crossed (three same-signature
  confirmations → silent auto-promotion of remaining same-signature
  candidates with `resolver='rule'`).

## Module boundary

`Public/` exposes the cross-module contracts other modules consume:

- **Contracts/**
  - `DispatchesChainResolution` — the post-commit dispatch surface
    `ConfirmImport` calls. Bound to `BusChainResolutionDispatcher`.
  - `UpsertsCardStatements` — the post-commit `card_statements` upsert
    `ConfirmImport` calls before dispatching the resolver. Bound to
    `CardStatementUpserter`.
- **Actions/**
  - `ConfirmChainLink` — promotes a candidate to confirmed. Runs the
    auto-promotion learning loop after the write.
  - `RejectChainLink` — moves a candidate to rejected.
  - `DismissChainLinkHint` — dismisses a hint-shaped row whose `to`
    endpoint is NULL.
- **DTOs/** — `ChainLinkRow`, `ChainLinkHintRow`, `ChainTree`,
  `ChainTreeNode`, `CardStatementForecastTile`, `StatementSettlement`,
  `NextSettlementDto`, `SeriesFunderLink`.
- **Exceptions/** — `ChainLinkRequiresConcretePartnerException` (the
  typed error `ConfirmChainLink` throws when a hint row cannot be
  promoted because the `to` endpoint is still NULL).
- **Services/** — `ChainLinkQuery` (review-queue + open-candidate
  count for the top-nav badge), `CardStatementQuery` (next-settlement
  + forecast-tile reads).

`Internal/` houses the resolvers and the lifecycle owners:

- **Internal/CardStatementStateMachine** — the sole sanctioned mutator
  of `card_statements.state` (`open` → `partially_settled` →
  `settled` / `overpaid`). The arch invariant
  `noCardStatementStateWritesOutsideMachine` keeps it sole.
- **Internal/ChainLinkInsertHelper** — the single shared `chain_links`
  INSERT site that encodes evidence JSON consistently.
- **Internal/Resolvers/IcsSettlementResolver** — decomposes ASN→ICS
  bulk-iDEAL settlements into per-statement chain rows.
- **Internal/Resolvers/PaypalFundingResolver** — three arms:
  deterministic (Activity-Download memo with IBAN match), ASN-direct
  (absent funding leg in PayPal CSV), and fuzzy fallback.
- **Internal/Resolvers/RetypeByAliasResolver** — re-types ambiguous
  cross-account rows once the `known_counterparty_ibans` alias table
  resolves them.
- **Internal/Jobs/ResolveChainLinksJob** — the per-user queued pass,
  `ShouldBeUniqueUntilProcessing` keyed on user id.
- **Internal/Listeners/CreateChainLinkFromHint** — listens for the
  `ChainHintDetected` event from `Receipts` and INSERTs a candidate
  chain_link for each card-funding / refund hint a receipt matcher
  extracted.

## Key services + events

- `BusChainResolutionDispatcher` (impl. `DispatchesChainResolution`) —
  inserts a `pending` row in `chain_resolution_runs` and queues a
  `ResolveChainLinksJob` for the user. Called from `ConfirmImport`
  AFTER the import's outer transaction commits (in-transaction
  dispatch would let the worker see stale state).
- `ResolveChainLinksJob` — for one user: flips the audit row to
  `running` with `started_at`, runs the three resolvers in order
  (`IcsSettlementResolver`, `PaypalFundingResolver`,
  `RetypeByAliasResolver`), flips the audit row to `complete` with
  `linked_count`. A `JobFailed` listener registered in
  `ChainsServiceProvider::boot()` flips it to `failed` with a
  truncated `last_error` on final-retry exhaustion.
- `CardStatementUpserter` (impl. `UpsertsCardStatements`) — upserts a
  `card_statements` row per ICS statement period seen at import,
  delegating every state mutation to `CardStatementStateMachine` so
  the arch invariant holds.
- `ConfirmChainLink` — the user-side promotion path. On promotion it
  reads the row's `evidence.signature_hash`, counts confirmed rows
  sharing that signature, and (if ≥ 3) updates every remaining
  same-signature candidate to `confirmed` with `resolver='rule'`. The
  resolver discriminator surfaces in the UI: `auto` + 1.0 = "Deterministic",
  `auto` + <1.0 = "Confirmed (resolver-suggested)", `rule` =
  "Confirmed via learning loop".
- `CreateChainLinkFromHint` listens for `Receipts::ChainHintDetected`
  (raised by `RecordReceipt` after the canonical transaction is
  persisted). Listener runs in-line; the FK on
  `chain_links.from_transaction_id` binds cleanly because the
  transaction has already been written.

## Data flow

The post-import resolver pass:

```
ConfirmImport (Modules/Import) — outer TX commits
  → UpsertsCardStatements::upsert($importRunId, $user)
       (creates / refreshes card_statements rows per ICS PDF)
  → DispatchesChainResolution::dispatch($user)
       → INSERT chain_resolution_runs (pending, started_at=null)
       → Bus::dispatch(new ResolveChainLinksJob($user->id))

ResolveChainLinksJob (queue worker) — uniqueId() = user->id
  → flip audit row to running
  → IcsSettlementResolver::resolve($user)
       (for each ASN transfer_out matching an open card_statements,
        insert chain_links and let the state machine drive the row
        toward settled / overpaid / partially_settled)
  → PaypalFundingResolver::resolve($user)
       (three arms: deterministic, asn-direct, fuzzy fallback)
  → RetypeByAliasResolver::resolve($user)
       (re-type rows once known_counterparty_ibans resolves them)
  → flip audit row to complete with linked_count
```

The user-promotion path:

```
/chains/review → ChainReviewQueue Livewire SFC
  → ChainLinkQuery (read)
  → user clicks Confirm
       → ConfirmChainLink($chainLinkId, $user)
            → promote candidate → confirmed
            → count same-signature confirmed rows
            → if ≥ 3: bulk-promote remaining candidates
                       (resolver='rule', UI shows "via learning loop")
  → user clicks Reject
       → RejectChainLink($chainLinkId, $user)
            → state = rejected
```
