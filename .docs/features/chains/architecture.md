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
  - `RejectChainLink` — moves a candidate to rejected. Rejection is
    strictly per-pair and neutral to the signature counter: it neither
    demotes existing same-signature confirmed rows nor blocks the
    auto-promotion threshold from accumulating across other
    same-signature confirmations. The only place `rejected` matters is
    `ChainLinkInsertHelper`'s pre-insert guard, which refuses to
    re-propose a candidate for an already-rejected pair — the user's
    rejection is final.
  - `DismissChainLinkHint` — dismisses a hint-shaped row whose `to`
    endpoint is NULL, by hard delete rather than the `state='rejected'`
    soft-delete Confirm/Reject use. Soft-delete is structurally
    impossible here — the schema trigger refuses any state-flip on a
    NULL-endpoint row, preserving the invariant that every non-hint
    chain_link has both endpoints set. Hard delete is safe because
    nothing FKs into `chain_links` from outside the table, and hints
    are inherently re-emittable: if the underlying conditions still
    hold, the upstream matcher produces an identical hint on the next
    import/chain pass, so an accidental dismiss just gets a fresh hint
    next time. Guards against being called on a concrete row (throws,
    since Confirm/Reject own that path instead).
- **DTOs/** — `ChainLinkRow`, `ChainLinkHintRow`, `ChainTree`,
  `ChainTreeNode`, `CardStatementForecastTile`, `StatementSettlement`,
  `NextSettlementDto`, `SeriesFunderLink`.
- **Exceptions/** — `ChainLinkRequiresConcretePartnerException` (the
  typed error `ConfirmChainLink` throws when a hint row cannot be
  promoted because the `to` endpoint is still NULL).
- **Services/** — `ChainLinkQuery` (review-queue + open-candidate
  count for the top-nav badge), `CardStatementQuery` (next-settlement
  - forecast-tile reads).

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
  inserts a `pending` row in `chain_resolution_runs` and runs
  `ResolveChainLinksJob` for the user via `dispatchSync()`, **not** the
  queue. Called from `ConfirmImport` AFTER the import's outer
  transaction commits (in-transaction dispatch would let the worker
  see stale state). It runs synchronously rather than queued because
  the resolvers match against encrypted `counterparty_iban`/
  `counterparty_name` columns, and the decryption KEK is only reachable
  through the live, unlocked Session on the calling HTTP/Livewire
  request — every call site is such a request, never a
  daemon/scheduler origin. Queuing would hand the job to the KEK-less
  `queue:work` daemon, which would decrypt to empty and silently
  resolve nothing. `dispatchSync()` keeps the KEK in-process and, as a
  side effect, bypasses `ShouldBeUniqueUntilProcessing` (that lock is
  only enforced by the queue's `PendingDispatch::shouldDispatch()`,
  which `dispatchSync()` never invokes) — a same-user double-click runs
  the resolver pass twice in sequence instead of collapsing into one,
  which is redundant but harmless since chain resolution is
  idempotent.
- `ResolveChainLinksJob` — for one user: flips the audit row to
  `running` with `started_at`, runs the three resolvers in order
  (`IcsSettlementResolver`, `PaypalFundingResolver`,
  `RetypeByAliasResolver`), flips the audit row to `complete` with
  `linked_count`. A `JobFailed` listener registered in
  `ChainsServiceProvider::boot()` flips it to `failed` with a
  truncated `last_error` on final-retry exhaustion.
- `CardStatementUpserter` (impl. `UpsertsCardStatements`) — promotes
  each ICS-kind `statement_summaries` row seen at import into a
  `card_statements` row via `insertOrIgnore` against the UNIQUE
  `(user_id, account_id, period_start, period_end)` constraint, so a
  re-run is a no-op and an existing row's `state` (moved on by
  `CardStatementStateMachine` since) is never reset back to `open`.
  `total_amount_minor` preserves `closing_balance_minor`'s sign
  verbatim (negative = amount owed); `open_balance_minor` is its
  absolute value. Rows missing either period boundary are skipped —
  the UNIQUE constraint requires both. `upsertForImportRun()` scopes to
  one import; `upsertForUser()` drops that scope for the healing-pass
  backfill (bounded by the user's lifetime ICS import count, not chain
  dispatch frequency).
- `ConfirmChainLink` — the user-side promotion path. On promotion it
  reads the row's `evidence.signature_hash`, counts confirmed rows
  sharing that signature, and (if ≥ 3) updates every remaining
  same-signature candidate to `confirmed` with `resolver='rule'`. The
  resolver discriminator surfaces in the UI: `auto` + 1.0 = "Deterministic",
  `auto` + <1.0 = "Confirmed (resolver-suggested)", `rule` =
  "Confirmed via learning loop". `/chains/review` also shows a proactive
  nudge — when a row's `confirmsRemaining === 1` (two prior same-signature
  confirmations already landed, this would be the third), the row renders
  "One more confirm and similar links auto-confirm."
- `CreateChainLinkFromHint` listens for `Receipts::ChainHintDetected`
  (raised by `RecordReceipt` after the canonical transaction is
  persisted). Listener runs in-line; the FK on
  `chain_links.from_transaction_id` binds cleanly because the
  transaction has already been written.

## CreateChainLinkFromHint — hint-type contract + safety

Two hint types are handled: `funded_by_card` → `chain_links.kind =
'funded_by_card_hint'` (an ICS receipt surfaced a card last-four; the
candidate waits for a resolver to bind it once the funder lands), and
`refund_of` → `'refund_of_hint'` (a refund-shaped receipt surfaced the
original-order reference id). No matcher currently emits `refund_of`
hints — the branch exists purely so the `ChainHintDetected` payload
sum-type stays total for both cases. An unknown `hintType` is silently
dropped, since the payload sum-type is closed and an unrecognized value
means an unintegrated producer upstream.

Cross-user safety mirrors `ChainLinkInsertHelper`: `chain_links.user_id`
is written from `$event->userId` only, never inferred from session
state, and `RecordReceipt` is the sole populator of that field.
Idempotency also mirrors the helper — a pre-INSERT existence check on
`(user_id, from_transaction_id, kind)` skips when any row already
exists in any state, so a manually-rejected row stays rejected across
repeated event dispatches.

## ChainLinkInsertHelper — the shared idempotent write path

`Internal/ChainLinkInsertHelper::insertIfNotExists()` is the single
`chain_links` INSERT site both `IcsSettlementResolver` and
`PaypalFundingResolver` call, so the `evidence` column is
`json_encode`d byte-identically across resolvers (one
`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` policy, not a
per-resolver choice).

It also folds in the pre-insert pair-uniqueness guard that keeps a
resolver re-run idempotent: if any `chain_links` row already exists
for the `(user_id, from_transaction_id, to_transaction_id, kind)`
tuple regardless of state, the insert is skipped. This is what makes
rejected-pair non-re-proposal work — a row the user manually rejected
stays rejected because the guard refuses to write a fresh candidate
for the same pair.

When `to_transaction_id` is NULL (the exceeded-tolerance
`ics_bulk_settle` candidate case the schema's NULL-endpoint trigger
allows), the existence query switches to `whereNull()` so the
pair-uniqueness check binds the NULL-endpoint variant exactly once per
`(user, from, kind)` tuple.

Kept under `Internal/` because no Public caller has a legitimate
reason to INSERT `chain_links` rows directly — `ConfirmChainLink` and
`RejectChainLink` only UPDATE existing rows.

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
  → three healing passes (see below), then:
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

### Healing passes — why they run before the resolvers

`ResolveChainLinksJob::handle()` runs three self-repair passes before
the two chain resolvers, because a wizard-order race or a per-import
card-statement upsert race can otherwise leave the ledger in a state
where the resolvers iterate empty sets:

1. `UpsertsCardStatements::upsertForUser` — promotes every ICS-kind
   `statement_summaries` row for the user into a `card_statements` row,
   independent of `import_run_id`. Catches up installs whose
   `ConfirmImport` path missed the per-import upsert (a rolled-back
   transaction, an older build, or a stale packaged bundle). Idempotent
   via the UNIQUE constraint inside `insertOrIgnore`.
2. `RetypeByAliasResolver::resolveForUser` — flips `expense`/`income`
   rows whose `counterparty_iban` resolves through
   `known_counterparty_ibans` to one of the user's own accounts.
   Idempotent and self-healing for late-added aliases.
3. `PairsTransferLegs::pairOrphansForUser` — pairs any transfer leg
   whose partner is now persisted but never went through the per-row
   `TransactionImported` listener (the only path the legacy
   preview-import flow had for closing `pair_transaction_id`). The
   retyped rows from pass 2 are the canonical case; older installs may
   also carry orphans predating this code.

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

## ChainLinkQuery — the bidirectional tree walk

`Public/Services/ChainLinkQuery::forTransaction()` builds the chain
drawer's tree by walking `chain_links` from the root transaction in
**both** directions — following both `from_transaction_id ->
to_transaction_id` and the reverse edge. An earlier, forward-only walk
was correct for the ICS bulk-settle pattern (the user typically opens
the drawer on the `from` side, the card-statement expense) but wrong
half the time for `paypal_funding`: clicking the ASN funding row (the
`to` side) found nothing, since no chain_link has
`from_transaction_id` equal to the ASN transaction. Walking both
directions means the drawer surfaces the full chain regardless of
which leg the user opened it on.

The walk is bounded at `MAX_DEPTH = 5` (the project's payment topology
is at most five levels: `recurring -> expense -> bulk-settle ->
ASN-transfer -> funding source`), with a visited-set guard against
accidental cycles. Only `confirmed`/`candidate` states are followed
(rejected legs are suppressed); `to_transaction_id IS NULL` legs
(exceeded-tolerance `ics_bulk_settle` candidates) are skipped in the
walker — those candidates still surface via `hintsForReview()` for the
user to resolve separately.

The confidence-tier mapping the tree and the review queue share:
`state='confirmed' AND resolver='auto' AND confidence=1.0` →
`Deterministic`; any other `confirmed` → `Confirmed`; `candidate` →
`Candidate`.

## ChainDrawer — the chain drill-down side-drawer

`Internal/Http/Livewire/ChainDrawer.php` mounts inside
`/transactions/{id}` and renders when the "View chain" button
dispatches a `chain-drawer:open` event carrying the transaction id.
Its Blade view is the project's first Flux flyout — it uses
`<flux:modal flyout position="right">` to render the chain tree as a
vertical waterfall (top = the clicked transaction, downward =
funders). Flux owns open/close/escape/click-outside; the component
owns the chain-tree data, the fan-out pagination cursor, and per-leg
collapse state.

The `open()` listener sets `transactionId` before dispatching
`modal-show` — the dispatch has to happen after the id is set, and
against the flyout's stable `chain-drawer` name, not a name derived
from the not-yet-loaded tree's root id (an earlier draft derived the
modal name from `tree.rootTransactionId`, which read `0` on first
click before the tree loaded, so the flyout appeared to do nothing).

`fanoutPage` starts at 0 (first 10 ICS charges visible); each
`showMoreFanout()` call increments it; pagination is forward-only.
Confirm/Reject chip actions delegate to the same `ConfirmChainLink` /
`RejectChainLink` Public actions `/chains/review` uses, so one action
class powers both surfaces.

### Fan-out children reconstruction

`ChainLinkQuery::forTransaction()` returns a flat BFS-ordered node
list with `children: []` always empty — the Public DTO never
populates it because the drawer is the only consumer that needs it.
`attachFanoutChildren()` rebuilds the children-by-parent map with one
bounded query against `chain_links` (kind `ics_bulk_settle`, state in
`confirmed`/`candidate`, scoped to the visited node ids) and re-emits
the node list with `ChainTreeNode.children` populated. The Public DTO
itself stays immutable — children are attached only at the UI layer.

A node becomes a fan-out **parent** only when it has 2 or more
outgoing `ics_bulk_settle` links; a single outgoing link is treated as
a normal chain hop (one settlement covering one expense isn't
interesting as a fan-out) and stays in the flat waterfall. Nodes that
are themselves fan-out children are dropped from the flat list — they
render nested inside their parent's fan-out container instead of as
duplicate top-level cards.
